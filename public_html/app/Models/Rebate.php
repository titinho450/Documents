<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rebate extends Model
{
    use HasFactory;

    protected $fillable = [
        'interest_commission1',
        'interest_commission2',
        'interest_commission3',
        'interest_commission4',
        'interest_commission5',
    ];

    protected $casts = [
        'interest_commission1' => 'float',
        'interest_commission2' => 'float',
        'interest_commission3' => 'float',
        'interest_commission4' => 'float',
        'interest_commission5' => 'float',
    ];
}
