<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BinaryTrade extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'amount_cents',
        'direction',
        'status',
        'expires_at',
        'result_decided_by_admin_id',
        'settled_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'result_decided_by_admin_id');
    }

    public function transaction(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(WalletTransaction::class, 'reference');
    }
}
