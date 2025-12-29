<?php

namespace App\Http\Controllers\admin\api;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Withdraw;
use App\Models\Withdrawal;
use App\Services\DigitoPay\DigitoPayService;
use App\Services\NowPayments\NowPaymentsService;
use App\Services\PayOne\PayOneService;
use App\Services\SyncPay\SyncPay;
use App\Services\SyncPayment\SyncPaymentService;
use App\Services\VizionPay\VizionPayException;
use App\Services\VizionPay\VizionPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WithdrawController extends Controller
{
    public function __construct(
        private SyncPay $syncpay,
        private PayOneService $payOne,
        private VizionPayService $vizionPay,
        private DigitoPayService $digitoPay,
        private NowPaymentsService $nowpayment,
        private SyncPaymentService $syncPayment,
    ) {}


    public function approveWithdraw(Withdrawal $withdraw, Request $request): JsonResponse
    {
        try {
            if (!$withdraw || $withdraw->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Saque não identificado ou já processado'
                ], 400);
            }

            $user = $withdraw->user;

            if ($withdraw->method_name == 'usdt') {
                $withdraw->status = TransactionStatus::PROCESSING;
                $withdraw->save();

                return response()->json([
                    'success' => true,
                    'data' => $withdraw,
                    'message' => 'Saque aprovado com sucesso!'
                ], 200);
            } else {
                $withdrawAccount = $user->withdrawAccount;

                // $dolar = $settingController->getDolarValue();

                // $finalAmount = $withdraw->amount * $dolar['dollar_with_iof'];

                // $valueInUSD = round($withdraw->final_amount * $dolar['dollar_value'], 2);

                $saque = $this->syncPayment->cashOut([
                    'amount' => (float) $withdraw->final_amount,
                    'pix_type' => $withdrawAccount->pix_key_type,
                    'pix_key' => $withdrawAccount->pix_key,
                    'name' => $withdrawAccount->full_name,
                    'document' => $withdrawAccount->cpf,
                    'externalreference' => $this->transactionService->generateUUid(),
                ]);

                $message = "O valor de R$" . $withdraw->final_amount . " foi enviado á sua conta PIX";

                if (!$saque['data']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Estamos passando por instabilidade em nosso gateway de pagamento'
                    ], 400);
                }

                $idTransaction = $saque['data']['idTransaction'];
            }

            $withdraw->transaction_id = $idTransaction;
            $withdraw->status = TransactionStatus::PROCESSING;
            $withdraw->save();

            Log::channel('webhook')->info('SOLICITAÇÃO DE APROVAÇÃO DE SAQUE EXECUTADA' . json_encode($withdraw, JSON_PRETTY_PRINT));

            $pusher = new \Pusher\Pusher(
                config('broadcasting.connections.pusher.key'),
                config('broadcasting.connections.pusher.secret'),
                config('broadcasting.connections.pusher.app_id'),
                config('broadcasting.connections.pusher.options')
            );

            $pusher->trigger('chanel-user-' . $user->id, 'paid', [
                'user_id' => $user->id,
                'message' => $message,
                'user' => $user,
                'timestamp' => now(),
                'type' => 'info'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Saque efetuado com sucesso!',
                'data' => $withdraw
            ],);
        } catch (VizionPayException $v) {
            Log::channel('vizion_paid_out')->error("Erro ao aprovar saque: " . json_encode([
                'line' => $v->getLine(),
                'file' => $v->getFile(),
                'error' => $v->getMessage(),
                'trace' => $v->getTraceAsString()
            ], JSON_PRETTY_PRINT));
            return response()->json([
                'success' => false,
                'message' => 'Erro com a vizzion pay',
                'details' => $v->getMessage()
            ], 500);
        } catch (\Exception $e) {
            \Log::error('Erro ao alterar saque:', [
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erro no processamento de saque',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function statistics()
    {
        $totalAmount = Withdrawal::sum('amount');
        $totalAprroved = Withdrawal::where('status', TransactionStatus::APPROVED)->sum('amount');
        $totalPending = Withdrawal::where('status', TransactionStatus::PENDING)->sum('amount');
        $totalRejected = Withdrawal::where('status', TransactionStatus::REJECTED)->sum('amount');
        $totalProcessing = Withdrawal::where('status', TransactionStatus::PROCESSING)->sum('amount');

        // Liquidos
        $totalAmountLiquid = Withdrawal::sum('final_amount');
        $totalAprrovedLiquid = Withdrawal::where('status', TransactionStatus::APPROVED)->sum('final_amount');
        $totalPendingLiquid = Withdrawal::where('status', TransactionStatus::PENDING)->sum('final_amount');
        $totalRejectedLiquid = Withdrawal::where('status', TransactionStatus::REJECTED)->sum('final_amount');
        $totalProcessingLiquid = Withdrawal::where('status', TransactionStatus::PROCESSING)->sum('final_amount');

        return response()->json([
            'total_pending' => $totalPending,
            'total_approved' => $totalAprroved,
            'total_processing' => $totalProcessing,
            'total_rejected' => $totalRejected,
            'total_amount' => $totalAmount,
            'liquid' => [
                'total_pending_liquid' => $totalPendingLiquid,
                'total_approved_liquid' => $totalAprrovedLiquid,
                'total_processing_liquid' => $totalProcessingLiquid,
                'total_rejected_liquid' => $totalRejectedLiquid,
                'total_amount_liquid' => $totalAmountLiquid,
            ]
        ]);
    }

    public function rejectWithdraw(Withdrawal $withdraw, Request $request): JsonResponse
    {
        try {
            if (!$withdraw || $withdraw->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Saque não identificado ou já processado'
                ], 400);
            }

            $user = $withdraw->user;

            $withdraw->status = TransactionStatus::REJECTED;
            $withdraw->save();

            $user->addBalance($withdraw->amount);

            $message = "Devido á alguma inconsistencia seu saque foi rejeitado pelos nossos administradores seu saldo foi devolvido!";


            $pusher = new \Pusher\Pusher(
                config('broadcasting.connections.pusher.key'),
                config('broadcasting.connections.pusher.secret'),
                config('broadcasting.connections.pusher.app_id'),
                config('broadcasting.connections.pusher.options')
            );

            $pusher->trigger('chanel-user-' . $user->id, 'paid', [
                'user_id' => $user->id,
                'message' => $message,
                'user' => $user,
                'timestamp' => now(),
                'type' => 'info'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Saque rejeitado com sucesso!',
                'data' => $withdraw
            ],);
        } catch (\Exception $e) {
            \Log::error('Erro ao alterar saque:', [
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erro no processamento de saque',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}
