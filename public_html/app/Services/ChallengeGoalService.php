<?php

namespace App\Services;

use App\Models\UserChallengeGoal;
use App\Models\ChallengeGoal;
use Illuminate\Support\Facades\Log;

class ChallengeGoalService
{
    /**
     * Atualizar progresso das metas do usuário
     * Esta função deve ser chamada após cada compra
     */
    public function updateProgress(int $userId, float $investmentAmount)
    {
        try {
            $challengeGoals = ChallengeGoal::active()->get();

            foreach ($challengeGoals as $goal) {
                $userChallenge = UserChallengeGoal::firstOrCreate(
                    [
                        'user_id' => $userId,
                        'challenge_goal_id' => $goal->id
                    ],
                    [
                        'current_investment' => 0,
                        'is_completed' => false,
                        'bonus_claimed' => false
                    ]
                );

                // Atualizar o investimento atual
                $userChallenge->increment('current_investment', $investmentAmount);

                // Verificar se a meta foi completada
                if (!$userChallenge->is_completed && $userChallenge->current_investment >= $goal->required_investment) {
                    $userChallenge->update([
                        'is_completed' => true,
                        'completed_at' => now()
                    ]);

                    Log::info("Meta de desafio completada - Usuário: {$userId}, Meta: {$goal->title}");
                }
            }
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar progresso das metas: ' . $e->getMessage());
        }
    }
}
