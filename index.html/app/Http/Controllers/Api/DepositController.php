<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionStatus;
use App\Enums\TransactionTypes;
use App\Helpers\CpfValidator;
use App\Http\Controllers\admin\SettingController;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Gateway;
use App\Models\GatewayMethod;
use App\Models\Transaction;
use App\Models\Withdrawal;
use App\Services\DepositService;
use App\Services\DigitoPay\DigitoPayException;
use App\Services\DigitoPay\DigitoPayService;
use App\Services\PayOne\PayOneClient;
use App\Services\PayOne\PayOneDepositPayload;
use App\Services\PayOne\PayOneService;
use App\Services\PixQrCodeService;
use App\Services\SyncPay\SyncPay;
use App\Services\SyncPay\SyncPayException;
use App\Services\SyncPayment\SyncPaymentException;
use App\Services\SyncPayment\SyncPaymentService;
use App\Services\TransactionService;
use App\Services\VizionPay\VizionPayService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use stdClass;

class DepositController extends Controller
{

    public function __construct(
        private PayOneService $payOneService,
        private SyncPay $syncpay,
        private PixQrCodeService $pixQrCodeService,
        private VizionPayService $vizionPayService,
        private DigitoPayService $digitopayService,
        private DepositService $depositService,
        private SettingController $settingController,
        private TransactionService $transactionService,
        private SyncPaymentService $syncPaymentService
    ) {}

    public function generateTransactionId(): string
    {
        return Str::uuid()->toString();
    }

    public function check(Deposit $deposit)
    {
        $deposit->refresh();
        if ($deposit->status !== TransactionStatus::APPROVED) {
            return response()->json([
                'success' => false
            ], 200);
        }

        return response()->json([
            'success' => true
        ], 200);
    }

    public function list()
    {
        $user = auth()->user();

        $deposits = Deposit::where('user_id', $user->id)->get();

        return response()->json($deposits, 200);
    }

    public function adminList()
    {
        $deposits = Deposit::all();

        return response()->json([
            'status' => true,
            'data' => $deposits,
            'message' => 'Depositos listados com sucesso'
        ]);
    }

    public function store(Request $request)
    {
        try {

            $validate = Validator::make($request->all(), [
                'amount' => ['required', 'numeric', 'min:' . setting('minimum_deposit', 0), 'max:' . setting('maximum_deposit', 0)],
                'cpf' => ['nullable', 'string', 'min:11', 'max:11']
            ], [
                'cpf.min' => 'O CPF deve ter 11 digitos',
                'cpf.max' => 'O CPF deve ter 11 digitos',
                'amount.min' => 'O valor mínimo para depósito é de $' . setting('minimum_deposit'),
                'amount.max' => 'O valor máximo para depósito é de $' . setting('maximum_deposit'),
            ]);

            $user = auth()->user();

            if (!$user) {
                Log::channel('deposit')->error('DEPOSIT -> Usuário não encontrado');
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado'
                ], 400);
            }

            Log::channel('deposit')->info("DEPOSIT -> Iniciando processo de deposito...", [
                'request' => json_encode($request->all(), JSON_PRETTY_PRINT),
                'user' => json_encode($user, JSON_PRETTY_PRINT)
            ]);


            if ($validate->fails()) {
                Log::channel('deposit')->error('[VALIDATION]:', [
                    'errors' => json_encode($validate->errors(), JSON_PRETTY_PRINT)
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Erro de validação',
                    'errors' => $validate->errors()
                ], 400);
            }
            // Validar CPF
            if ($request->cpf) {

                $validationCpf = CpfValidator::validate($request->cpf);
                if (!$validationCpf['valid']) {
                    return response()->json([
                        'success' => false,
                        'message' => $validationCpf['message'],
                        'errors' => [
                            'cpf' => [$validationCpf['message']]
                        ]
                    ], 400);
                }
            }


            // Obtém os dados validados corretamente
            $validatedData = $validate->validated();
            $order_id = $this->transactionService->generateUUid();

            $withdrawnAccount = $user->withdrawAccount;

            $gatewayMethod = GatewayMethod::where('status', 'active')->first();

            if (!$gatewayMethod) {
                throw new Exception("Nenhum gateway ativo no momento, entre em contato com um administrador");
            }


            $generatedData = $this->depositService->generateDataDeposits();

            if (app()->environment('production')) {

                $payload = [
                    'value_cents' => (float) $request->amount,
                    'generator_name' => $withdrawnAccount->full_name ?? $user->name,
                    'generator_document' => $generatedData['cpf'],
                    'phone' => $generatedData['telefone'],
                    'callback_url' => route('vizion.webhook', ['type' => 'deposit']),
                ];

                Log::channel('deposit')->info("[PAYLOAD]:", [
                    'data' => json_encode($payload, JSON_PRETTY_PRINT)
                ]);

                $gatewayResponse = $this->syncPaymentService->cashIn($payload);

                $transactionGate = new stdClass;
                $transactionGate->status = "OK";
                $transactionGate->transactionId = $gatewayResponse['data']['idTransaction'];

                $transactionGate->pix = new stdClass;
                $transactionGate->pix->code = $gatewayResponse['data']['paymentCode'];
                $transactionGate->pix->image = $gatewayResponse['data']['paymentCode'];
            } else {
                $transactionGate = new stdClass;
                $transactionGate->status = "OK";
                $transactionGate->transactionId = $order_id;

                $transactionGate->pix = new stdClass;
                $transactionGate->pix->code = config('custom.qrcode_test');
                $transactionGate->pix->image = config('custom.qrcode_test');

                $gatewayResponse = $transactionGate;
            }

            if ($transactionGate->status !== "OK") {
                Log::channel('vizion')->error('[TYPE]:DEPOSIT -> ' . $transactionGate['response']);
                return response()->json([
                    'success' => false,
                    'message' => $transactionGate
                ], 400);
            }

            $deposit = new Deposit([
                'user_id' => $user->id,
                'method_name' => 'PIX',
                'address' => "VizionPay",
                'order_id' => $order_id,
                'amount' => (float) $validatedData['amount'],
                'transaction_id' => $transactionGate->transactionId,
                'date' => Carbon::now(),
                'status' => TransactionStatus::PENDING,
            ]);

            $deposit->save();

            Transaction::created([
                'user_id' => Auth::id(),
                'type' => TransactionTypes::DEPOSIT,
                'currency' => 'BRL',
                'amount' => (float) $validatedData['amount'],
                'payment_id' => $transactionGate->transactionId,
                'order_id' => $order_id,
                'external_data' => json_encode($gatewayResponse),
                'status' => TransactionStatus::PENDING,
                'description' => 'Depósito via PIX criado pelo usuário de ID: ' . $user->id,
                'deposit_id' => $deposit->id,
            ]);


            Log::channel('deposit')->info("[GENERATED]:", [
                'data' => json_encode($deposit, JSON_PRETTY_PRINT)
            ]);

            // Caminho para o arquivo da logo
            // $logoPath = public_path('common/img/astroinvestlogo.png');


            // // Verifica se o arquivo da logo existe
            // if (!file_exists($logoPath)) {
            //     \Log::warning('Logo não encontrada em: ' . $logoPath);
            // }

            // // Verifica se o código PIX é válido
            // if (!$this->pixQrCodeService->validatePixCode($transactionGate->pix->code)) {
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'Não foi possível gerar o código PIX',
            //     ], 400);
            // }

            // // Gera o QR code com a logo
            // $qrCodeBase64 = $this->pixQrCodeService->generateQrCodeWithLogo($transactionGate->pix->code, $logoPath);

            return response()->json([
                'success' => true,
                'data' => [
                    'deposit_id' => $deposit->id,
                    'payment_code' => $transactionGate->pix->code,
                    'base_64' => " ",
                    'value' => $validatedData['amount']
                ]
            ]);
        } catch (SyncPayException $s) {
            return response()->json([
                'success' => false,
                'message' => 'Estamos passando por dificuldades em nosso gateway de pagamento no momento, voltaremos em breve',
                'errors' => [
                    'syncpay' => [$s->getMessage()]
                ]
            ], 400);
        } catch (\Exception $e) {
            Log::error('[TYPE]:DEPOSIT -> ' . $e->getMessage());
            throw $e;
            return response()->json([
                'success' => false,
                'message' => [
                    'msg' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]
            ], 400);
        }
    }

    public function SyncPayWebHook(Request $request)
    {
        try {
            $requestData = $request->toArray();

            Log::channel('webhook')->info("[SYNCPAY] -> Webhook Recebido:", [
                'response' => json_encode($requestData, JSON_PRETTY_PRINT)
            ]);

            $syncpayData = $this->syncpay->processCashInWebhook($requestData);

            $transactionId = $syncpayData['transaction_id'];

            $deposit = Deposit::where('transaction_id', $transactionId)
                ->where('status', TransactionStatus::PENDING)
                ->first();

            if (!$deposit) {
                Log::error("[TYPE]:WEBHOOK SYNCPAY -> Deposito não encontrado TRX: " . $transactionId);
                return response()->json([
                    'message' => "Depósito não encontrado"
                ], 400);
            }

            if ($deposit->status !== 'pending') {
                Log::warning("[TYPE]:WEBHOOK SYNCPAY -> Depósito já processado TRX: " . $transactionId);
                return response()->json([
                    'message' => "Depósito já processado anteriormente"
                ], 200);
            }

            Log::info("[TYPE]:WEBHOOK SYNCPAY -> Iniciando atualização de dados de deposito e comissão");

            if (Cache::lock('deposit_' . $transactionId, 30)->get()) {
                DB::beginTransaction();
                try {
                    $this->depositService->approveDeposit($deposit);
                    DB::commit();

                    Log::info("[TYPE]:WEBHOOK SYNCPAY -> Processo de webhook finalizado");

                    return response()->json([
                        'success' => true,
                        'message' => "Webhook processado com sucesso"
                    ], 200);
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            } else {
                Log::warning("[TYPE]:WEBHOOK SYNCPAY -> Depósito sendo processado por outra instância TRX: " . $transactionId);
                return response()->json([
                    'message' => "Depósito já está sendo processado"
                ], 200);
            }
        } catch (SyncPayException $s) {
            Log::error("Erro ao processar webhook: " . $s->getMessage());
            throw $s;
        } catch (\Exception $e) {
            Log::error("Erro ao processar webhook: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Processa webhooks de depósito e saque da VizionPay.
     *
     * @param Request $request
     * @param string $type O tipo de webhook (ex: 'payment', 'deposit', etc.).
     * @return \Illuminate\Http\JsonResponse
     */
    public function VizionPayWebHook(Request $request, $type)
    {
        try {
            $webhookData = $request->all();

            Log::channel('webhook')->info("[VIZIONPAY] -> Webhook Recebido:", [
                'type' => $type,
                'response' => json_encode($webhookData, JSON_PRETTY_PRINT)
            ]);

            // Utiliza um switch para tratar os diferentes tipos de webhooks de forma mais organizada
            switch ($webhookData['event']) {
                case 'TRANSFER_COMPLETED':
                    // Processa o webhook de saque aprovado
                    return $this->processTransferCompleted($webhookData);

                case 'TRANSFER_FAILED':
                    // Processa o webhook de saque falhado
                    return $this->processTransferFailed($webhookData);

                case 'TRANSACTION_PAID':
                    // Processa o webhook de depósito pago
                    return $this->processTransactionPaid($webhookData);
                case 'TRANSFER_FAILED':
                    // Processa o webhook de saque cancelado
                    return $this->processTransferFailed($webhookData);
            }

            // Retorna um erro caso o evento não seja reconhecido
            return response()->json([
                'message' => 'Evento de webhook não reconhecido.'
            ], 400);
        } catch (SyncPayException $s) {
            Log::error("Erro SyncPay ao processar webhook: " . $s->getMessage());
            return response()->json(['message' => 'Erro interno ao processar webhook.'], 500);
        } catch (\Exception $e) {
            Log::error("Erro geral webhook: " . $e->getTraceAsString());
            Log::error("Erro geral ao processar webhook: " . $e->getMessage());
            return response()->json(['message' => 'Erro interno ao processar webhook.'], 500);
        }
    }

    /**
     * Processa webhooks de depósito e saque da SyncPayment.
     *
     * @param Request $request
     * @param string $type O tipo de webhook (ex: 'cashin', 'cashout').
     * @return \Illuminate\Http\JsonResponse
     */
    public function syncPaymentWebhook(Request $request, $type)
    {
        try {
            $webhookData = $request->all();

            Log::channel('syncpayments')->info("[SYNCPAYMENT] -> Webhook Recebido:", [
                'type' => $type,
                'response' => json_encode($webhookData, JSON_PRETTY_PRINT)
            ]);

            $status = $webhookData['data']['status'];
            if ($type === 'cashout') {
                // Utiliza um switch para tratar os diferentes tipos de webhooks de forma mais organizada
                switch ($status) {
                    case 'completed':
                        // Processa o webhook de saque aprovado
                        return $this->processSyncPaymentTransferCompleted($webhookData);

                    case 'failed':
                        // Processa o webhook de saque falhado
                        return $this->processTransferFailed($webhookData);
                    case 'refunded':
                    case 'med':
                        // Processa o webhook de saque cancelado
                        return $this->processTransferFailed($webhookData);
                }
            }

            if ($type === 'cashin') {
                // Utiliza um switch para tratar os diferentes tipos de webhooks de forma mais organizada
                switch ($status) {
                    case 'completed':
                        // Processa o webhook de depósito pago
                        return $this->processSyncPaymentTransactionPaid($webhookData);
                    case 'failed':
                    case 'refunded':
                        // Processa o webhook de saque cancelado
                        return $this->processDepositFailed($webhookData);
                }
            }

            // Retorna um erro caso o evento não seja reconhecido
            return response()->json([
                'message' => 'Evento de webhook não reconhecido.' . $type
            ], 400);
        } catch (SyncPaymentException $s) {
            Log::error("Erro SyncPay ao processar webhook: " . $s->getMessage());
            return response()->json(['message' => 'Erro interno ao processar webhook.'], 500);
        } catch (\Exception $e) {
            Log::error("Erro geral webhook: " . $e->getTraceAsString());
            Log::error("Erro geral ao processar webhook: " . $e->getMessage());
            return response()->json(['message' => 'Erro interno ao processar webhook.'], 500);
        }
    }

    /**
     * Processa a falha de um saque.
     *
     * @param array $webhookData
     * @return \Illuminate\Http\JsonResponse
     */
    private function processDepositFailed(array $webhookData)
    {
        $transactionData = $webhookData['data'];

        $deposit = Deposit::where('status', TransactionStatus::PENDING)
            ->where('transaction_id', $transactionData['id'])
            ->first();

        if (!$deposit) {
            Log::channel('webhook')->warning("[SYNCPAYMENT] -> Depósito não identificado: " . $transactionData['id']);
            return response()->json([
                'message' => 'Depósito não identificado'
            ], 404);
        }

        /** @var \App\Models\User $user */
        $user = $deposit->user;
        if (!$user) {
            return response()->json(['message' => 'Usuario nao encontrado'], 400);
        }
        $deposit->status = TransactionStatus::CANCELED;
        $deposit->metadata = $webhookData;
        $deposit->save();

        return response()->json([
            'success' => true,
            'message' => 'Depósito processado com falha'
        ], 200);
    }

    /**
     * Processa a conclusão de um saque via SyncPayment.
     *
     * @param array $webhookData
     * @return \Illuminate\Http\JsonResponse
     */
    private function processSyncPaymentTransferCompleted(array $webhookData)
    {
        $transactionData = $this->syncPayment->processCashOutWebhook($webhookData);

        // Use 'first()' para obter um único modelo, não 'whereIn'
        $withdraw = Withdrawal::where('status', TransactionStatus::PROCESSING)
            ->where('transaction_id', $transactionData['transaction_id'])
            ->first();

        if (!$withdraw) {
            Log::channel('syncpayments')->warning("[SYNCPAYMENT] -> Saque não identificado: " . $transactionData['transaction_id']);
            return response()->json([
                'message' => 'Saque não identificado'
            ], 404); // Use 404 para "não encontrado"
        }

        $withdraw->status = TransactionStatus::APPROVED;
        $withdraw->save();

        return response()->json([
            'success' => true,
            'message' => 'Saque processado com sucesso'
        ], 200);
    }

    /**
     * Processa o pagamento de um depósito via SyncPayment.
     *
     * @param array $webhookData
     * @return \Illuminate\Http\JsonResponse
     */
    private function processSyncPaymentTransactionPaid(array $webhookData)
    {
        try {
            $syncPaymentResponse = $this->syncPayment->processCashInWebhook($webhookData);
            $transactionId = $syncPaymentResponse['transaction_id'];

            $deposit = Deposit::where('transaction_id', $transactionId)
                ->where('status', TransactionStatus::PENDING)
                ->first();

            if (!$deposit) {
                Log::channel('syncpayments')->error("[TYPE]:WEBHOOK SYNCPAYMENT -> Deposito não encontrado TRX: " . $transactionId);
                return response()->json([
                    'message' => "Depósito não encontrado"
                ], 404);
            }

            if ($deposit->status !== TransactionStatus::PENDING) {
                Log::channel('syncpayments')->warning("[TYPE]:WEBHOOK SYNCPAYMENT -> Depósito já processado TRX: " . $transactionId);
                return response()->json([
                    'message' => "Depósito já processado anteriormente"
                ], 200);
            }

            if (Cache::lock('deposit_' . $transactionId, 30)->get()) {
                DB::beginTransaction();
                try {
                    $this->depositService->approveDeposit($deposit);

                    $deposit->update([
                        'metadata' => $webhookData
                    ]);
                    DB::commit();

                    Log::info("[TYPE]:WEBHOOK SYNCPAYMENT -> Processo de webhook de depósito finalizado com sucesso");

                    return response()->json([
                        'success' => true,
                        'message' => "Webhook processado com sucesso"
                    ], 200);
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error("Erro durante o processamento do depósito: " . $e->getMessage());
                    return response()->json(['message' => 'Erro interno ao processar webhook.'], 500);
                } finally {
                    // Certifique-se de liberar o lock, mesmo em caso de erro
                    Cache::lock('deposit_' . $transactionId)->release();
                }
            } else {
                Log::warning("[TYPE]:WEBHOOK VIZION -> Depósito sendo processado por outra instância TRX: " . $transactionId);
                return response()->json([
                    'message' => "Depósito já está sendo processado"
                ], 200);
            }
        } catch (SyncPaymentException $e) {
            Log::channel('syncpayments')->info(json_encode([
                'message' => 'Erro com a syncpay',
                'details' => $e->getMessage()
            ], JSON_PRETTY_PRINT));

            return response()->json([
                'error' => $e->getMessage(),
                'message' => 'Erro de validação no webhook'
            ], 500);
        } catch (Exception $e) {
            Log::channel('syncpayments')->info(json_encode([
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], JSON_PRETTY_PRINT));

            return response()->json([
                'error' => $e->getMessage(),
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Processa a conclusão de um saque.
     *
     * @param array $webhookData
     * @return \Illuminate\Http\JsonResponse
     */
    private function processTransferCompleted(array $webhookData)
    {
        $transactionData = $this->vizionPayService->processCashOutWebhook($webhookData);

        // Use 'first()' para obter um único modelo, não 'whereIn'
        $withdraw = Withdrawal::where('status', TransactionStatus::PROCESSING)
            ->where('transaction_id', $transactionData['transaction_id'])
            ->first();

        // O erro estava aqui: 'if ($withdraw)' retornaria true se o saque fosse encontrado.
        // O correto é 'if (!$withdraw)' para verificar se o saque não foi encontrado.
        if (!$withdraw) {
            Log::channel('webhook')->warning("[VIZIONPAY] -> Saque não identificado: " . $transactionData['transaction_id']);
            return response()->json([
                'message' => 'Saque não identificado'
            ], 404); // Use 404 para "não encontrado"
        }

        $withdraw->status = TransactionStatus::APPROVED;
        $withdraw->save();

        return response()->json([
            'success' => true,
            'message' => 'Saque processado com sucesso'
        ], 200);
    }

    /**
     * Processa a falha de um saque.
     *
     * @param array $webhookData
     * @return \Illuminate\Http\JsonResponse
     */
    private function processTransferFailed(array $webhookData)
    {
        $transactionData = $webhookData['data'];

        $withdraw = Withdrawal::where('status', TransactionStatus::PROCESSING)
            ->where('transaction_id', $transactionData['id'])
            ->first();

        if (!$withdraw) {
            Log::channel('webhook')->warning("[SYNCPAYMENT] -> Saque não identificado: " . $transactionData['id']);
            return response()->json([
                'message' => 'Saque não identificado'
            ], 404);
        }

        /** @var \App\Models\User $user */
        $user = $withdraw->user;
        if (!$user) {
            return response()->json(['message' => 'Usuario nao encontrado'], 400);
        }
        $user->addBalance($withdraw->amount);
        $withdraw->status = 'rejected';
        $withdraw->save();

        return response()->json([
            'success' => true,
            'message' => 'Saque processado com falha'
        ], 200);
    }

    /**
     * Processa o pagamento de um depósito.
     *
     * @param array $webhookData
     * @return \Illuminate\Http\JsonResponse
     */
    private function processTransactionPaid(array $webhookData)
    {
        $vizionPayResponse = $this->vizionPayService->processCashInWebhook($webhookData);
        $transactionId = $vizionPayResponse['transaction_id'];

        $deposit = Deposit::where('transaction_id', $transactionId)
            ->where('status', TransactionStatus::PENDING)
            ->first();

        if (!$deposit) {
            Log::channel('vizion')->error("[TYPE]:WEBHOOK VIZION -> Deposito não encontrado TRX: " . $transactionId);
            return response()->json([
                'message' => "Depósito não encontrado"
            ], 404);
        }

        if ($deposit->status !== TransactionStatus::PENDING) {
            Log::warning("[TYPE]:WEBHOOK VIZION -> Depósito já processado TRX: " . $transactionId);
            return response()->json([
                'message' => "Depósito já processado anteriormente"
            ], 200);
        }

        if (Cache::lock('deposit_' . $transactionId, 30)->get()) {
            DB::beginTransaction();
            try {
                $this->depositService->approveDeposit($deposit);
                DB::commit();

                Log::info("[TYPE]:WEBHOOK VIZION -> Processo de webhook de depósito finalizado com sucesso");

                return response()->json([
                    'success' => true,
                    'message' => "Webhook processado com sucesso"
                ], 200);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("Erro durante o processamento do depósito: " . $e->getMessage());
                return response()->json(['message' => 'Erro interno ao processar webhook.'], 500);
            } finally {
                // Certifique-se de liberar o lock, mesmo em caso de erro
                Cache::lock('deposit_' . $transactionId)->release();
            }
        } else {
            Log::warning("[TYPE]:WEBHOOK VIZION -> Depósito sendo processado por outra instância TRX: " . $transactionId);
            return response()->json([
                'message' => "Depósito já está sendo processado"
            ], 200);
        }
    }

    public function DigitoWebhook(Request $request)
    {
        try {

            $requestData = $request->toArray();

            Log::channel('webhook')->info("[DIGITOPAY] -> Webhook Recebido:", [
                'response' => json_encode($requestData, JSON_PRETTY_PRINT)
            ]);

            $vizionPayResponse = $this->digitopayService->processCashInWebhook($requestData);

            $transactionId = $vizionPayResponse['transaction_id'];

            $deposit = Deposit::where('transaction_id', $transactionId)
                ->where('status', 'pending')
                ->first();

            if (!$deposit) {
                Log::channel('webhook')->error("[TYPE]:WEBHOOK DIGITOPAY -> Deposito não encontrado TRX: " . $transactionId);
                return response()->json([
                    'message' => "Depósito não encontrado"
                ], 400);
            }

            if ($deposit->status !== 'pending') {
                Log::warning("[TYPE]:WEBHOOK DIGITOPAY -> Depósito já processado TRX: " . $transactionId);
                return response()->json([
                    'message' => "Depósito já processado anteriormente"
                ], 200);
            }

            Log::info("[TYPE]:WEBHOOK DIGITOPAY -> Iniciando atualização de dados de deposito e comissão");

            if (Cache::lock('deposit_' . $transactionId, 30)->get()) {
                DB::beginTransaction();
                try {
                    $this->depositService->approveDeposit($deposit);
                    DB::commit();

                    Log::info("[TYPE]:WEBHOOK DIGITOPAY -> Processo de webhook finalizado");

                    return response()->json([
                        'success' => true,
                        'message' => "Webhook processado com sucesso"
                    ], 200);
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            } else {
                Log::warning("[TYPE]:WEBHOOK DIGITOPAY -> Depósito sendo processado por outra instância TRX: " . $transactionId);
                return response()->json([
                    'message' => "Depósito já está sendo processado"
                ], 200);
            }
        } catch (DigitoPayException $s) {
            Log::error("Erro ao processar webhook: " . $s->getMessage());
            throw $s;
        } catch (\Exception $e) {
            Log::error("Erro ao processar webhook: " . $e->getMessage());
            throw $e;
        }
    }
}
