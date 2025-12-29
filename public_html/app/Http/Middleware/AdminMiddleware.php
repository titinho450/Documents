<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Para rotas de API
        if ($request->is('api/*')) {
            // Verifique o token ou método de autenticação API aqui
            $admin = Auth::guard('admin')->user();

            if (!$admin || $admin->type !== 'admin') {
                return response()->json(['error' => 'Acesso restrito a administradores'], 403);
            }

            return $next($request);
        }

        // Para rotas web (mantenha seu código atual)
        $admin = Auth::guard('admin')->user();

        if ($request->routeIs('admin.login') || $request->routeIs('admin.login-submit')) {
            return $next($request);
        }

        if (!$admin || $admin->type !== 'admin') {
            Auth::guard('admin')->logout();
            return response()->json(['error' => 'Acesso restrito a administradores'], 403);
        }

        return $next($request);
    }
}
