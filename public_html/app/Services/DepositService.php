<?php
// app/Services/DepositService.php

namespace App\Services;

use App\Models\Deposit;
use App\Models\Transaction;
use App\Enums\TransactionStatus;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserLedger;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;

class DepositService
{
    private const COMMISSION_LEVELS = [1, 2, 3, 4, 5];
    public function approveDeposit(Deposit $deposit): void
    {
        DB::transaction(function () use ($deposit) {
            $user = $deposit->user()->lockForUpdate()->first();
            $user->increment('balance', $deposit->amount);

            $deposit->update([
                'status' => TransactionStatus::APPROVED,
                'processed_at' => now(),
            ]);

            $transaction = Transaction::where('deposit_id', $deposit->id)->first();
            if ($transaction) {
                $transaction->update(['status' => TransactionStatus::APPROVED]);
            }

            $paidComissions = setting('paid_comissions_on_deposit');

            if ($paidComissions) {
                $this->processReferralCommissions($deposit);
            }
        });

        $this->notifyUser($deposit);
    }

    public function rejectDeposit(Deposit $deposit): void
    {
        DB::transaction(function () use ($deposit) {
            $deposit->update([
                'status' => TransactionStatus::REJECTED,
                'processed_at' => now(),
            ]);

            $user = $deposit->user()->lockForUpdate()->first();
            $user->decrement('balance', $deposit->amount);

            $transaction = Transaction::where('deposit_id', $deposit->id)->first();
            if ($transaction) {
                $transaction->update(['status' => TransactionStatus::REJECTED]);
            }
        });

        $this->notifyUser($deposit);
    }
    public function cancelDeposit(Deposit $deposit): void
    {
        DB::transaction(function () use ($deposit) {
            $deposit->update([
                'status' => TransactionStatus::CANCELED,
                'processed_at' => now(),
            ]);

            $transaction = Transaction::where('deposit_id', $deposit->id)->first();
            if ($transaction) {
                $transaction->update(['status' => TransactionStatus::CANCELED]);
            }
        });

        $this->notifyUser($deposit);
    }

    protected function notifyUser(Deposit $deposit): void
    {
        $pusher = new Pusher(
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
            $userLedger->status = TransactionStatus::APPROVED;
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


    /**
     * Generate random data for deposits.
     *
     * @return array{
     *    telefone: string,
     *    cpf: string,
     *    email: string
     * }
     */
    public function generateDataDeposits(): array
    {
        $telefones = [
            '(11) 91234-5678',
            '(21) 99876-5432',
            '(31) 98765-4321',
            '(41) 99654-3210',
            '(51) 99543-2109',
            '(61) 99432-1098',
            '(71) 99321-0987',
            '(81) 99210-9876',
            '(91) 99109-8765',
            '(85) 99098-7654',
        ];

        $cpfs = [
            '484.566.330-97',
            '504.538.700-66',
            '901.824.890-86',
            '061.048.020-01',
            '787.809.650-32',
            '328.380.070-76',
            '629.616.120-47',
            '199.975.460-32',
            '300.360.240-31',
            '929.837.840-88',
            '193.561.100-34',
        ];

        $emails = [
            'plataform@gmail.com',
            'plataform2@gmail.com',
            'platafor3@gmail.com',
            'plataform4@gmail.com',
            'plataform5@gmail.com',
            'plataform6@gmail.com',
            'plataform7@gmail.com',
            'plataform8@gmail.com',
            'plataform9@gmail.com',
            'plataform10@gmail.com',
        ];

        // Seleciona um número aleatório
        $telefoneAleatorio = $telefones[array_rand($telefones)];
        $cpfAleatorio = $cpfs[array_rand($cpfs)];
        $emailAleatorio = $emails[array_rand($emails)];

        return [
            'telefone' => $telefoneAleatorio,
            'cpf' => $cpfAleatorio,
            'email' => $emailAleatorio,
        ];
    }
}
