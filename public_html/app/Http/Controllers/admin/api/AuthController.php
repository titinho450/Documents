<?php

namespace App\Http\Controllers\admin\api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login de administrador via API
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws ValidationException
     */
    public function login(Request $request)
    {
        // Validar os dados de entrada
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ], [
            'email.required' => 'Informe seu e-mail',
            'email.email' => 'Informe um e-mail válido',
            'password.required' => 'Informe sua senha',
            'password.string' => 'Informe uma senha válida'
        ]);

        // Buscar o administrador pelo email
        $admin = Admin::where('email', $credentials['email'])->first();

        // Verificar se o admin existe e a senha está correta
        if (!$admin || !Hash::check($credentials['password'], $admin->password)) {
            throw ValidationException::withMessages([
                'email' => ['As credenciais fornecidas estão incorretas.'],
            ]);
        }


        // Revogar tokens anteriores (opcional, mas recomendado por segurança)
        $admin->tokens()->delete();


        // Criar um novo token com escopo de administrador
        $token = $admin->createToken('admin-token', ['admin'])->plainTextToken;

        // Retornar resposta com token e informações do usuário
        return response()->json([
            'status' => 'success',
            'message' => 'Login realizado com sucesso',
            'token' => $token,
            'data' => $admin
        ], 200);
    }

    /**
     * Logout de administrador
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        // Revogar o token atual do usuário
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'success' => true,
            'message' => 'Logout realizado com sucesso',
        ], 200);
    }

    /**
     * Obter informações do usuário autenticado
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => $request->user('admin-api'),
            ]
        ], 200);
    }
}
