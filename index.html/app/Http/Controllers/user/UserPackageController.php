<?php

namespace App\Http\Controllers\user;

use App\Models\Package;
use App\Models\UserPackage;
use App\Models\UserCycle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UserPackageController extends Controller
{
    /**
     * Exibe os pacotes do usuário logado
     */
    public function index()
    {
        $userPackages = UserPackage::where('user_id', Auth::id())
            ->with(['package', 'currentCycle'])
            ->get();

        return view('user.packages.index', compact('userPackages'));
    }

    /**
     * Exibe detalhes de um pacote específico do usuário
     */
    public function show(UserPackage $userPackage)
    {
        // Verificar se o pacote pertence ao usuário logado
        if ($userPackage->user_id !== Auth::id()) {
            abort(403, 'Acesso não autorizado.');
        }

        $userPackage->load([
            'package',
            'currentCycle',
            'userCycles' => function ($query) {
                $query->orderBy('cycle_id');
            },
            'userCycles.cycle'
        ]);

        return view('user.packages.show', compact('userPackage'));
    }

    /**
     * Inscreve o usuário em um pacote
     */
    public function subscribe(Request $request, Package $package)
    {
        // Verificar se o pacote está ativo
        if ($package->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Este pacote não está disponível para inscrição.'
            ], 400);
        }

        // Verificar se o usuário já está inscrito neste pacote
        $existingSubscription = UserPackage::where('user_id', Auth::id())
            ->where('package_id', $package->id)
            ->whereIn('status', ['active', 'pending'])
            ->first();

        if ($existingSubscription) {
            return response()->json([
                'success' => false,
                'message' => 'Você já está inscrito neste pacote.'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Carregar o primeiro ciclo
            $firstCycle = $package->firstCycle();
            if (!$firstCycle) {
                throw new \Exception('Este pacote não possui ciclos definidos.');
            }

            // Criar a inscrição do usuário no pacote
            $userPackage = UserPackage::create([
                'user_id' => Auth::id(),
                'package_id' => $package->id,
                'start_date' => now(),
                'current_cycle_id' => $firstCycle->id,
                'current_sequence' => 1,
                'expected_end_date' => now()->addDays($package->total_duration),
                'total_invested' => 0,
                'total_earned' => 0,
                'status' => 'active',
            ]);

            // Criar o primeiro ciclo do usuário
            UserCycle::create([
                'user_package_id' => $userPackage->id,
                'cycle_id' => $firstCycle->id,
                'start_date' => now(),
                'expected_end_date' => now()->addDays($firstCycle->duration_days),
                'investment_amount' => $firstCycle->investment_amount,
                'status' => 'pending',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Inscrição realizada com sucesso! Agora você precisa realizar o investimento do primeiro ciclo.',
                'data' => $userPackage
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erro ao realizar inscrição: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancela a inscrição do usuário em um pacote
     */
    public function cancel(UserPackage $userPackage)
    {
        // Verificar se o pacote pertence ao usuário logado
        if ($userPackage->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso não autorizado.'
            ], 401);
        }

        // Verificar se o pacote pode ser cancelado
        if ($userPackage->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Este pacote não pode ser cancelado.'
            ], 400);
        }

        $userPackage->update(['status' => 'cancelled']);

        // Cancelar também o ciclo atual se estiver pendente
        $currentUserCycle = UserCycle::where('user_package_id', $userPackage->id)
            ->where('cycle_id', $userPackage->current_cycle_id)
            ->first();

        if ($currentUserCycle && $currentUserCycle->status === 'pending') {
            $currentUserCycle->update(['status' => 'cancelled']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Inscrição cancelada com sucesso.'
        ], 200);
    }
}
