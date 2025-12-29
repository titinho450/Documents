<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\User\StoreBinaryTradeRequest;
use App\Http\Resources\V1\BinaryTradeResource;
use App\Models\BinaryTrade;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BinaryTradeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $trades = $request->user()->binaryTrades()->latest()->paginate(15);
        return BinaryTradeResource::collection($trades)->response();
    }

    public function store(StoreBinaryTradeRequest $request): JsonResponse
    {
        $user = $request->user();
        $amount = $request->input('amount_cents');

        if ($user->balance_cents < $amount) {
            return response()->json(['message' => 'Saldo insuficiente.'], 400);
        }

        DB::beginTransaction();
        try {
            $trade = $user->binaryTrades()->create([
                'amount_cents' => $amount,
                'direction' => $request->input('direction'),
                'expires_at' => now()->addMinutes(1),
            ]);

            WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'debit',
                'amount_cents' => $amount,
                'status' => 'pending',
                'reference_type' => BinaryTrade::class,
                'reference_id' => $trade->id,
            ]);

            // Reduz o saldo de forma "pending" para evitar uso duplo
            $user->subtractBalance($amount);

            DB::commit();

            return (new BinaryTradeResource($trade))->response()->setStatusCode(201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Erro ao criar operação: ' . $e->getMessage()], 500);
        }
    }
}
