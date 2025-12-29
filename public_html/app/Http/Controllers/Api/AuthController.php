<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    /**
     * Handle user login attempt
     *
     * @param LoginRequest $request
     * @return JsonResponse
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();

            return response()->json([
                'status' => 'success',
                'message' => 'Login realizado com sucesso',
                'data' => [
                    'user' => Auth::user()
                ],
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Credenciais inválidas',
        ], 401);
    }

    /**
     * Handle validate token
     *
     * @return JsonResponse
     */
    public function validate(): JsonResponse
    {
        try {
            $user = auth()->user();

            if ($user) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Login realizado com sucesso',
                    'data' => [
                        'user' => [
                            'id' => $user->id,
                            'name' => $user->name,
                            'phone' => $user->phone,
                            'email' => $user->email,
                        ],
                    ],
                ], 200);
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Erro de validação',
                    'errors' => $e->errors(),
                ], 422);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erro ao validar token',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Logout user and revoke token
     *
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'status' => 'success',
                'message' => 'Logout realizado com sucesso',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erro ao realizar logout',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
