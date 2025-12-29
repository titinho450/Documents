<?php

namespace App\Http\Controllers\user;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateWithdrawalRequest;
use App\Enums\TransactionTypes;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\UserLedger;
use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\Deposit;
use App\Models\GatewayMethod;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\SyncPayment\SyncPaymentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Piggly\Pix\Exceptions\InvalidPixKeyException;
use Piggly\Pix\Key;
use Piggly\Pix\Parser;

class WithdrawController extends Controller
{
    public function __construct(private Setting $setting, private SyncPaymentService $syncpaymentService) {}

    public function withdraw()
    {
        if (user()->gateway_method == null || user()->gateway_number == null) {
            return redirect()->route('user.bank.create')->with('success', 'First create your account.');
        }

        // Todos os saques
        $withdrawals = Withdrawal::where('user_id', auth()->user()->id)
            ->orderBy('created_at', 'desc') // Ordena pelos mais recentes
            ->get(['id', 'amount', 'created_at']);

        $saquesCount = $withdrawals->count(); // Total de saques
        $ultimoSaque = $withdrawals->first(); // Último saque (registro mais recente)
        // dd($ultimoSaque);
        $lastSaque = $ultimoSaque->amount ?? 0;
        $setting = Setting::first();

        return view('app.main.withdraw.index', compact('saquesCount', 'lastSaque', 'setting'));
    }

    public function withdraw_history()
    {
        return view('app.main.withdraw_history');
    }

    /**
     * 
     * Lista todos os saques feitos, de todos os usuários
     */
    public function listing()
    {
        $withdraws = Withdrawal::with('user')->get();

        foreach ($withdraws as $withdraw) {
            if ($withdraw->user) {
                $user = $withdraw->user;
                $withdraw->user->total_invested_data = $user->getTotalInvestedAttribute();
            }
        }

        return response()->json($withdraws, 200);
    }

    /**
     * 
     * Deleting specific withdraw
     */
    public function delete($id)
    {
        $withdraw = Withdrawal::find($id);

        if (!$withdraw) {
            response()->json([
                'success' => false,
                'message' => 'Saque não encontrado',
                'error' => 'Saque não encontrado',
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => 'Saque excluído com sucesso',
        ], 200);
    }

    /**
     * Gera um ID de ordem único
     * 
     * @return string
     */
    private static function generateUniqueOrderId()
    {
        $timestamp = now()->format('YmdHis');
        $random = mt_rand(1000, 9999);
        return $timestamp . $random;
    }

    public function meuIp(Request $request)
    {
        // Pega o IP real do usuário (considerando proxies)
        $ip = $request->ip();

        return response()->json([
            'ip' => $ip,
        ]);
    }

    public function withdrawRequest(Request $request)
    {
        DB::beginTransaction();

        try {
            // Validação dos dados
            $validate = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:10|max:' . setting('maximum_withdraw', 0),
                'withdrawn_address' => 'string|nullable',
                'usdt_network' => 'string|nullable',
                'method' => 'string',
                'ip_address' => 'required|string|min:9|max:100'
            ], [
                'amount.required' => 'Informe o valor do saque',
                'amount.numeric' => 'O valor do saque deve ser numérico em centávos',
                'amount.min' => 'O valor mínimo de saque é de R$' . setting('minimum_withdraw'),
                'amount.max' => 'O valor máximo de saque é de R$' . setting('maximum_withdraw'),

                'ip_address.required' => 'Não foi possível fazer a verificação de segurança',
                'ip_address.string' => 'O ip deve ser uma string',
                'ip_address.min' => 'O mínimo de caracteres de ip é 9',
                'ip_address.max' => 'O máximo de caracteres de ip é 100',
            ]);

            Log::channel('vizion_paid_out')->info("[WITHDRAWN][VIZION] ->INICIANDO PROCESSO DE CASHOUT:", [
                'request' => json_encode($request->all(), JSON_PRETTY_PRINT)
            ]);

            if ($validate->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erro de validação',
                    'errors' => $validate->errors()
                ], 422);
            }

            // Pega as configurações de saque
            /** @var \App\Models\Setting $settings */
            $settings = Setting::first();

            // Verificar configurações e condições
            if (!$settings->canWithdraw()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você está tentando fazer um saque fora do horário permitido.'
                ], 422);
            }

            $user = Auth::user();
            $amount = (float) $request->amount;

            if ($user->ban_unban === 'ban') {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário bloqueado, entre em contato com o suporte!.'
                ], 401);
            }


            // if (!$user->hasInvested90Percent()) {
            //     $remaining = $user->remainingToInvest90Percent();
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'Você precisa investir ao menos 90% do valor depositado, você ainda precisa investir ' . price($remaining) . ' .'
            //     ], 401);
            // }

            // if (!$user->hasMultipleValueWithdraws((float) $request->amount)) {

            //     return response()->json([
            //         'success' => false,
            //         'message' => 'Você fez muitos saques do mesmo valor consecutivo, aguarde 1 hora e tente novamente'
            //     ], 401);
            // }

            Log::info("[WITHDRAW]: Usuário ID: " . $user->id . " | Solicitação de saque no valor de R$" . $amount);

            // Verificar se o usuário tem conta de saque cadastrada
            if (!$user->withdrawAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cadastre sua carteira antes de solicitar um saque.'
                ], 422);
            }

            // Verificar saldo
            if ($amount > $user->balance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Saldo insuficiente para realizar este saque.'
                ], 422);
            }

            // Verificar limites de saque
            if ($amount < setting('minimum_withdraw')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Valor mínimo de saque é ' . setting('minimum_withdraw')
                ], 422);
            }

            if ($amount > setting('maximum_withdraw')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Valor máximo de saque é ' . setting('maximum_withdraw')
                ], 422);
            }

            //Verificar se o usuário tem plano ativo
            $withdraw_limiter = Setting::first()->value('withdraw_limiter');

            if ($withdraw_limiter == 1) {
                $userPurchase = Purchase::where('user_id', $user->id)
                    ->where('status', 'active')
                    ->first();

                if (!$userPurchase) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Você não possui um plano ativo para realizar saques.'
                    ], 422);
                }
            }

            // Verificar método de pagamento
            $gatewayMethod = GatewayMethod::where('status', 'active')->first();
            if (!$gatewayMethod) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum método de saque cadastrado, contate um administrador.'
                ], 422);
            }

            // Calcular taxa
            $charge = 0;
            if (setting('withdraw_charge') > 0) {
                $charge = $amount * 0.07;
            }
            $finalAmount = $amount - $charge;

            // Bloquear e atualizar o saldo (usando lock for update para evitar race conditions)
            $lockedUser = DB::table('users')
                ->where('id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedUser || $lockedUser->balance < $amount) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Saldo insuficiente ou usuário não encontrado.'
                ], 422);
            }

            // Decrementar saldo
            $user->decrement('balance', $amount);

            $pixType = $user->withdrawAccount->pix_key_type === 'CPF' ? 'document' : $user->withdrawAccount->pix_key_type;

            Parser::validate($pixType, $user->withdrawAccount->pix_key);


            // Verifica se o IP é um IPv6 (usando a função filter_var)
            $requestIp = $request->ip();
            if (filter_var($requestIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                // Se for IPv6, tenta encontrar o IPv4
                if ($request->hasHeader('X-Forwarded-For')) {
                    $ips = explode(',', $request->header('X-Forwarded-For'));
                    foreach ($ips as $currentIp) {
                        $currentIp = trim($currentIp);
                        // Verifica se o IP atual é um IPv4
                        if (filter_var($currentIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                            $requestIp = $currentIp;
                            break;
                        }
                    }
                }
            }

            // Criar registro de saque
            $withdrawal = new Withdrawal();
            $withdrawal->user_id = $user->id;
            $withdrawal->method_name = 'PIX';
            $withdrawal->name = $user->withdrawAccount->full_name;
            $withdrawal->cpf = $user->withdrawAccount->cpf;
            $withdrawal->pix_type = $user->withdrawAccount->pix_key_type;
            $withdrawal->pix_key = $user->withdrawAccount->pix_key;
            $withdrawal->address = $request->withdrawn_address ?? 'PIX';
            $withdrawal->amount = $amount;
            $withdrawal->charge = $charge;
            $withdrawal->usdt_network = $request->usdt_network;
            $withdrawal->oid = self::generateUniqueOrderId();
            $withdrawal->final_amount = $finalAmount;
            $withdrawal->ip = $request->ip_address ?? $requestIp;
            $withdrawal->status = 'pending';
            $withdrawal->save();

            // Registrar Transação
            Transaction::create(
                [
                    'user_id' => $user->id,
                    'type' => TransactionTypes::WITHDRAW,
                    'currency' => 'BRL',
                    'amount' => $amount,
                    'withdraw_id' => $withdrawal->id,
                    'payment_id' => $withdrawal->id,
                    'order_id' => $withdrawal->id,
                    'payment_address' => 'Vizzion',
                    'status' => TransactionStatus::PROCESSING,
                    'description' => 'Solicitação de saque',
                ]
            );

            // Confirmar as alterações no banco de dados
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Solicitação de saque realizada com sucesso',
                'withdrawal_id' => $withdrawal->id
            ]);
        } catch (InvalidPixKeyException $e) {
            DB::rollBack();

            Log::error("[WITHDRAW]: ERRO INTERNO: ", [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'msg' => $e->getMessage(),
                'user_id' => Auth::id(),
                'amount' => $request->amount ?? 'N/A'
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Chave pix cadastrada inválida.',
                'error_details' => 'Verifiqueee sua conta de saque e corrija sua chave pix'
            ], 422);
        } catch (\Exception $e) {
            // Reverter todas as alterações em caso de falha
            DB::rollBack();

            Log::error("[WITHDRAW]: ERRO INTERNO: ", [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'msg' => $e->getMessage(),
                'user_id' => Auth::id(),
                'amount' => $request->amount ?? 'N/A'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ocorreu um erro durante o processamento do saque. Seu saldo foi preservado.',
                'error_details' => app()->environment('production') ? null : $e->getMessage()
            ], 500);
        }
    }
}
