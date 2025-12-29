<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestmentPackage extends Model
{
    protected $fillable = [
        'name',
        'symbol',
        'image',
        'min_return_rate',
        'max_return_rate',
        'duration_days'
    ];

    protected $casts = [
        'min_return_rate' => 'decimal:2',
        'max_return_rate' => 'decimal:2',
        'duration_days' => 'integer',
    ];
}
