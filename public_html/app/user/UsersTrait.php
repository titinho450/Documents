<?php

namespace App\user;

use App\Models\ReferralBonus;

trait UsersTrait
{
    public function distributeReferralBonus(User $user, float $amount)
    {
        $sponsor = $user->sponsor;
        $level = 1;

        while ($sponsor && $level <= 10) {
            $referralLevel = ReferralLevel::where('level', $level)->first();
            if ($referralLevel) {
                $bonus = $amount * ($referralLevel->bonus_percentage / 100);

                ReferralBonus::create([
                    'user_id' => $sponsor->id,
                    'referred_user_id' => $user->id,
                    'level' => $level,
                    'amount' => $bonus,
                ]);

                // Você pode também adicionar diretamente na carteira do usuário aqui
                $sponsor->increment('balance', $bonus);
            }

            $sponsor = $sponsor->sponsor;
            $level++;
        }
    }
}
