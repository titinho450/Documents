<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ChallengeGoal extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'required_investment',
        'bonus_amount',
        'bonus_type',
        'order',
        'is_active'
    ];

    protected $casts = [
        'required_investment' => 'float',
        'bonus_amount' => 'float',
        'is_active' => 'boolean',
        'order' => 'integer'
    ];

    public function userChallenges()
    {
        return $this->hasMany(UserChallengeGoal::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }
}
