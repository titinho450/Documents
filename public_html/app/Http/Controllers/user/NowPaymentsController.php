<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Transaction;
use App\Services\NowPayments\NowPaymentsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class NowPaymentsController extends Controller
{
    protected NowPaymentsService $nowPayments;

    public function __construct(NowPaymentsService $nowPayments)
    {
        $this->nowPayments = $nowPayments;
    }

    /**
     * Criar depósito USDT
     */
    public function createDeposit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:' . setting('minimum_deposit'), 'max:' . setting('maximum_deposit')],
        ], [
            'amount.min' => 'O valor mínimo para depósito é de $' . setting('minimum_deposit'),
            'amount.max' => 'O valor máximo para depósito é de $' . setting('maximum_deposit'),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = Auth::user();
        $amount = $request->input('amount');

        $result = $this->nowPayments->createDeposit($user, $amount);

        if ($result['success']) {
            $deposit = new Deposit([
                'user_id' => $user->id,
                'method_name' => 'USDT',
                'address' => "NowPayment",
                'order_id' => $result['transaction']['order_id'],
                'amount' => (float) $amount,
                'transaction_id' => $result['transaction']['payment_id'],
                'date' => Carbon::now(),
                'status' => 'pending',
            ]);

            $deposit->save();

            return response()->json([
                'success' => true,
                'message' => 'Depósito criado com sucesso',
                'data' => [
                    'payment_id' => $result['data']['payment_id'],
                    'payment_url' => $result['data']['invoice_url'] ?? null,
                    'payment_address' => $result['data']['pay_address'] ?? null,
                    'payment_amount' => $result['data']['pay_amount'] ?? null,
                    'qr_code' => $result['data']['qr_code'] ?? null,
                    'transaction_id' => $result['transaction']->id,
                ]
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['error'] ?? 'Erro ao criar depósito'
        ], 400);
    }

    public function depositIpn(Request $request)
    {
        $data = $request->json()->all();

        Log::channel('nowpay_webhook')->info('Payload recebido: ' . json_encode($data, JSON_PRETTY_PRINT));

        return $this->nowPayments->processDepositIPN($data);
    }
}
