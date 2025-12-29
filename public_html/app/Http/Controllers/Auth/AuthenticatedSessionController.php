<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Deposit;
use App\Models\User;
use App\Models\UserLedger;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create()
    {
        return view('app.main.index');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(Request $request)
    {

        if ($request->auth == '' || $request->auth == null || $request->password == '') {
            return redirect()->back()->with('error', 'Incorrect phone number and password');
        }

        $user = User::where('phone', $request->auth)->orWhere('email', $request->auth)->first();

        if (Auth::check()) {
            return redirect()->route('dashboard');
        }


        if (!$user) {
            return redirect()->back()->with('error', 'Incorrect phone number and password');
        }

        //Check user ban or unban
        if ($user->ban_unban == 'ban') {
            return redirect()->back()->with('error', 'Your account has been..');
        }

        if ($user) {
            if (Hash::check($request->password, $user->password)) {
                Auth::login($user);
                return redirect()->route('dashboard');
            } else {
                return redirect()->back()->with('error', 'Your password is incorrect.');
            }
        } else {
            return redirect()->back()->with('error', 'Incorrect phone number and password');
        }
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
