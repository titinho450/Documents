<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use App\Models\Checkin;
use App\Models\Commission;
use App\Models\Deposit;
use App\Models\Improvment;
use App\Models\LuckyLedger;
use App\Models\Purchase;
use App\Models\Rebate;
use App\Models\User;
use App\Models\UserLedger;
use App\Models\Withdrawal;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TeamController extends Controller
{
    //
    public function team()
    {
        $user = Auth::user();

        //First Level Users
        $first_level_users = User::where('ref_by', $user->ref_id)->get();
        $first_level_users_ids = [];
        foreach ($first_level_users as $user) {
            array_push($first_level_users_ids, $user->id);
        }

        //Second Level Users
        $second_level_users_ids = [];
        foreach ($first_level_users as $element) {
            $users = User::where('ref_by', $element->ref_id)->get();
            foreach ($users as $user) {
                array_push($second_level_users_ids, $user->id);
            }
        }
        $second_level_users = User::whereIn('id', $second_level_users_ids)->get();

        //Third Level Users
        $third_level_users_ids = [];
        foreach ($second_level_users as $element) {
            $users = User::where('ref_by', $element->ref_id)->get();
            foreach ($users as $user) {
                array_push($third_level_users_ids, $user->id);
            }
        }
        $third_level_users = User::whereIn('id', $third_level_users_ids)->get();
        $team_size = $first_level_users->count() + $second_level_users->count() + $third_level_users->count();

        //Get level wise user ids
        $first_ids = $first_level_users->pluck('id'); //first
        $second_ids = $second_level_users->pluck('id'); //Second
        $third_ids = $third_level_users->pluck('id'); //Third

        $lv1Recharge = Deposit::whereIn('user_id', $first_ids)->where('status', 'approved')->sum('amount');
        $lv2Recharge = Deposit::whereIn('user_id', $second_ids)->where('status', 'approved')->sum('amount');
        $lv3Recharge = Deposit::whereIn('user_id', $third_ids)->where('status', 'approved')->sum('amount');
        $lvTotalDeposit = $lv1Recharge + $lv2Recharge + $lv3Recharge;

        $lv1Withdraw = Withdrawal::whereIn('user_id', $first_ids)->where('status', 'approved')->sum('amount');
        $lv2Withdraw = Withdrawal::whereIn('user_id', $second_ids)->where('status', 'approved')->sum('amount');
        $lv3Withdraw = Withdrawal::whereIn('user_id', $third_ids)->where('status', 'approved')->sum('amount');
        $lvTotalWithdraw = $lv1Withdraw + $lv2Withdraw + $lv3Withdraw;

        $activeMembers1 = Deposit::whereIn('user_id', $first_ids)->where('status', 'approved')->groupBy('user_id')->count();
        $activeMembers2 = Deposit::whereIn('user_id', $second_ids)->where('status', 'approved')->groupBy('user_id')->count();
        $activeMembers3 = Deposit::whereIn('user_id', $third_ids)->where('status', 'approved')->groupBy('user_id')->count();

        $totalInvestment = Purchase::whereIn('user_id', array_merge($first_ids->toArray(), $second_ids->toArray(), $third_ids->toArray()))->sum('amount');
        $totalLevelInvest1 = Purchase::whereIn('user_id', array_merge($first_ids->toArray()))->sum('amount');
        $totalLevelInvest2 = Purchase::whereIn('user_id', array_merge($second_ids->toArray()))->sum('amount');
        $totalLevelInvest3 = Purchase::whereIn('user_id', array_merge($third_ids->toArray()))->sum('amount');

        $levelTotalCommission1 = UserLedger::where('user_id', \auth()->id())->where('reason', 'commission')->where('step', 'first')->sum('amount');
        $levelTotalCommission2 = UserLedger::where('user_id', \auth()->id())->where('reason', 'commission')->where('step', 'second')->sum('amount');
        $levelTotalCommission3 = UserLedger::where('user_id', \auth()->id())->where('reason', 'commission')->where('step', 'third')->sum('amount');
        $rebates = Rebate::first();

        return view('app.main.team.index', compact(
            'rebates',
            'first_level_users',
            'second_level_users',
            'third_level_users',
            'team_size',
            'lvTotalDeposit',
            'lvTotalWithdraw',
            'lv1Recharge',
            'lv2Recharge',
            'lv3Recharge',
            'lv1Withdraw',
            'lv2Withdraw',
            'lv3Withdraw',
            'activeMembers1',
            'activeMembers2',
            'activeMembers3',
            'totalInvestment',
            'levelTotalCommission1',
            'levelTotalCommission2',
            'levelTotalCommission3',
            'totalLevelInvest1',
            'totalLevelInvest2',
            'totalLevelInvest3',
        ));
    }

    public function team_details($generation)
    {
        $user = Auth::user();

        //First Level Users
        $first_level_users = User::where('ref_by', $user->ref_id)->orderByDesc('id')->get();
        $first_level_users_ids = [];
        foreach ($first_level_users as $user) {
            array_push($first_level_users_ids, $user->id);
        }

        //Second Level Users
        $second_level_users_ids = [];
        foreach ($first_level_users as $element) {
            $users = User::where('ref_by', $element->ref_id)->get();
            foreach ($users as $user) {
                array_push($second_level_users_ids, $user->id);
            }
        }
        $second_level_users = User::whereIn('id', $second_level_users_ids)->orderByDesc('id')->get();

        //Third Level Users
        $third_level_users_ids = [];
        foreach ($second_level_users as $element) {
            $users = User::where('ref_by', $element->ref_id)->get();
            foreach ($users as $user) {
                array_push($third_level_users_ids, $user->id);
            }
        }
        $third_level_users = User::whereIn('id', $third_level_users_ids)->orderByDesc('id')->get();


        if ($generation == 1) {
            $users = $first_level_users;
        }
        if ($generation == 2) {
            $users = $second_level_users;
        }
        if ($generation == 3) {
            $users = $third_level_users;
        }

        return  view('app.main.team.team_details', compact('users', 'generation'));
    }
}
