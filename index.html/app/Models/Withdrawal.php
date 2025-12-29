<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Withdrawal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'cpf',
        'pix_type',
        'pix_key',
        'transaction_id',
        'method_name',
        'oid',
        'address',
        'amount',
        'charge',
        'final_amount',
        'ip',
        'status'
    ];

    protected $casts = [
        'amount' => 'float',
        'final_amount' => 'float',
        'charge' => 'float'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payment_method()
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
