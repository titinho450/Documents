<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Mail\PasswordResetMail;

class PasswordResetController extends Controller
{
    /**
     * Enviar link de recuperação de senha
     */
    public function sendResetLink(Request $request)
    {
        try {
            // Validação dos dados
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email'
            ], [
                'email.required' => 'O campo email é obrigatório.',
                'email.email' => 'Por favor, insira um email válido.',
                'email.exists' => 'Este email não está cadastrado em nosso sistema.'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $email = $request->email;

            // Buscar o usuário
            $user = User::where('email', $email)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado.'
                ], 404);
            }

            // Gerar token único
            $token = Str::random(64);

            // Remover tokens antigos para este email
            DB::table('password_reset_tokens')
                ->where('email', $email)
                ->delete();

            // Inserir novo token
            DB::table('password_reset_tokens')->insert([
                'email' => $email,
                'token' => $token,
                'created_at' => Carbon::now()
            ]);

            // Enviar email
            Mail::to($email)->send(new PasswordResetMail($user, $token));

            return response()->json([
                'success' => true,
                'message' => 'Link de recuperação enviado para seu email. Verifique sua caixa de entrada e spam.',
                'data' => [
                    'email' => $email,
                    'sent_at' => Carbon::now()->format('d/m/Y H:i:s')
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor. Tente novamente mais tarde.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Verificar se o token é válido
     */
    public function verifyToken(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'token' => 'required|string',
                'email' => 'required|email'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos.',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar se o token existe e não expirou (60 minutos)
            $tokenData = DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->where('token', $request->token)
                ->first();

            if (!$tokenData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token inválido ou expirado.'
                ], 400);
            }

            // Verificar se não expirou (60 minutos)
            $tokenAge = Carbon::parse($tokenData->created_at)->diffInMinutes(Carbon::now());

            if ($tokenAge > 60) {
                // Remover token expirado
                DB::table('password_reset_tokens')
                    ->where('email', $request->email)
                    ->delete();

                return response()->json([
                    'success' => false,
                    'message' => 'Token expirado. Solicite um novo link de recuperação.'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Token válido.',
                'data' => [
                    'email' => $request->email,
                    'expires_in_minutes' => 60 - $tokenAge
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Redefinir senha
     */
    public function resetPassword(Request $request)
    {
        try {
            // Validação
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'token' => 'required|string',
                'password' => 'required|string|min:8|confirmed',
                'password_confirmation' => 'required|string'
            ], [
                'email.required' => 'O campo email é obrigatório.',
                'email.email' => 'Por favor, insira um email válido.',
                'token.required' => 'Token é obrigatório.',
                'password.required' => 'A senha é obrigatória.',
                'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
                'password.confirmed' => 'A confirmação da senha não confere.',
                'password_confirmation.required' => 'A confirmação da senha é obrigatória.'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos.',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar token
            $tokenData = DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->where('token', $request->token)
                ->first();

            if (!$tokenData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token inválido ou expirado.'
                ], 400);
            }

            // Verificar se não expirou
            $tokenAge = Carbon::parse($tokenData->created_at)->diffInMinutes(Carbon::now());

            if ($tokenAge > 60) {
                DB::table('password_reset_tokens')
                    ->where('email', $request->email)
                    ->delete();

                return response()->json([
                    'success' => false,
                    'message' => 'Token expirado. Solicite um novo link de recuperação.'
                ], 400);
            }

            // Buscar usuário
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado.'
                ], 404);
            }

            // Atualizar senha
            $user->update([
                'password' => Hash::make($request->password)
            ]);

            // Remover token usado
            DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->delete();

            // Log da atividade (opcional)
            \Log::info('Password reset successful', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
                'timestamp' => Carbon::now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Senha redefinida com sucesso! Você já pode fazer login com sua nova senha.',
                'data' => [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'reset_at' => Carbon::now()->format('d/m/Y H:i:s')
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Listar tentativas de reset (apenas para admin)
     */
    public function getResetAttempts(Request $request)
    {
        try {
            // Verificar se é admin (adicione sua lógica de autorização)
            // if (!auth()->user()->isAdmin()) {
            //     return response()->json(['success' => false, 'message' => 'Não autorizado.'], 403);
            // }

            $attempts = DB::table('password_reset_tokens')
                ->select('email', 'created_at')
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'message' => 'Tentativas de reset recuperadas.',
                'data' => $attempts
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Limpar tokens expirados (comando artisan ou cron)
     */
    public function cleanExpiredTokens()
    {
        try {
            $expiredCount = DB::table('password_reset_tokens')
                ->where('created_at', '<', Carbon::now()->subHours(1))
                ->count();

            DB::table('password_reset_tokens')
                ->where('created_at', '<', Carbon::now()->subHours(1))
                ->delete();

            return response()->json([
                'success' => true,
                'message' => "Limpeza concluída. {$expiredCount} tokens expirados removidos.",
                'data' => [
                    'removed_count' => $expiredCount,
                    'cleaned_at' => Carbon::now()->format('d/m/Y H:i:s')
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro na limpeza de tokens.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}
