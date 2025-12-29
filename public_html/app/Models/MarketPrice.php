<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketPrice extends Model
{
    protected $fillable = [
        'symbol',
        'price_usd',
        'date'
    ];

    protected $casts = [
        'date' => 'date',
    ];
}
