<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GatewayMethod;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class GatewayMethodController extends Controller
{
    public function index()
    {
        $admin = Auth::guard('admin')->user();

        $token = $admin->createToken('Admin Token', ['*'])->plainTextToken;
        return view('admin.pages.gateway.gateway_method', compact('token'));
    }

    public function find($id)
    {
        $gateway = GatewayMethod::find($id);

        return response()->json($gateway, 200);
    }

    public function delete($id)
    {
        $gateway = GatewayMethod::find($id);

        if (!$gateway) {
            return response()->json(['error' => 'Gateway não encontrado'], 404);
        }

        $gateway->delete();

        return response()->json(['message' => 'Gateway excluído com sucesso'], 200);
    }

    public function list()
    {
        $gateways = GatewayMethod::get();

        return response()->json($gateways, 200);
    }

    public function store(Request $request)
    {
        try {
            // Validação utilizando o sistema do Laravel
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|min:3|max:100',
                'api_key' => [
                    'nullable',
                    'string',
                    'min:5',
                    'max:100',
                    'unique:gateway_methods'
                ],
                'client_id' => [
                    'nullable',
                    'string',
                    'min:5',
                    'max:100',
                    'unique:gateway_methods'
                ],
                'client_secret' => [
                    'nullable',
                    'string',
                    'min:5',
                    'max:100',
                    'unique:gateway_methods'
                ]
            ], [
                'name.required' => 'O nome do gateway é obrigatório',
                'nome.min' => 'O nome deve ter pelo menos 3 caracteres',
                'api_key.min' => 'Api Key inválida',
                'api_key.max' => 'Api Key inválida',
                'client_id.min' => 'Client ID inválido',
                'client_id.max' => 'Client ID inválido',
                'client_secret.min' => 'Client ID inválido',
                'client_secret.max' => 'Client ID inválido',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Erro de validação',
                    'errors' => $validator->errors()
                ], 422);
            }

            $hasActive = GatewayMethod::where('status', 'active')->first();

            $gateway = new GatewayMethod();
            $gateway->name = $request->name;
            $gateway->api_key = $request->api_key;
            $gateway->client_id = $request->client_id;
            $gateway->client_secret = $request->client_secret;
            $gateway->status = $hasActive ? "inactive" : "active";

            $gateway->save();

            return response()->json($gateway, 200);
        } catch (\Exception $e) {
            throw new Exception($e);
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
