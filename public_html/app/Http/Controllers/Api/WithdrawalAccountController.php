<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WithdrawalAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Piggly\Pix\Exceptions\InvalidPixKeyException;
use Piggly\Pix\Exceptions\InvalidPixKeyTypeException;
use Piggly\Pix\Parser;

class WithdrawalAccountController extends Controller
{
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'full_name' => 'required|string|max:255',
                'cpf' => 'required|string|max:14',
                'phone' => 'required|string|max:20',
                'pix_key_type' => 'required|in:CPF,EMAIL,PHONE,RANDOM',
                'pix_key' => 'required|string|max:255',
                'is_default' => 'boolean'
            ]);

            if ($validator->fails()) {
                Log::info('ERRO AO CADASTRAR CONTA DE SAQUE. DADOS: ' . json_encode($validator->errors(), JSON_PRETTY_PRINT));
                return response()->json(['errors' => $validator->errors()], 422);
            }

            // Se esta conta for definida como padrão, remove o padrão das outras contas
            if ($request->input('is_default', true)) { // Por padrão, primeira conta será a padrão
                WithdrawalAccount::where('user_id', auth()->id())
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $cpf = preg_replace('/\D/', '', $request->cpf);
            $pix_key = $request->pix_key;

            if ($request->pix_key_type === 'CPF' || $request->pix_key_type === 'PHONE') {
                $pix_key = preg_replace('/\D/', '', $pix_key);
            }

            $pixType = $request->pix_key_type === 'CPF' ? 'document' : $request->pix_key_type;
            Parser::validate(strtolower($pixType), $pix_key);

            $account = WithdrawalAccount::create([
                'user_id' => auth()->id(),
                'cpf' => $cpf,
                'pix_key' => strtolower($pix_key),
                ...$validator->validated(),
                'is_default' => $request->input('is_default', true)
            ]);

            return response()->json([
                'message' => 'Conta PIX cadastrada com sucesso',
                'data' => $account
            ], 201);
        } catch (InvalidPixKeyTypeException | InvalidPixKeyException $e) {
            // Captura as exceções específicas de chave PIX
            Log::error("[WITHDRAW_ACCOUNT]: CHAVE PIX INVÁLIDA", [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'msg' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_details' => $e->getMessage()
            ], 422);
        } catch (\Exception $e) {
            Log::info('ERRO AO CADASTRAR CONTA DE SAQUE. DADOS: ' . $e->getMessage());
        }
    }

    public function update(Request $request, WithdrawalAccount $withdrawalAccount)
    {
        try {
            // Verifica se a conta pertence ao usuário autenticado
            if ($withdrawalAccount->user_id !== auth()->id()) {
                return response()->json(['message' => 'Não autorizado'], 403);
            }

            $validator = Validator::make($request->all(), [
                'full_name'      => 'string|max:255',
                'cpf'            => 'string|max:14',
                'phone'          => 'string|max:20',
                'pix_key_type'   => 'in:CPF,EMAIL,PHONE,RANDOM',
                'pix_key'        => 'string|max:255',
                'is_default'     => 'boolean',
                'status'         => 'in:active,inactive'
            ]);

            if ($validator->fails()) {
                Log::info('ERRO AO ATUALIZAR CONTA DE SAQUE. DADOS: ' . json_encode($validator->errors(), JSON_PRETTY_PRINT));
                return response()->json(['errors' => $validator->errors()], 422);
            }

            // Se esta conta for definida como padrão, remove o padrão das outras contas
            if ($request->input('is_default')) {
                WithdrawalAccount::where('user_id', auth()->id())
                    ->where('id', '!=', $withdrawalAccount->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $data = $validator->validated();

            // Limpar CPF
            if (isset($data['cpf'])) {
                $data['cpf'] = preg_replace('/\D/', '', $data['cpf']);
            }

            // Limpar Pix se for CPF ou PHONE
            if (isset($data['pix_key']) && isset($data['pix_key_type'])) {
                if ($data['pix_key_type'] === 'CPF' || $data['pix_key_type'] === 'PHONE') {
                    $data['pix_key'] = preg_replace('/\D/', '', $data['pix_key']);
                }
            }

            $pixType = $data['pix_key_type'] === 'CPF' ? 'document' : $data['pix_key_type'];

            Parser::validate(strtolower($pixType), $data['pix_key']);

            $data['pix_key'] = strtolower($data['pix_key']);

            $withdrawalAccount->update($data);

            return response()->json([
                'message' => 'Conta PIX atualizada com sucesso',
                'data' => $withdrawalAccount
            ]);
        } catch (InvalidPixKeyTypeException | InvalidPixKeyException $e) {
            // Captura as exceções específicas de chave PIX
            Log::error("[WITHDRAW_ACCOUNT]: CHAVE PIX INVÁLIDA", [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'msg' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_details' => $e->getMessage()
            ], 422);
        } catch (\Exception $e) {
            Log::info('ERRO AO ATUALIZAR CONTA DE SAQUE. DADOS: ' . $e->getMessage());
        }
    }
}
