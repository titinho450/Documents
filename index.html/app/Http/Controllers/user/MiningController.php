<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use App\Models\Rebate;
use App\Models\User;
use App\Models\Purchase;
use App\Models\UserLedger;
use Illuminate\Support\Facades\Auth;

class MiningController extends Controller
{
    public function received_amount()
    {
        $user = Auth::user();
        if ($user->receive_able_amount > 0){
            $uu = User::where('id', $user->id)->first();
            $uu->balance = $user->balance + $user->receive_able_amount;

            $ledger = new UserLedger();
            $ledger->user_id = $user->id;
            $ledger->reason = 'daily_income';
            $ledger->perticulation = 'Commission Received.';
            $ledger->amount = $user->receive_able_amount;
            $ledger->credit = $user->receive_able_amount;
            $ledger->status = 'approved';
            $ledger->date = date("Y-m-d H:i:s");
            $ledger->save();

            $uu->receive_able_amount = 0;
            $uu->save();

            return response()->json(['status'=> true, 'message'=> 'Commission Received.'.price($uu->receive_able_amount), 'balance'=> price($uu->receive_able_amount)]);
        }else{
            return response()->json(['status'=> true, 'message'=> 'Earnings will be added immediately after 24-hours.', 'balance'=> price($user->receive_able_amount)]);
        }
    }
}








