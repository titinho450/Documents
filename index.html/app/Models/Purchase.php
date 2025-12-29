<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'package_id',
        'transaction_id',
        'amount',
        'daily_income',
        'date',
        'status',
        'validity'
    ];

    protected $casts = [
        'date' => 'datetime',
    ];

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'purchase_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function package()
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    public function plans()
    {
        return $this->belongsTo(CyclePlan::class, 'package_id');
    }
}
