<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateWithdrawalRequest;
use App\Models\GatewayMethod;
use App\Models\User;
use App\Models\Withdrawal;
use App\Models\WithdrawalAccount;
use App\Services\DigitoPay\DigitoPayService;
use App\Services\NowPayments\NowPaymentsService;
use App\Services\PayOne\PayOneService;
use App\Services\SyncPay\SyncPay;
use App\Services\SyncPay\SyncPayException;
use App\Services\VizionPay\VizionPayException;
use App\Services\VizionPay\VizionPayService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ManageWithdrawController extends Controller
{

    private $syncpay;
    private $payOne;
    private $vizionPay;
    private $digitoPay;
    private NowPaymentsService $nowpayment;

    public function __construct(SyncPay $syncpay, PayOneService $payOne, VizionPayService $vizionPay, DigitoPayService $digitoPay, NowPaymentsService $nowpayment)
    {
        $this->syncpay = $syncpay;
        $this->payOne = $payOne;
        $this->vizionPay = $vizionPay;
        $this->digitoPay = $digitoPay;
        $this->nowpayment = $nowpayment;
    }

    public function webhookWithdrawn(Request $request)
    {
        $data = $request->all();

        try {
            $verify = $this->syncpay->processCashOutWebhook($data);

            if ($verify['transaction_id']) {
                $transactionId = $verify['transaction_id'];

                $whithdraw = Withdrawal::where('transaction_id', $transactionId)->first();

                if ($whithdraw) {
                    $whithdraw->status = 'approved';
                    $whithdraw->save();

                    return response()->json([
                        'status' => 'success',
                        'message' => 'Saque processado com sucesso'
                    ], 200);
                }
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => 'IdTransaction não encontrado'
                ], 404);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erro ao processar saque: ' . $e->getMessage()
            ], 404);
        }
    }

    public function pendingWithdraw()
    {
        $title = 'Pending';
        $withdraws = Withdrawal::with(['user', 'payment_method'])->where('status', 'pending')->orderByDesc('id')->get();
        return view('admin.pages.withdraw.list', compact('withdraws', 'title'));
    }

    public function rejectedWithdraw()
    {
        $title = 'Rejected';
        $withdraws = Withdrawal::with(['user', 'payment_method'])->where('status', 'rejected')->orderByDesc('id')->get();
        return view('admin.pages.withdraw.list', compact('withdraws', 'title'));
    }

    public function approvedWithdraw()
    {
        $title = 'Approved';
        $withdraws = Withdrawal::with(['user', 'payment_method'])->where('status', 'approved')->orderByDesc('id')->get();
        return view('admin.pages.withdraw.list', compact('withdraws', 'title'));
    }

    private function generateTransactionId(): string
    {
        return Str::uuid()->toString();
    }

    /**
     * Conversão USD para USDT com cotação real da API
     *
     * @param float $usdAmount
     * @return array
     */
    private function convertUsdToUsdt($usdAmount)
    {
        if (!is_numeric($usdAmount) || $usdAmount < 0) {
            throw new Exception('Valor em USD deve ser um número válido e positivo');
        }

        try {
            // Buscar cotação do USDT via CoinGecko API
            $response = Http::timeout(10)->get('https://api.coingecko.com/api/v3/simple/price', [
                'ids' => 'tether',
                'vs_currencies' => 'usd'
            ]);

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['tether']['usd'])) {
                    $usdtPrice = $data['tether']['usd'];
                    $usdtAmount = $usdAmount / $usdtPrice;

                    return [
                        'success' => true,
                        'usd_amount' => round((float)$usdAmount, 2),
                        'usdt_amount' => round($usdtAmount, 6),
                        'exchange_rate' => $usdtPrice,
                        'timestamp' => now()->toISOString(),
                        'fallback' => false
                    ];
                }
            }

            throw new Exception('Dados inválidos da API');
        } catch (Exception $e) {
            Log::warning('Erro ao buscar cotação USDT: ' . $e->getMessage());

            // Fallback para conversão 1:1
            return [
                'success' => true,
                'usd_amount' => round((float)$usdAmount, 2),
                'usdt_amount' => round((float)$usdAmount, 6),
                'exchange_rate' => 1.0,
                'timestamp' => now()->toISOString(),
                'fallback' => true
            ];
        }
    }

    public function withdrawStatus(Request $request, $id)
    {


        try {
            $withdraw = Withdrawal::where('status', 'pending')->find($id);

            if (!$withdraw) {
                return redirect()->back()->with('error', 'Transação não encontrada');
            }

            $user = User::find($withdraw->user_id);

            if ($request->status == 'rejected') {
                $user->increment('profit_balance', $withdraw->amount);

                $withdraw->update([
                    'status' => 'rejected'
                ]);

                return redirect()->back()->with('success', 'Withdraw status change successfully.');
            }



            if ($withdraw->method_name == 'usdt') {
                $withdraw->status = $request->status;
                $withdraw->save();


                return redirect()->back()->with('success', 'Withdraw status change successfully.');
            } else {
                $withdrawAccount = WithdrawalAccount::where('user_id', $user->id)->first();

                $settingController = new SettingController();
                $dolar = $settingController->getDolarValue();

                // $finalAmount = $withdraw->amount * $dolar['dollar_with_iof'];

                $valueInUSD = round($withdraw->final_amount * $dolar['dollar_value'], 2);

                $saque = $this->vizionPay->cashOut([
                    'amount' => (float) $valueInUSD,
                    'pix_type' => $withdrawAccount->pix_key_type,
                    'pix_key' => $withdrawAccount->pix_key,
                    'document' => $withdrawAccount->cpf,
                    'name' => $withdrawAccount->full_name,
                    'postbackUrl' => route('vizion.webhook', ['type' => 'payment'])
                ]);

                $message = "O valor de R$" . $withdraw->final_amount . " foi enviado á sua conta PIX";

                if (!$saque['data']) {
                    return redirect()->back()->with('error', 'Estamos passando por instabilidade em nosso processador de saques no momento');
                }

                $idTransaction = $saque['data']['idTransaction'];
            }




            $withdraw->transaction_id = $idTransaction;
            $withdraw->status = $request->status;
            $withdraw->save();

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

            return redirect()->back()->with('success', 'Withdraw status change successfully.');
        } catch (VizionPayException $v) {
            Log::channel('vizion_paid_out')->error("Erro ao aprovar saque: " . json_encode([
                'line' => $v->getLine(),
                'file' => $v->getFile(),
                'error' => $v->getMessage(),
                'trace' => $v->getTraceAsString()
            ], JSON_PRETTY_PRINT));
            return redirect()->back()->with('error', $v->getMessage());
        } catch (\Exception $e) {
            \Log::error('Erro ao alterar saque:', [
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new Exception($e);
            return redirect()->back()->with('error', 'Falha: ' . $e->getMessage());
        }
    }

    /**
     * 
     * Updating specific withdraw
     */
    public function update(UpdateWithdrawalRequest $request, $id)
    {
        Log::channel('withdraw')->info("[WITHDRAW] Iniciando processamento de saque: ");
        $withdrawal = Withdrawal::find($id);

        if (!$withdrawal) {
            return response()->json([
                'success' => false,
                'message' => 'Solicitação de saque não encontrada.'
            ], 404);
        }

        DB::beginTransaction();
        try {

            if ($request->status == 'rejected') {
                $user->increment('balance', $withdraw->amount);
            } else if ($request->status == 'approved') {
                $idTransaction  = self::processWithdraw($withdrawal);

                $withdrawal->transaction_id = $idTransaction;
            }

            $withdrawal->save();

            $withdrawal->update($request->validated());

            DB::commit();
            Log::channel('withdraw')->info("[WITHDRAW] Saque processado com sucesso: " . json_encode($withdrawal, JSON_PRETTY_PRINT));
            $withdrawal = Withdrawal::find($id);
            return response()->json([
                'success'  => true,
                'message'  => 'Solicitação de saque atualizada com sucesso.',
                'withdraw' => $withdrawal
            ], 200);
        } catch (\Exception $e) {
            Log::channel('withdraw')->error("[WITHDRAW] Erro no processamento de saque: " . json_encode($withdrawal, JSON_PRETTY_PRINT));
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar a solicitação de saque.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 
     * Function from processing withdraw on gateway
     * 
     * @param Withdraw
     * @return array{
     *  id_transaction: string
     * }
     * @throws Exception
     */
    private static function processWithdraw(Withdrawal $withdrawal)
    {
        $user = $withdrawal->user;


        $withdrawAccount = WithdrawalAccount::where('user_id', $user->id)->first();

        if (!$withdrawAccount) {
            throw new Exception('Nenhuma carteira de saque cadastrada.');
        }

        $gatewayMethod = GatewayMethod::where('status', 'active')->first();

        if (!$gatewayMethod) {
            throw new Exception('Nenhum método de pagamento habilitado no momento.');
        }

        $idTransaction = '';

        if ($gatewayMethod->name === 'payone') {
            $saque = self::payOne->cashout($withdraw);
            $idTransaction = self::generateTransactionId();
        } else {
            // processa o saque
            $saque = self::syncpay->cashOut([
                'amount' => $withdraw->final_amount,
                'pix_type' => $withdrawAccount->pix_key_type,
                'pix_key' => $withdrawAccount->pix_key,
                'document' => $withdrawAccount->cpf,
                'name' => $withdrawAccount->full_name
            ]);

            $idTransaction = $saque['data']['idTransaction'];
        }


        if (!$saque['data']) {
            return redirect()->back()->with('error', 'Estamos passando por instabilidade em nosso processador de saques no momento');
        }

        $pusher = new \Pusher\Pusher(
            config('broadcasting.connections.pusher.key'),
            config('broadcasting.connections.pusher.secret'),
            config('broadcasting.connections.pusher.app_id'),
            config('broadcasting.connections.pusher.options')
        );

        $pusher->trigger('chanel-user-' . $user->id, 'paid', [
            'user_id' => $user->id,
            'message' => "O valor de R$" . $withdraw->final_amount . " foi enviado á sua conta PIX",
            'user' => $user,
            'timestamp' => now(),
            'type' => 'info'
        ]);

        return $idTransaction;
    }

    public function withdrawChangeStatus(Request $request, $id)
    {


        try {
            $withdraw = Withdrawal::find($id);
            $user = User::find($withdraw->user_id);

            if (!$withdraw) {
                return response()->json([
                    'success' => false,
                    'message' => 'Saque não encontrado'
                ], 400);
            }

            if ($request->status == 'rejected') {
                $userRe = User::find($withdraw->user_id);
                $userRe->balance = $userRe->balance + $withdraw->amount;
                $userRe->save();

                $withdraw->status = 'rejected';
                $withdraw->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Saque alterado com sucesso'
                ], 200);
            }

            $pixType = '';
            switch ($withdraw->pix_type) {
                case 'random':
                    $pixType = 'RANDOM';
                    break;
                case 'CPF':
                    $pixType = 'cpf';
                    break;

                default:
                    $pixType = $withdraw->pix_type;
                    break;
            }

            // processa o saque
            $saque = $this->syncpay->cashOut($withdraw->final_amount, $user->realname, $withdraw->cpf, $withdraw->pix_key, $pixType);

            if (!$saque['data']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erro ao processar pix: ' . $e->getMessage()
                ], 400);
            }

            $withdraw->transaction_id = null;
            $withdraw->status = $request->status;
            $withdraw->save();
            return response()->json([
                'success' => true,
                'message' => 'Saque alterado com sucesso'
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Erro ao alterar saque:', [
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Falha: ' . $e->getMessage()
            ], 400);
        }
    }

    public function aproveAll(Request $request)
    {
        $values = $request->values; // Verifica se o array de IDs foi enviado corretamente

        if (!$values || !is_array($values)) {
            return response()->json(['message' => 'Nenhum valor válido fornecido.'], 400);
        }

        $aproveds = [];

        foreach ($values as $id) {
            $withdraw = Withdrawal::find($id);

            if (!$withdraw) {
                continue; // Ignora IDs inválidos
            }

            $user = User::find($withdraw->user_id);

            try {

                $pixType = '';
                switch ($withdraw->pix_type) {
                    case 'random':
                        $pixType = 'token';
                        break;
                    case 'CPF':
                        $pixType = 'cpf';
                        break;

                    default:
                        $pixType = $withdraw->pix_type;
                        break;
                }

                // Processa o saque
                $saque = $this->syncpay->cashOut(
                    $withdraw->final_amount,
                    $user->realname,
                    $withdraw->cpf,
                    $withdraw->pix_key,
                    $pixType
                );

                if (empty($saque['data'])) {
                    return response()->json(['message' => 'Erro ao processar PIX.'], 400);
                }

                $withdraw->transaction_id = $saque['data']['idTransaction'];
                $withdraw->status = 'approved';
                $withdraw->save();

                $aproveds[] = $withdraw;
            } catch (\Exception $e) {
                return response()->json(['message' => 'Erro ao processar todos os saques: ' . $e->getMessage()], 500);
            }
        }

        return response()->json($aproveds, 200);
    }
}
