<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralLevel extends Model
{
    protected $fillable = [
        'level',
        'bonus_percentage'
    ];

    protected $casts = [
        'bonus_percentage' => 'float'
    ];
}
