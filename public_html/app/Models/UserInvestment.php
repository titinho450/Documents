<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserInvestment extends Model
{
    protected $fillable = [
        'user_id',
        'investment_package_id',
        'amount',
        'start_date',
        'end_date',
        'total_earned',
        'active'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function investmentPackage()
    {
        return $this->belongsTo(InvestmentPackage::class);
    }
}
