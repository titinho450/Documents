<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Config;

class AdminApiMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $admin = Auth::guard('admin')->user();

        if (!$admin || $admin->type !== 'admin') {
            Config::set('session.cookie', 'admin_session');
            return response()->json([
                'success' => false,
                'message' => 'Acesso restrito a administradores',
                'errors' => [
                    'authorization' => ['Acesso restrito a administradores']
                ]
            ], 403);
        }

        return $next($request);
    }
}
