<?php

namespace App\Services\NowPayments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Transaction;
use Exception;
use Illuminate\Http\JsonResponse;

class NowPaymentsService
{
    private string $apiKey;
    private string $baseUrl;
    private string $ipnSecret;

    public function __construct()
    {
        $this->apiKey = config('nowpayments.api_key');
        $this->baseUrl = config('nowpayments.base_url', 'https://api.nowpayments.io/v1');
        $this->ipnSecret = config('nowpayments.ipn_secret');
    }

    /**
     * Verifica o status da API
     */
    public function getApiStatus(): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
            ])->get($this->baseUrl . '/status');

            return [
                'success' => $response->successful(),
                'data' => $response->json()
            ];
        } catch (Exception $e) {
            Log::error('NowPayments API Status Error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Obtém moedas disponíveis
     */
    public function getAvailableCurrencies(): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
            ])->get($this->baseUrl . '/currencies');

            return [
                'success' => $response->successful(),
                'data' => $response->json()
            ];
        } catch (Exception $e) {
            Log::error('NowPayments Get Currencies Error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Cria um depósito USDT
     */
    public function createDeposit(User $user, float $amount, string $orderId = null): array
    {
        try {
            $orderId = $orderId ?: 'deposit_' . $user->id . '_' . time();

            $payload = [
                'price_amount' => $amount,
                'price_currency' => 'usddtrc20',
                'pay_currency' => 'usddtrc20',
                'order_id' => $orderId,
                'order_description' => "Depósito USDT - Usuário {$user->id}",
                'ipn_callback_url' => route('now_payment.ipn'),
                "is_fixed_rate" => true,
                "is_fee_paid_by_user" => false
            ];

            Log::channel('nowpay')->info('Payload deposito: ' . json_encode($payload, JSON_PRETTY_PRINT));

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/payment', $payload);

            if ($response->successful()) {
                $paymentData = $response->json();

                // Salva a transação no banco
                $transaction = Transaction::create([
                    'user_id' => $user->id,
                    'type' => 'deposit',
                    'amount' => $amount,
                    'currency' => 'USDT',
                    'status' => 'pending',
                    'payment_id' => $paymentData['payment_id'],
                    'order_id' => $orderId,
                    'payment_address' => $paymentData['pay_address'] ?? null,
                    'external_data' => json_encode($paymentData),
                ]);

                return [
                    'success' => true,
                    'data' => $paymentData,
                    'transaction' => $transaction
                ];
            }

            Log::channel('nowpay')->info("Resposta Gate: " . json_encode($response->json(), JSON_PRETTY_PRINT));

            return [
                'success' => false,
                'error' => $response->json()['message'] ?? 'Erro ao criar depósito'
            ];
        } catch (Exception $e) {
            Log::channel('nowpay')->error('NowPayments Create Deposit Error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Verifica o status de um pagamento
     */
    public function getPaymentStatus(string $paymentId): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
            ])->get($this->baseUrl . "/payment/{$paymentId}");

            return [
                'success' => $response->successful(),
                'data' => $response->json()
            ];
        } catch (Exception $e) {
            Log::error('NowPayments Get Payment Status Error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function createBearerToken()
    {
        $email = env('NOWPAYMENTS_EMAIL');
        $password = env('NOWPAYMENTS_PASSWORD');

        $payload = [
            'email' => $email,
            'password' => $password
        ];

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/auth', $payload);

        if ($response->successful()) {
            $payoutData = $response->json();

            return $payoutData['token'];
        }

        return [
            'success' => false,
            'error' => $response->json()['message'] ?? 'Erro ao criar saque'
        ];
    }

    /**
     * Processa saque USDT
     */
    public function createPayout(User $user, float $amount, string $address): array
    {
        try {

            $payload = [
                'withdrawals' => [
                    [
                        'address' => $address,
                        'currency' => 'usdt',
                        'amount' => $amount,
                        'ipn_callback_url' => route('now_payment.ipn'),
                    ]
                ]
            ];

            $token = $this->createBearerToken();

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ])->post($this->baseUrl . '/payout', $payload);

            if ($response->successful()) {
                $payoutData = $response->json();

                // Congela o saldo do usuário
                $user->decrement('usdt_balance', $amount);
                $user->increment('usdt_frozen', $amount);

                // Salva a transação no banco
                $transaction = Transaction::create([
                    'user_id' => $user->id,
                    'type' => 'withdrawal',
                    'amount' => $amount,
                    'currency' => 'USDT',
                    'status' => 'pending',
                    'withdrawal_address' => $address,
                    'batch_withdrawal_id' => $payoutData['id'] ?? null,
                    'external_data' => json_encode($payoutData),
                ]);

                return [
                    'success' => true,
                    'data' => $payoutData,
                    'transaction' => $transaction
                ];
            }

            return [
                'success' => false,
                'error' => $response->json()['message'] ?? 'Erro ao criar saque'
            ];
        } catch (Exception $e) {
            Log::error('NowPayments Create Payout Error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Verifica o status de um saque
     */
    public function getPayoutStatus(string $batchId): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
            ])->get($this->baseUrl . "/payout/{$batchId}");

            return [
                'success' => $response->successful(),
                'data' => $response->json()
            ];
        } catch (Exception $e) {
            Log::error('NowPayments Get Payout Status Error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Processa IPN de depósito
     */
    public function processDepositIPN(array $data): JsonResponse
    {
        try {
            // Verifica a autenticidade do IPN
            // if (!$this->verifyIPNSignature($data)) {
            //     Log::channel('nowpay_webhook')->warning('IPN signature verification failed', $data);
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'IPN signature verification failed'
            //     ], 400);
            // }

            $paymentId = $data['parent_payment_id'];
            $paymentStatus = $data['payment_status'];

            $transaction = Transaction::where('payment_id', $paymentId)->first();

            if (!$transaction) {
                Log::channel('nowpay_webhook')->warning('Transaction not found for payment_id: ' . $paymentId);
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not found for payment_id: ' . $paymentId
                ], 400);
            }

            // Atualiza o status da transação
            $transaction->update([
                'status' => $this->mapPaymentStatus($paymentStatus),
                'external_data' => json_encode($data),
            ]);

            // Se o pagamento foi confirmado, adiciona o saldo ao usuário
            if ($paymentStatus === 'confirmed') {
                $user = $transaction->user;
                $user->increment('balance', $transaction->amount);

                Log::channel('nowpay_webhook')->info("Depósito confirmado: {$transaction->amount} USDT para usuário {$user->id}");
            }

            return response()->json([
                'success' => true,
                'message' => 'Transaction successfull processed'
            ], 200);
        } catch (Exception $e) {
            Log::channel('nowpay_webhook')->error('Process Deposit IPN Error: ' . $e->getMessage(), $data);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Processa IPN de saque
     */
    public function processPayoutIPN(array $data): bool
    {
        try {
            // Verifica a autenticidade do IPN
            if (!$this->verifyIPNSignature($data)) {
                Log::warning('Payout IPN signature verification failed', $data);
                return false;
            }

            $batchId = $data['batch_withdrawal_id'];
            $status = $data['status'];

            $transaction = Transaction::where('batch_withdrawal_id', $batchId)->first();

            if (!$transaction) {
                Log::warning('Transaction not found for batch_withdrawal_id: ' . $batchId);
                return false;
            }

            $user = $transaction->user;

            // Atualiza o status da transação
            $transaction->update([
                'status' => $this->mapPayoutStatus($status),
                'external_data' => json_encode($data),
            ]);

            // Se o saque foi processado com sucesso
            if ($status === 'finished') {
                $user->decrement('usdt_frozen', $transaction->amount);
                Log::info("Saque processado: {$transaction->amount} USDT para usuário {$user->id}");
            }
            // Se o saque falhou, devolve o saldo
            elseif (in_array($status, ['failed', 'refunded', 'rejected'])) {
                $user->decrement('usdt_frozen', $transaction->amount);
                $user->increment('usdt_balance', $transaction->amount);
                Log::info("Saque falhou, saldo devolvido: {$transaction->amount} USDT para usuário {$user->id}");
            }

            return true;
        } catch (Exception $e) {
            Log::error('Process Payout IPN Error: ' . $e->getMessage(), $data);
            return false;
        }
    }

    /**
     * Verifica a assinatura do IPN
     */
    private function verifyIPNSignature(array $data): bool
    {
        if (!isset($data['hmac']) || !$this->ipnSecret) {
            return false;
        }

        $expectedSignature = $data['hmac'];
        unset($data['hmac']);

        $message = json_encode($data, JSON_UNESCAPED_SLASHES);
        $calculatedSignature = hash_hmac('sha512', $message, $this->ipnSecret);

        return hash_equals($expectedSignature, $calculatedSignature);
    }

    /**
     * Mapeia status de pagamento para status interno
     */
    private function mapPaymentStatus(string $status): string
    {
        return match ($status) {
            'waiting' => 'pending',
            'confirming' => 'confirming',
            'confirmed' => 'confirmed',
            'finished' => 'completed',
            'failed' => 'failed',
            'refunded' => 'refunded',
            'expired' => 'expired',
            default => 'pending'
        };
    }

    /**
     * Mapeia status de saque para status interno
     */
    private function mapPayoutStatus(string $status): string
    {
        return match ($status) {
            'waiting' => 'pending',
            'processing' => 'processing',
            'finished' => 'completed',
            'failed' => 'failed',
            'refunded' => 'refunded',
            'rejected' => 'rejected',
            default => 'pending'
        };
    }

    /**
     * Obtém o saldo mínimo para saque
     */
    public function getMinimumPayoutAmount(): float
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
            ])->get($this->baseUrl . '/min-amount?currency_from=usdt&currency_to=usdt');

            if ($response->successful()) {
                $data = $response->json();
                return (float) $data['min_amount'];
            }

            return 10.0; // Valor padrão
        } catch (Exception $e) {
            Log::error('Get Minimum Payout Amount Error: ' . $e->getMessage());
            return 10.0; // Valor padrão
        }
    }

    /**
     * Valida endereço USDT
     */
    public function validateUSDTAddress(string $address): bool
    {
        // Validação básica para endereços USDT (TRC20/ERC20)

        // TRC20 (Tron) - começa com 'T' e tem 34 caracteres
        if (preg_match('/^T[A-Za-z1-9]{33}$/', $address)) {
            return true;
        }

        // ERC20 (Ethereum) - começa com '0x' e tem 42 caracteres hexadecimais
        if (preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
            return true;
        }

        return false;
    }

    /**
     * Obtém lista de transações
     */
    public function getUserTransactions(User $user, int $limit = 10): array
    {
        return Transaction::where('user_id', $user->id)
            ->whereIn('currency', ['USDT'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}
