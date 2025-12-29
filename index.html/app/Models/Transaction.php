<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Transaction extends Model
{
    use HasApiTokens;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use Notifiable;
    //
    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'currency',
        'ref_id',
        'status',
        'payment_id',
        'order_id',
        'payment_address',
        'payment_amount',
        'withdrawal_address',
        'batch_withdrawal_id',
        'external_data',
        'description',
        'deposit_id',
        'withdraw_id',
        'purchase_id'
    ];

    protected $casts = [
        'external_data' => 'array',
        'amount' => 'float',
        'payment_amount' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeDeposits($query)
    {
        return $query->where('type', 'deposit');
    }

    public function scopeWithdrawals($query)
    {
        return $query->where('type', 'withdrawal');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount, 8) . ' ' . $this->currency;
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Pendente',
            'confirming' => 'Confirmando',
            'confirmed' => 'Confirmado',
            'processing' => 'Processando',
            'completed' => 'Concluído',
            'failed' => 'Falhou',
            'refunded' => 'Reembolsado',
            'expired' => 'Expirado',
            'rejected' => 'Rejeitado',
            default => 'Desconhecido'
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'yellow',
            'confirming' => 'blue',
            'confirmed' => 'green',
            'processing' => 'blue',
            'completed' => 'green',
            'failed' => 'red',
            'refunded' => 'orange',
            'expired' => 'gray',
            'rejected' => 'red',
            default => 'gray'
        };
    }


    public function deposit()
    {
        return $this->belongsTo(Deposit::class, 'deposit_id');
    }

    public function withdraw()
    {
        return $this->belongsTo(Withdraw::class, 'withdraw_id');
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }
}
