<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WithdrawalAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'full_name',
        'cpf',
        'phone',
        'pix_key_type', // cpf, email, phone, random
        'pix_key',
        'is_default',
        'status' // active, inactive
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
