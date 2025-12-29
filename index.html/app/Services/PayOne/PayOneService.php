<?php

namespace App\Services\PayOne;

use App\Enums\TransactionStatus;
use App\Events\PusherEvent;
use App\Models\Deposit;
use App\Models\Payout;
use App\Models\Referral;
use App\Models\ReferralBonus;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserLedger;
use App\Models\Withdrawal;
use App\Services\PayOne\DTO\PayOneDepositResponse;
use App\Services\PayOne\Exceptions\PayOneApiException;
use App\Services\PayOne\Exceptions\PayOneValidationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class PayOneService
{

    private const API_BASE_URL = 'https://app.getpayone.com/api/v1';
    private const COMMISSION_LEVELS = [1, 2, 3, 4, 5];
    private const VALID_COMPLETED_STATUS = [
        'PAID_OUT',
        'APROVADO',
        'PAGAMENTO_APROVADO',
        'COMPLETED'
    ];

    private string $publicKey;
    private string $secretKey;



    public function __construct(string $publicKey, string $secretKey)
    {
        $this->publicKey = $publicKey;
        $this->secretKey = $secretKey;
    }

    /**
     * Make a PIX deposit
     * 
     * @param PayOneDepositPayload $payload Dados do depósito
     * @return PayOneDepositResponse Resposta do depósito contendo os dados do PIX
     * @throws PayOneApiException When the API request fails
     * @throws PayOneValidationException When validation fails
     */
    public function deposit(PayOneDepositPayload $payload): PayOneDepositResponse
    {
        try {
            $response = $this->makeApiRequest(
                'POST',
                '/gateway/pix/receive',
                $payload->jsonSerialize()
            );

            return new PayOneDepositResponse($response);
        } catch (Exception $e) {
            Log::error('PayOne deposit request failed', [
                'payload' => $payload->jsonSerialize(),
                'error' => $e->getMessage()
            ]);
            throw new PayOneApiException('Failed to process deposit request: ' . $e->getMessage());
        }
    }

    /**
     * Get transaction details
     * 
     * @param string $transactionId
     * @param string $clientIdentifier
     * @return array
     * @throws PayOneApiException
     */
    public function getTransaction(string $transactionId, string $clientIdentifier): array
    {
        try {
            return $this->makeApiRequest('GET', '/gateway/transactions', [
                'id' => $transactionId,
                'clientIdentifier' => $clientIdentifier
            ]);
        } catch (Exception $e) {
            Log::error('Failed to fetch transaction', [
                'transaction_id' => $transactionId,
                'client_identifier' => $clientIdentifier,
                'error' => $e->getMessage()
            ]);
            throw new PayOneApiException('Failed to fetch transaction details: ' . $e->getMessage());
        }
    }

    /**
     * Reserved for future cashout implementation
     * 
     * @param Payout $payout
     * @throws PayOneApiException
     */
    public function cashout(Withdrawal $withdraw): void
    {
        throw new PayOneApiException('Cashout functionality not yet implemented');
    }

    /**
     * Handle incoming webhooks
     */
    public function handleWebhook(string $type, array $payload): string
    {
        try {
            $this->validateWebhookPayload($payload);

            Log::info('PayOne webhook received', [
                'type' => $type,
                'payload' => $payload
            ]);

            return match ($type) {
                'deposit' => $this->processDepositWebhook($payload),
                'cashout' => $this->processCashoutWebhook($payload),
                default => throw new PayOneValidationException('Invalid webhook type: ' . $type)
            };
        } catch (Exception $e) {
            Log::error('PayOne webhook processing failed', [
                'type' => $type,
                'payload' => $payload,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function processDepositWebhook(array $payload): string
    {
        return DB::transaction(function () use ($payload) {
            $deposit = $this->findAndValidateDeposit($payload);


            if ($this->isValidDeposit($deposit, $payload)) {
                $this->updateDepositAndUserBalance($deposit);
                $this->processReferralCommissions($deposit);

                Log::info('Deposit processed successfully', [
                    'deposit_id' => $deposit->id,
                    'amount' => $deposit->amount
                ]);

                return 'Deposit processed successfully';
            }

            Log::warning('Invalid deposit detected', [
                'deposit_id' => $deposit->id,
                'payload' => $payload
            ]);

            return 'Invalid deposit';
        });
    }

    private function findAndValidateDeposit(array $payload): Deposit
    {
        $deposit = Deposit::query()
            ->where('transaction_id', $payload['transaction']['id'])
            ->where('status', "pending")
            ->firstOrFail();

        Log::info("[TYPE]:DEPOSIT -> Deposit found: ", $deposit->toArray());

        // $transactionDetails = $this->getTransaction(
        //     $payload['transaction']['id'],
        //     $deposit->order_id
        // );

        // if (!$this->validateTransactionAmount($deposit->amount, $transactionDetails['amount'])) {
        //     throw new PayOneValidationException('Transaction amount mismatch');
        // }

        return $deposit;
    }

    private function isValidDeposit(Deposit $deposit, array $payload): bool
    {
        // $transactionDetails = $this->getTransaction(
        //     $payload['transaction']['id'],
        //     $deposit->order_id
        // );

        // return in_array($payload['transaction']['status'], self::VALID_COMPLETED_STATUS) &&
        //     in_array($transactionDetails['status'], self::VALID_COMPLETED_STATUS) &&
        //     $this->validateTransactionAmount($deposit->amount, $transactionDetails['amount']);
        return in_array($payload['transaction']['status'], self::VALID_COMPLETED_STATUS) &&
            $this->validateTransactionAmount($deposit->amount, $payload['transaction']['amount']);
    }

    private function validateTransactionAmount(float $expectedAmount, float $actualAmount): bool
    {
        return abs($expectedAmount - $actualAmount) < 0.01;
    }

    public function updateDepositAndUserBalance(Deposit $deposit): void
    {
        DB::transaction(function () use ($deposit) {
            // Lock na linha do usuário
            $user = $deposit->user()->lockForUpdate()->first();

            // Incrementa o saldo do usuário
            $user->increment('balance', $deposit->amount);

            // Atualiza o depósito
            $deposit->update([
                'status' => TransactionStatus::APPROVED,
                'processed_at' => now(),
            ]);

            // Atualiza a transação se existir
            $transaction = Transaction::where('deposit_id', $deposit->id)->first();

            if ($transaction) {
                $transaction->update([
                    'status' => TransactionStatus::APPROVED,
                ]);
            }
        });

        // Notificação com Pusher DEPOIS do commit
        $pusher = new \Pusher\Pusher(
            config('broadcasting.connections.pusher.key'),
            config('broadcasting.connections.pusher.secret'),
            config('broadcasting.connections.pusher.app_id'),
            config('broadcasting.connections.pusher.options')
        );

        $pusher->trigger('chanel-user-' . $deposit->user->id, 'paid', [
            'user_id' => $deposit->user->id,
            'message' => "O depósito no valor de R$" . $deposit->amount . " foi creditado em sua conta.",
            'user' => $deposit->user,
            'timestamp' => now(),
            'type' => 'info',
        ]);
    }

    public function processReferralCommissions(Deposit $deposit): void
    {
        $commissions = $this->getReferralCommissions();
        $processedUsers = new \SplObjectStorage();

        try {
            $currentUser = $deposit->user;

            foreach (self::COMMISSION_LEVELS as $level) {
                if (!$currentUser || !isset($commissions[$level])) {
                    break;
                }

                $referral = User::where('ref_id', $currentUser->ref_by)->first();
                if (!$referral || $processedUsers->contains($referral)) {
                    break;
                }

                $this->processCommissionForUser($deposit, $referral, $level, $commissions[$level]);
                $processedUsers->attach($referral);
                $currentUser = $referral;
            }
        } catch (Exception $e) {
            Log::error('Commission processing failed', [
                'deposit_id' => $deposit->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function processCommissionForUser(Deposit $deposit, User $user, int $level, float $commissionRate): void
    {
        DB::transaction(function () use ($deposit, $user, $level, $commissionRate) {
            $commission = $deposit->amount * ($commissionRate / 100);

            $user->increment('total_commission', $commission);
            $user->increment('profit_balance', $commission);

            $userLedger = new UserLedger();
            $userLedger->user_id = $user->id;
            $userLedger->get_balance_from_user_id = $deposit->user_id;
            $userLedger->reason = 'commission';
            $userLedger->perticulation = 'Bonus de indicado nivel: ' . $level;
            $userLedger->amount = $commission;
            $userLedger->credit = $commission;
            $userLedger->status = 'approved';
            $userLedger->date = now();
            $userLedger->step = $level;

            $userLedger->save();

            $userFrom = User::find($deposit->user_id);

            Log::info("[TYPE]:COMISSION -> Comission added: ", $userLedger->toArray());

            if ($userFrom) {
                $pusher = new \Pusher\Pusher(
                    config('broadcasting.connections.pusher.key'),
                    config('broadcasting.connections.pusher.secret'),
                    config('broadcasting.connections.pusher.app_id'),
                    config('broadcasting.connections.pusher.options')
                );

                $pusher->trigger('chanel-user-' . $user->id, 'paid', [
                    'user_id' => $user->id,
                    'message' => "Comissão recebida no valor de " . $commission . " pelo usuário " . $userFrom->name,
                    'user' => $user,
                    'timestamp' => now(),
                    'type' => 'info'
                ]);

                Log::info("Notificação enviada diretamente via Pusher no canal" . ' private-user-' . $user->id);
            }

            // ReferralBonus::create([
            //     'from_user_id' => $user->id,
            //     'to_user_id' => $deposit->user_id,
            //     'level' => $level,
            //     'amount' => $commission,
            //     'commission_type' => 'Referral Deposit Bonus',
            //     'remarks' => "Level $level referral bonus"
            // ]);
        });
    }

    private function getReferralCommissions(): array
    {
        return Referral::query()
            ->where('commission_type', 'deposit')
            ->whereIn('level', self::COMMISSION_LEVELS)
            ->pluck('commission', 'level')
            ->toArray();
    }



    private function processCashoutWebhook(array $payload): string
    {
        // Reserved for future implementation
        Log::info('Cashout webhook received', ['payload' => $payload]);
        return 'Cashout webhook acknowledged';
    }

    private function makeApiRequest(string $method, string $endpoint, array $data = []): array
    {
        $response = Http::withHeaders([
            'x-public-key' => $this->publicKey,
            'x-secret-key' => $this->secretKey
        ])->$method(rtrim(self::API_BASE_URL, '/') . $endpoint, $data);

        if (!$response->successful()) {
            throw new PayOneApiException(
                'API request failed: ' . ($response->json('message') ?? 'Unknown error'),
                $response->status()
            );
        }

        return $response->json();
    }

    private function validateWebhookPayload(array $payload): void
    {
        if (empty($payload['transaction']['id'])) {
            throw new PayOneValidationException('Missing transaction ID in webhook payload');
        }
    }
}

class PayOneDepositPayload implements \JsonSerializable
{
    public string $identifier;
    public float $amount;
    public PayOneClient $client;
    public ?float $discount;
    public string $dueDate;
    public string $callbackUrl;

    public function __construct(
        string $identifier,
        float $amount,
        PayOneClient $client,
        ?float $discount,
        string $dueDate,
        string $callbackUrl
    ) {
        $this->identifier = $identifier;
        $this->amount = $amount;
        $this->client = $client;
        $this->discount = $discount;
        $this->dueDate = $dueDate;
        $this->callbackUrl = $callbackUrl;
    }

    public function jsonSerialize(): array
    {
        return [
            'identifier' => $this->identifier,
            'amount' => $this->amount,
            'client' => $this->client->jsonSerialize(),
            'discount' => $this->discount,
            'dueDate' => $this->dueDate,
            'callbackUrl' => $this->callbackUrl
        ];
    }
}

class PayOneClient implements \JsonSerializable
{
    public string $name;
    public string $email;
    public string $phone;
    public string $document;

    public function __construct(string $name, string $email, string $phone, string $document)
    {
        $this->name = $name;
        $this->email = $email;
        $this->phone = $phone;
        $this->document = $document;
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'document' => $this->document
        ];
    }
}
