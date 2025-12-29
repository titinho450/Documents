<?php

namespace App\Policies;

use App\Models\BinaryTrade;
use App\Models\User;

class BinaryTradePolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, BinaryTrade $binaryTrade): bool
    {
        return $user->id === $binaryTrade->user_id;
    }
}
