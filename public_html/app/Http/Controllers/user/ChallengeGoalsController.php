<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ChallengeGoal;
use App\Models\UserChallengeGoal;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChallengeGoalsController extends Controller
{
    /**
     * Exibir as metas de desafio
     */
    public function show()
    {
        try {
            $challengeGoals = ChallengeGoal::active()->ordered()->paginate(10);

            return view('admin.goals.list', compact('challengeGoals'));
        } catch (\Exception $e) {
            Log::error('Erro ao obter usuário autenticado: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Erro interno do servidor']);
        }
    }

    /**
     * Criar novas metas de desafio
     */
    public function create()
    {
        try {

            return view('admin.goals.create');
        } catch (\Exception $e) {
            Log::error('Erro ao obter usuário autenticado: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Erro interno do servidor']);
        }
    }

    /**
     * Criar novas metas de desafio
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'required_investment' => 'required|numeric|min:0',
            'bonus_amount' => 'required|numeric|min:0',
            'bonus_type' => 'required|in:fixed,percentage',
        ]);

        try {
            ChallengeGoal::create($validated);

            return redirect()->route('admin.challenge-goals.index')->with('success', 'Meta de desafio criada com sucesso.');
        } catch (\Exception $e) {
            Log::error('Erro ao criar meta de desafio: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Erro interno do servidor: ' . $e->getMessage()]);
        }
    }


    /**
     * Exibir formulário de edição de uma meta de desafio
     */
    public function edit($id)
    {
        try {
            $challengeGoal = ChallengeGoal::findOrFail($id);
            return view('admin.goals.edit', compact('challengeGoal'));
        } catch (\Exception $e) {
            Log::error('Erro ao buscar meta de desafio para edição: ' . $e->getMessage());
            return redirect()->route('admin.challenge-goals.index')
                ->withErrors(['error' => 'Meta de desafio não encontrada.']);
        }
    }

    /**
     * Atualizar uma meta de desafio existente
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'required_investment' => 'required|numeric|min:0',
            'bonus_amount' => 'required|numeric|min:0',
            'bonus_type' => 'required|in:fixed,percentage',
            'order' => 'required|integer|min:1',
            'is_active' => 'boolean',
        ]);

        try {
            $challengeGoal = ChallengeGoal::findOrFail($id);

            // Se is_active não foi marcado, define como false
            $validated['is_active'] = $request->has('is_active');

            $challengeGoal->update($validated);

            return redirect()->route('admin.challenge-goals.index')
                ->with('success', 'Meta de desafio atualizada com sucesso.');
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar meta de desafio: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Erro interno do servidor: ' . $e->getMessage()]);
        }
    }

    /**
     * Excluir uma meta de desafio
     */
    public function destroy(Request $request, ChallengeGoal $challenge)
    {

        try {
            $challenge->delete();

            return redirect()->route('admin.challenge-goals.index')->with('success', 'Meta de desafio excluído com sucesso.');
        } catch (\Exception $e) {
            Log::error('Erro ao excluir meta de desafio: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Erro interno do servidor: ' . $e->getMessage()]);
        }
    }

    /**
     * Excluir uma meta de desafio
     */
    public function userDestroy(Request $request, UserChallengeGoal $challenge)
    {

        try {
            $challenge->delete();

            return redirect()->route('admin.challenge-goals.index')->with('success', 'Meta para usuário excluída.');
        } catch (\Exception $e) {
            Log::error('Erro ao excluir meta de desafio para usuário: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Erro interno do servidor: ' . $e->getMessage()]);
        }
    }

    /**
     * Exibir as metas de desafio
     */
    public function usersShow()
    {
        try {
            $userChallengeGoals = UserChallengeGoal::paginate(10);

            return view('admin.goals.users', compact('userChallengeGoals'));
        } catch (\Exception $e) {
            Log::error('Erro ao obter usuário autenticado: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Erro interno do servidor: ' . $e->getMessage()]);
        }
    }

    /**
     * Obter todas as metas de desafio do usuário
     */
    public function index()
    {
        try {
            $user = Auth::user();

            // Buscar todas as metas ativas
            $challengeGoals = ChallengeGoal::active()->ordered()->get();

            // Buscar progresso do usuário para cada meta
            $userChallenges = UserChallengeGoal::where('user_id', $user->id)
                ->with('challengeGoal')
                ->get()
                ->keyBy('challenge_goal_id');

            $response = $challengeGoals->map(function ($goal) use ($userChallenges) {
                $userChallenge = $userChallenges->get($goal->id);

                return [
                    'id' => $goal->id,
                    'title' => $goal->title,
                    'description' => $goal->description,
                    'required_investment' => $goal->required_investment,
                    'bonus_amount' => $goal->bonus_amount,
                    'bonus_type' => $goal->bonus_type,
                    'current_investment' => $userChallenge ? $userChallenge->current_investment : 0,
                    'progress_percentage' => $userChallenge ? $userChallenge->progress_percentage : 0,
                    'remaining_amount' => $userChallenge ? $userChallenge->remaining_amount : $goal->required_investment,
                    'is_completed' => $userChallenge ? $userChallenge->is_completed : false,
                    'completed_at' => $userChallenge ? $userChallenge->completed_at : null,
                    'bonus_claimed' => $userChallenge ? $userChallenge->bonus_claimed : false,
                    'can_claim_bonus' => $userChallenge ? ($userChallenge->is_completed && !$userChallenge->bonus_claimed) : false,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $response
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar metas de desafio: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Reivindicar bônus de uma meta completada
     */
    public function claimBonus(Request $request)
    {
        $validated = $request->validate([
            'challenge_goal_id' => 'required|integer|exists:challenge_goals,id'
        ]);

        DB::beginTransaction();

        try {
            $user = Auth::user();
            $challengeGoalId = $validated['challenge_goal_id'];

            // Buscar o desafio do usuário
            $userChallenge = UserChallengeGoal::where('user_id', $user->id)
                ->where('challenge_goal_id', $challengeGoalId)
                ->with('challengeGoal')
                ->first();

            if (!$userChallenge) {
                return response()->json([
                    'success' => false,
                    'message' => 'Desafio não encontrado'
                ], 404);
            }

            // Verificar se o desafio foi completado
            if (!$userChallenge->is_completed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Desafio não foi completado ainda'
                ], 400);
            }

            // Verificar se o bônus já foi reivindicado
            if ($userChallenge->bonus_claimed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bônus já foi reivindicado'
                ], 400);
            }

            // Calcular o valor do bônus
            $bonusAmount = $userChallenge->challengeGoal->bonus_amount;
            if ($userChallenge->challengeGoal->bonus_type === 'percentage') {
                $bonusAmount = ($userChallenge->current_investment * $bonusAmount) / 100;
            }

            // Adicionar bônus ao saldo do usuário
            User::where('id', $user->id)->increment('balance', $bonusAmount);

            // Marcar bônus como reivindicado
            $userChallenge->update([
                'bonus_claimed' => true,
                'bonus_claimed_at' => now()
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bônus reivindicado com sucesso',
                'bonus_amount' => $bonusAmount
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao reivindicar bônus: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Atualizar progresso das metas do usuário
     * Esta função deve ser chamada após cada compra
     */
    public function updateProgress($userId, $investmentAmount)
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
