<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Deposit extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'method_name',
        'address',
        'transaction_id',
        'order_id',
        'amount',
        'date',
        'status',
        'processed_at'
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'date' => 'datetime',
        'amount' => 'decimal:2',
        'status' => TransactionStatus::class
    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Buscar total de depósitos aprovados
    public static function getTotalApprovedDeposits()
    {
        return self::where('status', TransactionStatus::APPROVED)
            ->sum('amount');
    }

    // Buscar total de depósitos aprovados no último mês
    public static function getTotalApprovedDepositsLastMonth()
    {
        $lastMonth = Carbon::now()->subMonth();

        return self::where('status', TransactionStatus::APPROVED)
            ->where('date', '>=', $lastMonth->startOfMonth())
            ->where('date', '<=', $lastMonth->endOfMonth())
            ->sum('amount');
    }

    // Buscar total de depósitos aprovados nos últimos 30 dias
    public static function getTotalApprovedDepositsLast30Days()
    {
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        return self::where('status', TransactionStatus::APPROVED)
            ->where('date', '>=', $thirtyDaysAgo)
            ->sum('amount');
    }

    // Buscar total de depósitos aprovados nos 30 dias anteriores (para comparação)
    public static function getTotalApprovedDepositsPrevious30Days()
    {
        $sixtyDaysAgo = Carbon::now()->subDays(60);
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        return self::where('status', TransactionStatus::APPROVED)
            ->where('date', '>=', $sixtyDaysAgo)
            ->where('date', '<', $thirtyDaysAgo)
            ->sum('amount');
    }

    // Calcular percentual de diferença nos últimos 30 dias
    public static function getPercentageDifferenceLast30Days()
    {
        $currentPeriod = self::getTotalApprovedDepositsLast30Days();
        $previousPeriod = self::getTotalApprovedDepositsPrevious30Days();

        if ($previousPeriod == 0) {
            return $currentPeriod > 0 ? 100 : 0;
        }

        $difference = $currentPeriod - $previousPeriod;
        $percentage = ($difference / $previousPeriod) * 100;

        return round($percentage, 2);
    }

    // Método para obter todas as estatísticas de uma vez
    public static function getDepositStatistics()
    {
        return [
            'total_approved' => self::getTotalApprovedDeposits(),
            'total_approved_last_month' => self::getTotalApprovedDepositsLastMonth(),
            'total_approved_last_30_days' => self::getTotalApprovedDepositsLast30Days(),
            'total_approved_previous_30_days' => self::getTotalApprovedDepositsPrevious30Days(),
            'percentage_difference_30_days' => self::getPercentageDifferenceLast30Days(),
        ];
    }

    // Método para obter contagem de depósitos (opcional)
    public static function getDepositCounts()
    {
        return [
            'total_approved_count' => self::where('status', TransactionStatus::APPROVED)->count(),
            'last_30_days_count' => self::where('status', TransactionStatus::APPROVED)
                ->where('date', '>=', Carbon::now()->subDays(30))
                ->count(),
            'previous_30_days_count' => self::where('status', TransactionStatus::APPROVED)
                ->where('date', '>=', Carbon::now()->subDays(60))
                ->where('date', '<', Carbon::now()->subDays(30))
                ->count(),
        ];
    }

    public function transaction()
    {
        return $this->hasOne(Transaction::class, 'deposit_id');
    }
}
