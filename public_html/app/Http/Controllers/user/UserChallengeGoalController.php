<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\UserChallengeGoal;

class UserChallengeGoalController extends Controller
{


    /**
     * Show the form for editing the specified resource.
     */
    public function edit(UserChallengeGoal $userChallengeGoal)
    {
        // Carrega os relacionamentos necessários
        $userChallengeGoal->load(['user', 'challengeGoal']);

        return view('admin.goals.user.edit', compact('userChallengeGoal'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, UserChallengeGoal $userChallengeGoal)
    {
        $request->validate([
            'current_investment' => [
                'required',
                'numeric',
                'min:0',
                'max:999999999.99'
            ],
            'is_completed' => 'nullable|boolean',
            'completed_at' => 'nullable|date',
            'bonus_claimed' => 'nullable|boolean',
            'bonus_claimed_at' => 'nullable|date',
        ], [
            'current_investment.required' => 'O campo investimento atual é obrigatório.',
            'current_investment.numeric' => 'O investimento atual deve ser um número válido.',
            'current_investment.min' => 'O investimento atual não pode ser negativo.',
            'current_investment.max' => 'O investimento atual excede o valor máximo permitido.',
            'completed_at.date' => 'A data de conclusão deve ser uma data válida.',
            'bonus_claimed_at.date' => 'A data do resgate do bônus deve ser uma data válida.',
        ]);

        DB::beginTransaction();

        try {
            // Dados para atualização
            $updateData = [
                'current_investment' => $request->current_investment,
                'is_completed' => $request->boolean('is_completed'),
                'bonus_claimed' => $request->boolean('bonus_claimed'),
            ];

            // Lógica para completed_at
            if ($request->boolean('is_completed')) {
                // Se está marcado como concluído
                if ($request->filled('completed_at')) {
                    $updateData['completed_at'] = $request->completed_at;
                } elseif (!$userChallengeGoal->completed_at) {
                    // Se não tem data de conclusão, define como agora
                    $updateData['completed_at'] = now();
                }
            } else {
                // Se não está concluído, remove a data de conclusão
                $updateData['completed_at'] = null;
                // Se não está concluído, também não pode ter bônus resgatado
                $updateData['bonus_claimed'] = false;
                $updateData['bonus_claimed_at'] = null;
            }

            // Lógica para bonus_claimed_at
            if ($request->boolean('bonus_claimed') && $request->boolean('is_completed')) {
                if ($request->filled('bonus_claimed_at')) {
                    $updateData['bonus_claimed_at'] = $request->bonus_claimed_at;
                } elseif (!$userChallengeGoal->bonus_claimed_at) {
                    // Se não tem data de resgate, define como agora
                    $updateData['bonus_claimed_at'] = now();
                }
            } else {
                // Se bônus não foi resgatado, remove a data
                $updateData['bonus_claimed_at'] = null;
            }

            // Verifica se o investimento atual atingiu ou superou a meta
            $requiredInvestment = $userChallengeGoal->challengeGoal->required_investment;
            if ($request->current_investment >= $requiredInvestment && !$request->boolean('is_completed')) {
                // Auto-marca como concluído se atingiu a meta
                $updateData['is_completed'] = true;
                if (!$request->filled('completed_at')) {
                    $updateData['completed_at'] = now();
                }
            }

            // Atualiza o registro
            $userChallengeGoal->update($updateData);

            DB::commit();

            return redirect()
                ->route('admin.user-challenge-goals.index')
                ->with('success', 'Progresso do usuário atualizado com sucesso!');
        } catch (\Exception $e) {
            DB::rollback();

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Erro ao atualizar o progresso: ' . $e->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(UserChallengeGoal $userChallengeGoal)
    {
        try {
            $userName = $userChallengeGoal->user->name ?? 'Usuário';
            $challengeTitle = $userChallengeGoal->challengeGoal->title ?? 'Challenge';

            $userChallengeGoal->delete();

            return redirect()
                ->route('admin.user-challenge-goals.index')
                ->with('success', "Progresso de {$userName} no challenge '{$challengeTitle}' foi excluído com sucesso!");
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Erro ao excluir o progresso: ' . $e->getMessage());
        }
    }
}
