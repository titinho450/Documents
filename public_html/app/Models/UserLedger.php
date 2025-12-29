<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserLedger extends Model
{
    use HasFactory;

    protected $table = 'user_ledgers';

    protected $fillable = [
        'user_id',
        'reason',
        'perticulation',
        'amount',
        'credit',
        'status',
        'date',
        'get_balance_from_user_id',
        'step'
    ];

    protected $casts = [
        'step' => 'integer'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
