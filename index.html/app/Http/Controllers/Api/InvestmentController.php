<?php

namespace App\Http\Controllers\api;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\InvestmentPackage;
use App\Models\Transaction;
use App\Models\UserInvestment;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class InvestmentController extends Controller
{
    public function __construct(private TransactionService $transactionService) {}

    public function store(Request $request)
    {
        try {
            // 1. Rate Limiting - Previne spam de requisições
            $rateLimitKey = 'investment_purchase_' . Auth::id();
            if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
                $seconds = RateLimiter::availableIn($rateLimitKey);
                return response()->json([
                    'message' => 'Muitas tentativas. Tente novamente em ' . $seconds . ' segundos.',
                    'retry_after' => $seconds
                ], 429);
            }

            // 2. Validação robusta com sanitização
            $validatedData = $request->validate([
                'package_id' => 'required|integer|exists:investment_packages,id',
                'amount' => 'required|numeric|min:10|max:1000000|regex:/^\d+(\.\d{1,2})?$/',
                'transaction_id' => 'required|string|max:255|regex:/^[a-zA-Z0-9\-_]+$/',
            ]);

            // 3. Sanitização adicional
            $packageId = (int) $validatedData['package_id'];
            $amount = round((float) $validatedData['amount'], 2);
            $transactionId = preg_replace('/[^a-zA-Z0-9\-_]/', '', $validatedData['transaction_id']);

            // 4. Verificação de duplicação de transação
            $existingTransaction = Transaction::where('order_id', $transactionId)
                ->where('user_id', Auth::id())
                ->first();

            if ($existingTransaction) {
                return response()->json([
                    'message' => 'Transação já processada'
                ], 400);
            }

            // 5. Verificação de tentativas simultâneas (previne race conditions)
            $lockKey = 'investment_lock_' . Auth::id();
            if (Cache::has($lockKey)) {
                return response()->json([
                    'message' => 'Processamento em andamento. Aguarde.'
                ], 429);
            }

            // 6. Usar transação de banco de dados
            DB::beginTransaction();

            try {
                // 7. Lock temporário para prevenir requisições simultâneas
                Cache::put($lockKey, true, 30); // 30 segundos

                // 8. Verificações de negócio mais rigorosas
                $package = InvestmentPackage::lockForUpdate()->findOrFail($packageId);

                // Verificar se o pacote está ativo
                if (!$package->is_active) {
                    throw new \Exception('Pacote de investimento não está disponível');
                }

                // Verificar limites do pacote
                if ($amount < $package->minimum_amount) {
                    throw new \Exception('Valor fora dos limites permitidos para este pacote');
                }

                // 9. Verificação de saldo com lock
                $user = Auth::user();
                $userWithLock = User::lockForUpdate()->findOrFail($user->id);

                if ($userWithLock->balance < $amount) {
                    throw new \Exception('Saldo insuficiente');
                }

                // 10. Verificar se usuário não excede limite de investimentos
                $userInvestmentCount = UserInvestment::where('user_id', Auth::id())
                    ->whereDate('created_at', now()->toDateString())
                    ->count();

                if ($userInvestmentCount >= 10) { // Limite diário
                    throw new \Exception('Limite diário de investimentos excedido');
                }

                // 11. Verificar valor total investido no dia
                $dailyInvestmentTotal = UserInvestment::where('user_id', Auth::id())
                    ->whereDate('created_at', now()->toDateString())
                    ->sum('amount');

                if (($dailyInvestmentTotal + $amount) > 50000) { // Limite diário de valor
                    throw new \Exception('Limite diário de valor de investimento excedido');
                }

                // 12. Criar o investimento
                $investment = UserInvestment::create([
                    'user_id' => Auth::id(),
                    'investment_package_id' => $package->id,
                    'amount' => $amount,
                    'start_date' => now(),
                    'end_date' => now()->addDays($package->duration_days),
                    'status' => 'active'
                ]);

                // 13. Debitar saldo do usuário
                $userWithLock->decrement('balance', $amount);

                // 14. Registrar transação
                Transaction::create([
                    'user_id' => Auth::id(),
                    'amount' => $amount,
                    'order_id' => $transactionId,
                    'payment_id' => $this->transactionService->generateUUid(),
                    'status' => TransactionStatus::COMPLETED,
                    'description' => 'Investment in package: ' . $package->id,
                    'type' => 'purchase',
                    'currency' => 'USD',
                    'external_data' => json_encode([
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ]),
                ]);

                // 15. Log da operação bem-sucedida
                Log::info('Investment created successfully', [
                    'user_id' => Auth::id(),
                    'investment_id' => $investment->id,
                    'package_id' => $package->id,
                    'amount' => $amount,
                    'transaction_id' => $transactionId,
                    'external_data' => json_encode([
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ]),
                ]);

                DB::commit();
                Cache::forget($lockKey);

                // 16. Incrementar rate limit apenas em caso de sucesso
                RateLimiter::hit($rateLimitKey, 300); // 5 minutos

                return response()->json([
                    'message' => 'Investimento criado com sucesso',
                    'investment' => $investment->load('package'),
                    'new_balance' => $userWithLock->fresh()->balance
                ], 201);
            } catch (\Exception $e) {
                DB::rollback();
                Cache::forget($lockKey);
                throw $e;
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            // 17. Log de tentativas de validação falhadas
            Log::warning('Investment validation failed', [
                'user_id' => Auth::id(),
                'errors' => $e->errors(),
                'input' => $request->except(['password', 'token']),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            // 18. Log detalhado de erros
            Log::error('Error creating investment', [
                'user_id' => Auth::id(),
                'package_id' => $request->package_id ?? null,
                'amount' => $request->amount ?? null,
                'transaction_id' => $request->transaction_id ?? null,
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'trace' => $e->getTraceAsString()
            ]);

            // 19. Incrementar contador de falhas para detecção de ataques
            $failureKey = 'investment_failures_' . Auth::id();
            $failures = Cache::increment($failureKey, 1);
            Cache::put($failureKey, $failures, 3600); // 1 hora

            if ($failures > 20) {
                Log::alert('Possible attack detected - too many investment failures', [
                    'user_id' => Auth::id(),
                    'failures' => $failures,
                    'ip' => $request->ip()
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor. Tente novamente mais tarde.'
            ], 500);
        }
    }

    public function wihdrawInvestment(Request $request, $id)
    {
        $investment = UserInvestment::findOrFail($id);

        if ($investment->user_id !== Auth::id()) {
            return response()->json(['message' => 'Você não tem permissão para retirar este investimento'], 403);
        }

        if (!$investment->active) {
            return response()->json(['message' => 'Este investimento já foi retirado'], 400);
        }

        $minWithdrawalDate = $investment->start_date->addDays($package->min_withdrawal_days);

        if (now()->lt($minWithdrawalDate)) {
            return response()->json(['message' => 'Você só pode sacar após ' . $minWithdrawalDate->format('d/m/Y')], 403);
        }

        $completed = now()->gte($investment->end_date);

        $bonus = $completed ? 1.0 : 0.7; // 70% dos rendimentos se sacar antes do fim
        $finalPayout = $investment->total_earned * $bonus;

        $investment->user->increment('balance', $finalPayout);

        $investment->end_date = now();
        $investment->active = false;
        $investment->save();

        return response()->json([
            'success' => true,
            'message' => 'Investimento retirado com sucesso',
            'data' => $transaction
        ]);
    }

    public function list()
    {
        $investments = InvestmentPackage::orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'message' => 'Investment list retrieved successfully',
            'data' => $investments
        ], 200);
    }
}
