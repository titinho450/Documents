<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Enums\TransactionStatus;
use App\Enums\TransactionTypes;
use App\Services\ChallengeGoalService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    private const REFERRALS_LEVELS = [1, 2, 3, 4, 5];
    use HasApiTokens;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use Notifiable;
    use HasRoles;



    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'ref_id',
        'phone_code',
        'total_commission',
        'profit_balance',
        'blocked_balance',
        'balance_cents',
        'phone',
        'ref_by',
        'username',
        'code',
        'ip',
        'balance',
        'register_bonus',
        'withdraw_password',
        'pix_type',
        'pix_key',
        'is_afiliate'
    ];

    protected $casts = [
        'blocked_balance' => 'float',
        'balance_cents' => 'integer',
        'total_commission' => 'float'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',

    ];

    /**
     * Aumenta o saldo do usuário.
     *
     * @param int $amountCents
     */
    public function addBalance(int $amountCents): void
    {
        $this->balance += (float) $amountCents;
        $this->save();
    }

    public function binaryTrades(): HasMany
    {
        return $this->hasMany(BinaryTrade::class);
    }

    public function userChallengeGoals(): HasMany
    {
        return $this->hasMany(UserChallengeGoal::class);
    }

    public function challengeGoals()
    {
        return $this->belongsToMany(ChallengeGoal::class, 'user_challenge_goals')
            ->withPivot(['current_investment', 'is_completed', 'completed_at', 'bonus_claimed', 'bonus_claimed_at'])
            ->withTimestamps();
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function withdraws()
    {
        return $this->hasMany(Withdrawal::class, 'user_id');
    }

    public function hasMultipleValueWithdraws(float $amount): bool
    {
        $countWithdraws = $this->withdraws()
            ->where('amount', $amount)
            ->where('created_at', '>=', now()->subHours(3))
            ->count();

        return $countWithdraws > 2;
    }

    public function remainingToInvest90Percent(): float
    {
        $totalDeposits = $this->deposits()->sum('amount');
        $totalPurchases = $this->purchases()->sum('amount');

        if ($totalDeposits <= 0) {
            return 0; // não tem depósitos, então nada a calcular
        }

        $required = $totalDeposits * 0.9; // 90% do total depositado
        $remaining = $required - $totalPurchases;

        return $remaining > 0 ? $remaining : 0;
    }

    public function hasInvested90Percent(): bool
    {
        $totalDeposits = $this->deposits()->sum('amount');
        $totalPurchases = $this->purchases()->sum('amount');

        if ($totalDeposits <= 0) {
            return false; // nunca depositou, então não investiu
        }

        return $totalPurchases >= ($totalDeposits * 0.9);
    }

    public function deposits()
    {
        return $this->hasMany(Deposit::class, 'user_id');
    }

    public function referralsChallengeGoal(float $amount)
    {

        /** @var \App\Models\User $referrer */
        $referrer = $this->referrer;
        $currentLevel = 1;
        Log::info('[ATUALIZANDO MISSOES DE REFERIDOS] VALOR: ' . $amount);


        // Itera sobre os níveis de indicação, até 3 níveis ou até que não haja mais um referrer.

        while ($referrer && $currentLevel <= 3) {

            $challengeGoals = ChallengeGoal::active()->get();

            foreach ($challengeGoals as $goal) {
                $userChallenge = UserChallengeGoal::firstOrCreate(
                    [
                        'user_id' => $referrer->id,
                        'challenge_goal_id' => $goal->id
                    ],
                    [
                        'current_investment' => 0,
                        'is_completed' => false,
                        'bonus_claimed' => false
                    ]
                );

                // Atualizar o investimento atual
                $userChallenge->increment('current_investment', $amount);

                // checa o total de investimento da equipe
                $invested = $referrer->purchases()->sum('amount');

                Log::info("[SOMA DE INVESTIMENTOS DO USUARIO]: {$referrer->phone} [VALOR]: {$invested}");

                // Verificar se a meta foi completada
                if (!$userChallenge->is_completed && $userChallenge->current_investment >= $goal->required_investment) {
                    $userChallenge->update([
                        'is_completed' => true,
                        'completed_at' => now()
                    ]);

                    Log::info("Meta de desafio completada - Usuário: {$referrer->id}, Meta: {$goal->title}");
                }
            }
            // Move para o próximo nível de referrer.
            $referrer = $referrer->referrer;
            $currentLevel++;
        }
    }

    /**
     * Processa a comissão de indicação para até 5 níveis.
     *
     * @param float $amount O valor base para o cálculo da comissão.
     */
    public function processComissionReferral(float $amount, string $description = 'Comissão de indicação do nível')
    {
        // Pega as taxas de comissão do modelo Rebate. Assumimos que há uma única entrada.
        $rebateRates = Rebate::first();

        Log::info('[DADOS REBATE]: ' . json_encode($rebateRates, JSON_PRETTY_PRINT));

        // Se não houver taxas de comissão, não há o que processar.
        if (!$rebateRates) {
            return;
        }

        $referrer = $this->referrer;
        $currentLevel = 1;
        Log::info('[VALOR DO INVESTIMENTO] USER: ' . $amount);


        // Itera sobre os níveis de indicação, até 3 níveis ou até que não haja mais um referrer.
        while ($referrer && $currentLevel <= 3) {
            // Constrói o nome da coluna dinamicamente, ex: 'interest_commission1'
            $commissionKey = 'interest_commission' . $currentLevel;
            $commissionRate = $rebateRates->{$commissionKey} ?? 0;

            if ($commissionRate > 0) {
                // Calcula o valor da comissão e converte para centavos.
                $commissionAmount = $amount * ($commissionRate / 100);

                Log::info('[PROCESSANDO COMISSÃO] USER: ' . $referrer->id . ' VALOR: ' .  (float) $commissionAmount);

                // Aumenta o saldo do referrer.
                $referrer->addBalance($commissionAmount);


                // Atualiza o total de comissão do referrer.
                $referrer->total_commission += $commissionAmount;
                $referrer->save();

                // Registra a comissão no ledger do usuário para auditoria.
                $referrer->ledgers()->create([
                    'type' => 'commission',
                    'get_balance_from_user_id' => $this->id,
                    'credit' => $commissionAmount,
                    'debit' => 0,
                    'date' => now(),
                    'step' => $currentLevel,
                    'status' => TransactionStatus::APPROVED,
                    'reason' => "commission_indication",
                    'perticulation' => "Comissão de indicação do nível {$currentLevel} de " . $this->name ?? $this->phone,
                    'amount' => $commissionAmount,
                    'details' => "Comissão de indicação do nível {$currentLevel} de " . $this->name ?? $this->phone,
                ]);

                $orderId = TransactionTypes::COMISSION . '_' . $this->id . '_' . time();

                // Registra a transaction.
                $referrer->transactions()->create([
                    'type' => TransactionTypes::COMISSION,
                    'currency' => 'BRL',
                    'amount' => (float) $commissionAmount,
                    'payment_id' => $orderId,
                    'order_id' => $orderId,
                    'payment_address' => 'BALANCE',
                    'status' => TransactionStatus::COMPLETED,
                    'description' => $description
                ]);
            }

            // Move para o próximo nível de referrer.
            $referrer = $referrer->referrer;
            $currentLevel++;
        }
    }

    /**
     * Diminui o saldo do usuário.
     *
     * @param int $amountCents
     */
    public function subtractBalance(int $amountCents): void
    {
        $this->balance -= $amountCents;
        $this->save();
    }

    public function investments()
    {
        return $this->hasMany(UserInvestment::class);
    }

    public function getNetworkAttribute()
    {
        $network = collect();

        $this->load('referrals'); // garante que o relacionamento esteja carregado

        $this->buildNetwork($this, $network, 1);

        return $network;
    }

    private function buildNetwork(User $user, Collection &$network, int $level)
    {
        if ($level > 5) {
            return;
        }

        foreach ($user->referrals as $referral) {
            $referral->nivel = $level;
            $network->push($referral);

            $referral->load('referrals'); // carrega referrals do referral
            $this->buildNetwork($referral, $network, $level + 1);
        }
    }


    public function getTransactionsAttribute()
    {
        return $this->hasMany(Transaction::class, 'user_id')->get();
    }

    public function activeInvestments()
    {
        return $this->investments()->where('active', true);
    }

    public function getActiveInvestmentsAttribute()
    {
        return $this->investments()->where('active', true)->get();
    }

    public function getTotalInvestedAttribute()
    {
        return $this->purchases()->sum('amount');
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function sponsor()
    {
        return $this->belongsTo(User::class, 'sponsor_id');
    }

    public function referred()
    {
        return $this->hasMany(User::class, 'sponsor_id');
    }

    public function checkins()
    {
        return $this->hasMany(UserCheckin::class);
    }

    public function hasCheckedInToday()
    {
        return $this->checkins()
            ->whereDate('checkin_date', now()->toDateString())
            ->exists();
    }

    public function lastCheckin()
    {
        return $this->checkins()
            ->latest('checkin_date')
            ->first();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function withdrawAccount()
    {
        return $this->hasOne(WithdrawalAccount::class, 'user_id');
    }

    public function withdraw()
    {
        return $this->hasMany(Withdrawal::class, 'user_id');
    }

    public function commissions()
    {
        return $this->hasMany(UserLedger::class, 'user_id');
    }

    public function ledgers()
    {
        return $this->hasMany(UserLedger::class, 'user_id');
    }

    public function referrals()
    {
        return $this->hasMany(User::class, 'ref_by', 'ref_id')->whereNotNull('ref_by');
    }

    /**
     * Relacionamento para o usuário que indicou este usuário (o "referrer").
     *
     * @return BelongsTo
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ref_by', 'ref_id');
    }

    public function getReferralAttribute()
    {
        return [
            'total_count' => $this->referrals()->count(),
            'active_count' => $this->referrals()->where('status', 'active')->count(),
            'investor_count' => $this->referrals()->whereHas('investments')->count(),
            'level1_count' => $this->referrals()->where('ref_by', $this->ref_id)->count(),
            'level2_count' => $this->referrals()->whereHas('referrals', function ($query) {
                $query->where('ref_by', $this->ref_id);
            })->count(),
            'level3_count' => $this->referrals()->whereHas('referrals.referrals', function ($query) {
                $query->where('ref_by', $this->ref_id);
            })->count(),
            'referrals' => $this->referrals->map(function ($referral) {
                return [
                    'id' => $referral->id,
                    'name' => $referral->name,
                    'email' => $referral->email,
                    'status' => $referral->status,
                    'created_at' => $referral->created_at,
                    'investments' => $referral->investments()->sum('amount'),
                ];
            }),
        ];
    }

    public function getTotalReferralInvestmentsAttribute()
    {
        $total = 0;
        foreach ($this->referrals as $referral) {
            $total += $referral->investments()->sum('amount');
        }
        return $total;
    }

    public function plans()
    {
        return $this->purchases->plans;
    }

    public function purchaseCycles()
    {
        return $this->hasMany(UserCycle::class);
    }
}
