<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SettleBinaryTradeRequest;
use App\Http\Resources\V1\BinaryTradeResource;
use App\Models\BinaryTrade;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminBinaryTradeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $trades = BinaryTrade::where('status', 'pending')
            ->latest()
            ->paginate(15);

        return BinaryTradeResource::collection($trades)->response();
    }

    public function settle(SettleBinaryTradeRequest $request, BinaryTrade $trade): JsonResponse
    {
        // Garante que o trade está pendente antes de ser resolvido
        if ($trade->status !== 'pending') {
            return response()->json(['message' => 'Esta operação já foi resolvida.'], 400);
        }

        $admin = $request->user();
        $result = $request->input('result');

        DB::beginTransaction();
        try {
            $trade->update([
                'status' => $result,
                'result_decided_by_admin_id' => $admin->id,
                'settled_at' => now(),
            ]);

            // Encontra a transação de débito pendente
            $debitTransaction = $trade->transaction()->firstOrFail();
            $debitTransaction->update(['status' => 'completed']);

            // Lógica de ajuste de saldo
            if ($result === 'won') {
                $payout = $trade->amount_cents * 2; // Ex: payout de 100%
                $trade->user->addBalance($payout);
                WalletTransaction::create([
                    'user_id' => $trade->user->id,
                    'type' => 'credit',
                    'amount_cents' => $payout,
                    'status' => 'completed',
                    'reference_type' => BinaryTrade::class,
                    'reference_id' => $trade->id,
                ]);
            } elseif ($result === 'draw') {
                // Se for empate, reembolsa o valor
                $trade->user->addBalance($trade->amount_cents);
                $debitTransaction->update(['status' => 'cancelled']);
            }

            // Log de ação do admin
            // AdminAction::create([...]);

            DB::commit();

            return response()->json(['message' => 'Resultado da operação definido com sucesso.', 'trade' => new BinaryTradeResource($trade)]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao resolver operação: ' . $e->getMessage(), ['trade_id' => $trade->id, 'admin_id' => $admin->id]);
            return response()->json(['message' => 'Erro interno ao processar a requisição.'], 500);
        }
    }
}
