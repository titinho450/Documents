<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionTypes;
use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\User;
use App\Models\UserLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ReferralController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $token = $user->createToken('auth_token')->plainTextToken;
        return view('app.main.team.manager', compact('token'));
    }

    /**
     * Obter lista de usuários indicados
     */
    public function getTest()
    {
        $user = User::first();

        $referrals = User::where('ref_by', $user->ref_id)
            ->select('id', 'name', 'realname', 'email', 'username', 'created_at', 'active_member', 'investor')
            ->withCount(['investments' => function ($query) {
                $query->where('status', 'completed');
            }])
            ->withSum(['investments' => function ($query) {
                $query->where('status', 'completed');
            }], 'amount')
            ->withSum('commissions', 'amount')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'referrals' => $referrals,
                'total_count' => $referrals->count(),
                'active_count' => $referrals->where('active_member', 1)->count(),
                'investor_count' => $referrals->where('investor', 1)->count(),
            ]
        ]);
    }

    /**
     * Busca referidos recursivamente até um nível máximo especificado
     * 
     * @param int $userId ID do usuário para buscar os referidos
     * @param string $refIdField Campo que contém o código de referência
     * @param string $refByField Campo que indica quem indicou o usuário
     * @param int $currentLevel Nível atual na recursão
     * @param int $maxLevel Nível máximo de profundidade
     * @param int $authUserId ID do usuário autenticado (para cálculo de comissões)
     * @return \Illuminate\Support\Collection
     */
    public function getReferralsRecursive($userId, $refIdField, $refByField, $currentLevel = 1, $maxLevel = 3, $authUserId = null)
    {
        // Se atingiu o nível máximo, retorna uma coleção vazia
        if ($currentLevel > $maxLevel) {
            return collect();
        }

        // Busca referidos do nível atual
        $referrals = User::where($refByField, $userId)
            ->select('id', 'name', 'realname', 'email', 'username', 'created_at', 'active_member', 'investor', $refIdField)
            ->withCount(['investments' => function ($query) {
                $query->where('status', 'completed');
            }])
            ->withSum(['investments' => function ($query) {
                $query->where('status', 'completed');
            }], 'amount')
            ->orderBy('created_at', 'desc')
            ->get();

        // Adiciona o nível e calcula comissões para cada referido
        /** @var \App\Models\User $referral */
        foreach ($referrals as $referral) {
            $referral->level = $currentLevel;

            if ($authUserId) {
                $comissionSumAmount = $referral->transactions()->where('type', TransactionTypes::COMISSION)->sum('amount');
                $referral->commissions_sum_amount = $comissionSumAmount;

                $referral->investments_sum_amount = (float) $referral->purchases()->sum('amount');
            }
        }

        // Inicializa uma coleção para todos os referidos (incluindo níveis mais profundos)
        $allReferrals = $referrals;

        // Para cada referido no nível atual, busca seus referidos no próximo nível
        foreach ($referrals as $referral) {
            $nextLevelReferrals = $this->getReferralsRecursive(
                $referral->{$refIdField},
                $refIdField,
                $refByField,
                $currentLevel + 1,
                $maxLevel,
                $authUserId
            );

            // Combina com a coleção principal
            $allReferrals = $allReferrals->concat($nextLevelReferrals);
        }

        return $allReferrals;
    }

    /**
     * Obter lista de usuários indicados
     */
    public function getUserReferrals()
    {
        $user = Auth::user();

        // Busca todos os referidos até o nível 3
        $allReferrals = $this->getReferralsRecursive($user->ref_id, 'ref_id', 'ref_by', 1, 5, $user->id);

        // Agrupamento por nível para estatísticas
        $referralsByLevel = $allReferrals->groupBy('level');

        return response()->json([
            'success' => true,
            'data' => [
                'referrals' => $allReferrals,
                'total_count' => $allReferrals->count(),
                'active_count' => $allReferrals->where('active_member', 1)->count(),
                'investor_count' => $allReferrals->where('investor', 1)->count(),
                'level1_count' => $referralsByLevel->get(1, collect())->count(),
                'level2_count' => $referralsByLevel->get(2, collect())->count(),
                'level3_count' => $referralsByLevel->get(3, collect())->count(),
            ]
        ]);
    }

    /**
     * Obter estatísticas de comissões
     */
    public function getCommissionStats()
    {
        $user = Auth::user();

        // Busca comissões agrupadas por mês
        $commissions = $user->commissions()
            ->where('reason', 'commission')
            ->whereNotNull('amount') // Garante que amount não seja nulo
            ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, SUM(amount) as total')
            ->groupBy('month')
            ->orderBy('month', 'desc')
            ->limit(12)
            ->get();

        // Calcula o total de todas as comissões
        $totalCommission = $user->commissions()
            ->where('reason', 'commission')
            ->sum('amount');

        // Calcula comissões pendentes
        $pendingCommission = $user->commissions()
            ->where('reason', 'commission')
            ->where('status', 'pending')
            ->sum('amount');

        // Calcula comissões aprovadas/pagas
        $paidCommission = $user->commissions()
            ->where('reason', 'commission')
            ->where('status', 'approved')
            ->sum('amount');

        return response()->json([
            'success' => true,
            'data' => [
                'total_commission' => $totalCommission,
                'pending_commission' => $pendingCommission,
                'paid_commission' => $paidCommission,
                'monthly_data' => $commissions,
            ]
        ]);
    }

    /**
     * Gerar novo link de referência
     */
    public function generateReferralLink()
    {
        $user = Auth::user();

        if (empty($user->ref_id)) {
            $ref_id = strtoupper(substr(md5(time() . $user->id), 0, 8));

            $user->ref_id = $ref_id;
            $user->save();
        }

        $referralLink = config('app.url') . '/signup?ref=' . $user->ref_id;

        return response()->json([
            'success' => true,
            'data' => [
                'ref_id' => $user->ref_id,
                'referral_link' => $referralLink
            ]
        ]);
    }
}
