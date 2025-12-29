<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserChallengeGoal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'challenge_goal_id',
        'current_investment',
        'is_completed',
        'completed_at',
        'bonus_claimed',
        'bonus_claimed_at'
    ];

    protected $casts = [
        'current_investment' => 'float',
        'is_completed' => 'boolean',
        'bonus_claimed' => 'boolean',
        'completed_at' => 'datetime',
        'bonus_claimed_at' => 'datetime',
    ];

    public function updateProgress(float $amount): void
    {
        // Se current_investment for null, inicializa com 0
        $this->current_investment = $this->current_investment ?? 0;

        $this->current_investment += $amount;

        if ($this->current_investment >= $this->challengeGoal->required_investment && !$this->is_completed) {
            $this->is_completed = true;
            $this->completed_at = now();
        } else {
            $this->is_completed = false;
        }

        $this->save();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function challengeGoal(): BelongsTo
    {
        return $this->belongsTo(ChallengeGoal::class);
    }

    public function getProgressPercentageAttribute()
    {
        if ($this->challengeGoal->required_investment <= 0) {
            return 0;
        }

        $percentage = ($this->current_investment / $this->challengeGoal->required_investment) * 100;
        return min(100, round($percentage, 2));
    }

    public function getRemainingAmountAttribute()
    {
        $remaining = $this->challengeGoal->required_investment - $this->current_investment;
        return max(0, $remaining);
    }
}
