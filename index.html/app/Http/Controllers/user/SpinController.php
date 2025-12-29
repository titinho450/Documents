<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use App\Models\Bonus;
use App\Models\BonusLedger;
use App\Models\User;
use App\Models\UserLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SpinController extends Controller
{
    public function spin()
    {
        return view('app.main.spin.index');
    }

    public function spin_history()
    {
        return view('app.main.spin_history');
    }

    public function submitbonuscheck($code)
    {
        $bonus = Bonus::where('status', 'active')->first();
        $user = Auth::user();
        if ($bonus) {
            if ($code == $bonus->code) {
                //Check this bonus use this user.
                $checkBonusUses = BonusLedger::where('bonus_id',$bonus->id )->where('user_id', $user->id)->first();
                if ($checkBonusUses){
                    return response()->json(['status' => false, 'message' => 'Invalid Code.']);
                }
                if ($bonus->counter < $bonus->set_service_counter) {
                    return response()->json(['status' => true]);
                } else {
                    return response()->json(['status' => false, 'message' => 'targeted fulfil.']);
                }
            } else {
                return response()->json(['status' => false, 'message' => 'Code invalid.']);
            }
        } else {
            return response()->json(['status' => false, 'message' => 'Not available.']);
        }
    }



    public function submitbonusamount(Request $request)
    {
        $bonus = Bonus::where('status', 'active')->first();
        $user = Auth::user();
        if ($bonus) {
            $code = $request->code;
            if ($code == $bonus->code) {
                //Check this bonus use this user.
                $checkBonusUses = BonusLedger::where('bonus_id',$bonus->id )->where('user_id', $user->id)->first();
                if ($checkBonusUses){
                    return response()->json(['status' => false, 'message' => 'Do not use again this code']);
                }

                else{
                    $amount = $bonus->amount;
                }

                if ($bonus->counter < $bonus->set_service_counter) {
                    User::where('id', $user->id)->update([
                        'balance'=> $user->balance + $amount,
                    ]);

                    //User Ledger
                    $ledger = new UserLedger();
                    $ledger->user_id = $user->id;
                    $ledger->reason = 'Claim';
                    $ledger->perticulation = 'Congratulations '.$user->name. ' you are successfully get your bonus.';
                    $ledger->amount = $amount;
                    $ledger->debit = $amount;
                    $ledger->status = 'approved';
                    $ledger->date = date('d-m-Y H:i');
                    $ledger->save();

                    Bonus::where('id', $bonus->id)->update([
                        'counter'=> $bonus->counter + 1
                    ]);

                    $bonus_ledger = new BonusLedger();
                    $bonus_ledger->user_id = $user->id;
                    $bonus_ledger->bonus_id = $bonus->id;
                    $bonus_ledger->bonus_code = $code;
                    $bonus_ledger->amount = $amount;
                    $bonus_ledger->save();

                    return response()->json(['status' => true, 'message' => 'Received '. price($amount) ]);
                } else {
                    return response()->json(['status' => false, 'message' => 'Try next.']);
                }
            } else {
                return response()->json(['status' => false, 'message' => 'Code is invalid.']);
            }
        } else {
            return response()->json(['status' => false, 'message' => 'Not Yet ready to use.']);
        }
    }
}
