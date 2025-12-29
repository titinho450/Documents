<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionStatus;
use App\Models\Settings;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserCheckin;
use Carbon\Carbon;
use Illuminate\Container\Attributes\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\Setting;

class CheckinController extends Controller
{
    /**
     * Exibir a página de checkin para o usuário
     */
    public function index()
    {
        $user = Auth::user();
        $settings = Setting::first();
        $checkinReward = $settings->checkin;

        $hasCheckedInToday = $user->hasCheckedInToday();
        $lastCheckin = $user->lastCheckin();
        $consecutiveDays = 0;

        // Calcular dias consecutivos de checkin
        if ($lastCheckin) {
            $lastDate = $lastCheckin->checkin_date;
            $yesterday = Carbon::yesterday()->toDateString();

            if ($lastDate == $yesterday) {
                // Buscar os últimos 30 checkins para calcular sequência
                $recentCheckins = $user->checkins()
                    ->orderBy('checkin_date', 'desc')
                    ->limit(30)
                    ->get();

                $consecutiveDays = 1; // Conta o último checkin
                $checkDate = Carbon::parse($lastDate);

                foreach ($recentCheckins->skip(1) as $checkin) {
                    $expectedPreviousDate = $checkDate->copy()->subDay()->toDateString();
                    if ($checkin->checkin_date->toDateString() == $expectedPreviousDate) {
                        $consecutiveDays++;
                        $checkDate = $checkin->checkin_date;
                    } else {
                        break;
                    }
                }
            }
        }

        // Calcular bônus baseado em dias consecutivos (opcional)
        $bonusMultiplier = 1;
        if ($consecutiveDays >= 7) {
            $bonusMultiplier = 1.5; // 50% extra para 7 dias consecutivos
        } elseif ($consecutiveDays >= 30) {
            $bonusMultiplier = 2; // 100% extra para 30 dias consecutivos
        }

        $potentialReward = $checkinReward * $bonusMultiplier;

        // Histórico de checkins do mês atual
        $currentMonthCheckins = $user->checkins()
            ->whereYear('checkin_date', now()->year)
            ->whereMonth('checkin_date', now()->month)
            ->orderBy('checkin_date', 'desc')
            ->get();

        return response()->json([
            'checkin_reward' => $checkinReward,
            'potential_reward' => $potentialReward,
            'has_checked_in_today' => $hasCheckedInToday,
            'last_checkin' => $lastCheckin,
            'consecutive_days' => $consecutiveDays,
            'current_month_checkins' => $currentMonthCheckins,
        ]);
    }

    /**
     * Processar o checkin diário do usuário
     */
    public function processCheckin(Request $request)
    {
        $user = Auth::user();

        // Verificar se o usuário já fez checkin hoje
        if ($user->hasCheckedInToday()) {
            return response()->json([
                'success' => false,
                'message' => 'Você já fez seu checkin hoje.',
                'error' => 'Você já fez seu checkin hoje.'
            ], 400);
        }

        /** @var \App\Models\Setting $settings */
        $settings = Setting::first();
        $checkinReward = $settings->checkin;

        // Verificar se o checkin está ativo no sistema
        if ($settings->checkin <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'O sistema de checkin está temporariamente desativado.',
                'error' => 'O sistema de checkin está temporariamente desativado.'
            ], 503);
        }

        // Calcular dias consecutivos
        $lastCheckin = $user->lastCheckin();
        $consecutiveDays = 0;
        $bonusMultiplier = 1;

        if ($lastCheckin) {
            $lastDate = $lastCheckin->checkin_date;
            $yesterday = Carbon::yesterday()->toDateString();

            if ($lastDate == $yesterday) {
                // Buscar os últimos 30 checkins para calcular sequência
                $recentCheckins = $user->checkins()
                    ->orderBy('checkin_date', 'desc')
                    ->limit(30)
                    ->get();

                $consecutiveDays = 1; // Conta o último checkin
                $checkDate = Carbon::parse($lastDate);

                foreach ($recentCheckins->skip(1) as $checkin) {
                    $expectedPreviousDate = $checkDate->copy()->subDay()->toDateString();
                    if ($checkin->checkin_date->toDateString() == $expectedPreviousDate) {
                        $consecutiveDays++;
                        $checkDate = $checkin->checkin_date;
                    } else {
                        break;
                    }
                }

                // Adicionar o dia atual à contagem
                $consecutiveDays++;

                // Aplicar bônus baseado em dias consecutivos (opcional)
                if ($consecutiveDays >= 7) {
                    $bonusMultiplier = 1.5; // 50% extra para 7 dias consecutivos
                } elseif ($consecutiveDays >= 30) {
                    $bonusMultiplier = 2; // 100% extra para 30 dias consecutivos
                }
            } else {
                // Resetar contagem se não foi ontem
                $consecutiveDays = 1;
            }
        } else {
            // Primeiro checkin
            $consecutiveDays = 1;
        }

        // Calcular a recompensa final
        $finalReward = (float) $settings->checkin;

        // Iniciar transação de banco de dados
        DB::beginTransaction();

        try {
            // Registrar o checkin
            $checkin = UserCheckin::create([
                'user_id' => $user->id,
                'checkin_date' => now()->toDateString(),
                'reward_amount' => $finalReward,
                'status' => TransactionStatus::COMPLETED
            ]);

            // Adicionar o valor ao saldo do usuário
            $user->addBalance($finalReward);

            // $orderId = 'checkin_' . $user->id . '_' . time();

            DB::commit();

            return response()->json([
                'success' => 'Checkin realizado com sucesso! Você recebeu ' . number_format($finalReward, 2) . ' de recompensa.',
                'reward_amount' => $finalReward,
                'consecutive_days' => $consecutiveDays,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Erro ao processar o checkin: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Exibir histórico de checkins do usuário
     */
    public function history()
    {
        $user = Auth::user();

        $checkins = $user->checkins()
            ->orderBy('checkin_date', 'desc')
            ->paginate(15);

        $totalEarned = $user->checkins()->sum('reward_amount');
        $consecutiveStreak = $this->calculateConsecutiveStreak($user);

        return response()->json([
            'checkins' => $checkins,
            'total_earned' => $totalEarned,
            'consecutive_streak' => $consecutiveStreak,
        ]);
    }

    /**
     * Calcular a sequência atual de checkins consecutivos
     */
    private function calculateConsecutiveStreak(User $user)
    {
        $lastCheckin = $user->lastCheckin();

        if (!$lastCheckin) {
            return 0;
        }

        $lastDate = $lastCheckin->checkin_date;
        $today = Carbon::today()->toDateString();
        $yesterday = Carbon::yesterday()->toDateString();

        // Verificar se o último checkin foi hoje ou ontem
        if ($lastDate->toDateString() != $today && $lastDate->toDateString() != $yesterday) {
            return 0; // A sequência foi quebrada
        }

        // Buscar os últimos 60 checkins para calcular sequência
        $recentCheckins = $user->checkins()
            ->orderBy('checkin_date', 'desc')
            ->limit(60)
            ->get();

        $consecutiveDays = 1; // Conta o último checkin
        $checkDate = Carbon::parse($lastDate);

        foreach ($recentCheckins->skip(1) as $checkin) {
            $expectedPreviousDate = $checkDate->copy()->subDay()->toDateString();
            if ($checkin->checkin_date->toDateString() == $expectedPreviousDate) {
                $consecutiveDays++;
                $checkDate = $checkin->checkin_date;
            } else {
                break;
            }
        }

        return $consecutiveDays;
    }

    /**
     * Admin: Configurações do sistema de checkin
     */
    public function adminSettings()
    {
        $settings = Settings::first();
        return view('admin.checkin-settings', compact('settings'));
    }

    /**
     * Admin: Atualizar configurações do sistema de checkin
     */
    public function updateSettings(Request $request)
    {
        $request->validate([
            'checkin' => 'required|numeric|min:0',
            'w_time_status' => 'required|in:active,inactive',
        ]);

        $settings = Settings::first();
        $settings->checkin = $request->checkin;
        $settings->w_time_status = $request->w_time_status;
        $settings->save();

        return redirect()->back()->with('success', 'Configurações de checkin atualizadas com sucesso!');
    }

    /**
     * Admin: Relatório de checkins
     */
    public function adminReport()
    {
        $todayCheckins = UserCheckin::whereDate('checkin_date', now()->toDateString())->count();
        $totalCheckins = UserCheckin::count();
        $totalRewards = UserCheckin::sum('reward_amount');

        $recentCheckins = UserCheckin::with('user')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $dailyStats = UserCheckin::selectRaw('DATE(checkin_date) as date, COUNT(*) as count, SUM(reward_amount) as total')
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->limit(30)
            ->get();

        return view('admin.checkin-report', compact(
            'todayCheckins',
            'totalCheckins',
            'totalRewards',
            'recentCheckins',
            'dailyStats'
        ));
    }
}
