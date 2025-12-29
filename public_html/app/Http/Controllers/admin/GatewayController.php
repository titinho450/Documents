<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\Gateway;
use App\Models\GatewayCredential;
use Illuminate\Http\Request;

class GatewayController extends Controller
{
    public function index()
    {
        $gateways = Gateway::with('credentials')->get();
        return view('admin.gateways.index', compact('gateways'));
    }

    public function create()
    {
        return view('admin.gateways.create');
    }

    public function store(Request $request)
    {
        $gateway = Gateway::create($request->only('name', 'slug', 'active'));

        foreach ($request->input('credentials', []) as $key => $value) {
            $gateway->credentials()->create([
                'key' => $key,
                'value' => $value,
            ]);
        }

        return redirect()->route('admin.gateway.manager')->with('success', 'Gateway criado com sucesso.');
    }

    public function edit(Gateway $gateway)
    {
        return view('admin.gateways.edit', compact('gateway'));
    }

    public function update(Request $request, Gateway $gateway)
    {


        $gateway->update($request->only('name', 'slug', 'active'));

        $gateway->credentials()->delete();
        foreach ($request->input('credentials', []) as $key => $value) {
            $gateway->credentials()->create([
                'key' => $key,
                'value' => $value,
            ]);
        }

        return redirect()->route('admin.gateway.manager')->with('success', 'Gateway atualizado com sucesso.');
    }

    public function destroy(Gateway $gateway)
    {
        $gateway->delete();
        return back()->with('success', 'Gateway removido.');
    }

    public function toogleStatus(Gateway $gateway)
    {
        $gateway->active = !$gateway->active;
        $gateway->save();
        return back()->with('success', 'Gateway atualizado com sucesso.');
    }

    // ... seus outros métodos existentes ...

    // Outros métodos do controller...

    /**
     * Atualiza as credenciais do gateway
     */
    public function updateCredentials(Request $request, Gateway $gateway)
    {
        // Verificar se a requisição espera JSON
        $expectsJson = $request->ajax() || $request->wantsJson();

        // Se os dados foram enviados via AJAX com JSON
        if ($request->has('credentials') && is_string($request->credentials)) {
            // Decodificar o JSON para array
            $credentials = json_decode($request->credentials, true);

            if (!is_array($credentials)) {
                if ($expectsJson) {
                    return response()->json(['error' => 'Formato de credenciais inválido'], 400);
                }
                return redirect()->back()->with('error', 'Formato de credenciais inválido');
            }
        } else {
            // Formato tradicional do formulário
            $credentials = $request->input('credentials', []);
        }

        // Log para debug
        \Log::info('Credenciais recebidas: ', ['count' => count($credentials), 'data' => $credentials]);

        // Excluir todas as credenciais existentes
        $gateway->credentials()->delete();

        // Criar novas credenciais
        foreach ($credentials as $cred) {
            if (!empty($cred['key']) && isset($cred['value'])) {
                $gateway->credentials()->create([
                    'key' => $cred['key'],
                    'value' => $cred['value']
                ]);
            }
        }

        if ($expectsJson) {
            return response()->json([
                'success' => true,
                'message' => 'Credenciais atualizadas com sucesso!'
            ]);
        }

        return redirect()->route('admin.gateway.index')->with('success', 'Credenciais atualizadas com sucesso!');
    }
}
