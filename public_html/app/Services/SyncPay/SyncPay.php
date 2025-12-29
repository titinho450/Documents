<?php

namespace App\Services\SyncPay;

use App\Models\Deposit;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

class SyncPay
{
    private $client;
    private $apiUrl;
    private const VALID_COMPLETED_STATUS = [
        'PAID_OUT',
        'COMPLETED'
    ];
    private const VALID_API_SUCCESS_STATUS = [
        'PAGO',
        'APROVADO',
        'PAGAMENTO_APROVADO',
        'COMPLETED'
    ];

    public function __construct(string $apiUrl, string $apiKey, string $externalreference = '')
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = base64_encode($apiKey);
        $this->externalreference = $externalreference;

        // Inicializa o cliente Guzzle com configurações base
        $this->client = new Client([
            'base_uri' => $this->apiUrl,
            'timeout'  => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ]);
    }

    /**
     * Gera o token com as credenciais
     * @return string
     */
    private function generateToken(): string
    {
        try {
            return base64_encode(env('SYNCPAY_API_KEY'));
        } catch (SyncPayException $e) {
            throw $e;
        }
    }

    /**
     * Realiza uma operação de Cash In (recebimento)
     * 
     * @param array{
     *     value_cents: float,
     *     generator_name: string,
     *     generator_document?: string,
     *     expiration_time?: int,
     *     external_reference?: string
     * } $payload Payload da transação
     * @return array{
     *      success: boolean,
     *      response: string,
     *      data: array{
     *          status: boolean,
     *          paymentCode: string,
     *          idTransaction: string,
     *          paymentCodeBase64: string,
     *          externalRefference: string
     *      }
     * } Resposta do servidor Status do pagamento, Pix copia e cola, Identificador da transação, QRcode de pagamento, URL de callback e Referencia de sistema
     * @throws SyncPayException
     */
    public function cashIn(array $payload): array
    {

        try {
            $ip = Request::ip();
            $token = $this->generateToken();

            $data = [
                'amount' => $payload['value_cents'],
                'postbackUrl' => route('syncpay.webhook'),
                'ip' => $ip,
                'customer' => [
                    'name' => $payload['generator_name'],
                    'email' => 'suport@syncpay.com',
                    'cpf' => $payload['generator_document']
                ]
            ];

            $response = $this->makeRequest('POST', '/v1/gateway/api/', $data, [
                'Authorization' => 'Basic ' . $token
            ]);

            $paymentCode = $response['paymentCode'] ?? null;
            $transactionId = $response['idTransaction'] ?? null;

            if (!$paymentCode || !$transactionId) {
                if ($response['message']) {
                    throw new SyncPayException('Erro ao gerar transação: ' . $response['message']);
                }

                throw new SyncPayException('Erro ao gerar transação: ' . json_encode($response));
            }

            LOG::info("[TYPE]:DEPOSIT SYNCPAY -> Depósito gerado com sucesso: ", $response);

            return [
                'success' => true,
                'response' => json_encode($response),
                'data' => [
                    'status' => true,
                    'paymentCode' => $response['paymentCode'] ?? null,
                    'idTransaction' => $response['idTransaction'] ?? null,
                    'paymentCodeBase64' => $response['paymentCodeBase64'] ?? null,
                    'externalRefference' => $response['idTransaction'] ?? null
                ]
            ];
        } catch (SyncPayException $s) {
            Log::error("[TYPE]:[SYNCPAY ERROR] -> ", $s->getMessage());
            throw $s;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Processa o webhook de Cash In
     * 
     * @param array $webhookData Dados recebidos no webhook
     * @return array{
     *      success: boolean,
     *      message: string,
     *      transaction_id: string,
     *      status: string
     * } Status do processamento
     * @throws SyncPayException
     */
    public function processCashInWebhook(array $webhookData): array
    {
        try {
            \Log::info('Recebido webhook para processar:', $webhookData);



            $messages = $webhookData['status'] ?? null;

            if (!$messages) {
                throw new SyncPayException('Informações do webhook não encontrada');
            }
            // Processar status da transação
            $transactionStatus = $webhookData['status'] ?? null;
            $transactionId = $webhookData['idtransaction'] ?? null;

            if (!$transactionId) {
                throw new SyncPayException('ID da transação não encontrado no webhook');
            }

            $deposit = Deposit::where('transaction_id', $transactionId)->where('status', 'pending')->first();

            if (!$deposit) {
                throw new SyncPayException('Deposito não encontrado');
            }
            // Validar assinatura do webhook
            $this->validateWebhookSignature($webhookData, $deposit);

            return [
                'success' => true,
                'message' => 'Webhook processado com sucesso',
                'transaction_id' => $transactionId,
                'status' => $transactionStatus
            ];
        } catch (Exception $e) {
            \Log::error('Erro ao processar webhook:', [
                'webhookData' => $webhookData,
                'error' => $e->getMessage()
            ]);

            throw new SyncPayException('Erro ao processar webhook: ' . $e->getMessage());
        }
    }

    /**
     * Realiza uma operação de Cash Out (pagamento)
     * 
     * @param array{
     *     amount: float,
     *     pix_type: string,
     *     pix_key: string,
     *     name: string,
     *     document: string,
     *     description?: string
     * } $payloadParams
     * 
     * @return array{
     *     success: boolean,
     *     data: array{
     *         amount: float,
     *         pixKey: string, 
     *         pixType: string, 
     *         beneficiaryName: string,
     *         beneficiaryDocument: string, 
     *         postbackUrl: string, 
     *         externalreference: string, 
     *         status: string,
     *         valor_liquido: float, 
     *         idTransaction: string
     *     }
     * }
     * 
     * @throws SyncPayException
     */
    public function cashOut($payloadParams): array
    {
        $payload = [
            'amount' => $payloadParams['amount'],
            'pixKey' => $payloadParams['pix_key'],
            'pixType' => $payloadParams['pix_type'],
            'beneficiaryName' => $payloadParams['name'],
            'beneficiaryDocument' => $payloadParams['document'],
            'description' => 'Saque pix',
            'postbackUrl' => null
        ];

        Log::info("[TYPE]WITHDRAWN SYNCPAY -> Iniciando processo de saque", $payload);

        try {
            $token = $this->generateToken();

            if (!$token) {

                \Log::error('[TYPE]WITHDRAWN SYNCPAY -> Erro ao gerar token:', [
                    'apikey' => $token,
                ]);
                throw new SyncPayException('[TYPE]WITHDRAWN SYNCPAY -> Erro ao gerar token: ' . json_encode($token));
            }

            $response = $this->makeRequest('POST', '/c1/cashout/api/', $payload, [
                'Authorization' => 'Basic ' . $token
            ]);

            if (!($response['data'] ?? null)) {
                \Log::error('[TYPE]WITHDRAWN SYNCPAY -> Dados recebidos inválidos:', $response);
                throw new SyncPayException('[TYPE]WITHDRAWN SYNCPAY -> Dados recebidos inválidos: ' . json_encode($response));
            }

            $idTransaction = $response['data']['idTransaction'] ?? null;

            \Log::info('[TYPE]WITHDRAWN SYNCPAY -> Processando saque:', $response);

            // if (!$idTransaction) {
            //     \Log::error('[TYPE]WITHDRAWN SYNCPAY -> Undefined idTransaction:', $response);
            //     throw new SyncPayException('Erro ao processar saque: ' . json_encode($response));
            // }

            if (empty($response['data']['status'])) {
                \Log::error('[TYPE]WITHDRAWN SYNCPAY -> Status ausente:', $response);
                throw new SyncPayException('Erro ao processar saque: ' . json_encode($response));
            }

            return [
                'success' => true,
                'data' => [
                    'amount' => $response['data']['amount'],
                    'pixKey' => $response['data']['pixKey'],
                    'pixType' => $response['data']['pixType'],
                    'beneficiaryName' => $response['data']['beneficiaryName'],
                    'beneficiaryDocument' => $response['data']['beneficiaryDocument'],
                    'externalreference' => $response['data']['externalreference'],
                    'status' => $response['data']['status'],
                    'valor_liquido' => $response['data']['valor_liquido'],
                    'idTransaction' => $idTransaction,
                    'status' => $response['data']['status']
                ]
            ];
        } catch (SyncPayException $e) {
            \Log::error('[TYPE]WITHDRAWN SYNCPAY -> Erro ao processar saque:', [
                'webhookData' => $e->getLine(),
                'error' => $e->getMessage()
            ]);

            throw new SyncPayException('Erro ao processar webhook: ' . $e->getMessage());
        }
    }

    /**
     * Processa o webhook de Cash Out
     * 
     * @param array $webhookData Dados recebidos no webhook
     * @return array{
     *      success: boolean,
     *      message: string,
     *      transaction_id: string,
     *      status: string
     * } Status do processamento
     * @throws SyncPayException
     */
    public function processCashOutWebhook(array $webhookData): array
    {
        try {


            // Processar status da transação
            $transactionStatus = $webhookData['status'] ?? null;
            $transactionId = $webhookData['idtransaction'] ?? null;

            if (!$transactionId || $transactionStatus) {
                Log::error("[TYPE]:DEPOSIT SYNCPAY -> Erro ao validar informaçoes", $webhookData);
                throw new SyncPayException('ID da transação não encontrado no webhook');
            }

            $apiResponse = $this->getTransaction($transactionId);


            if (!in_array($transactionStatus, self::VALID_COMPLETED_STATUS) || !in_array($apiResponse['situacao'], self::VALID_COMPLETED_STATUS)) {
                throw new SyncPayException('Webhook inválido');
            }



            return [
                'success' => true,
                'message' => 'Webhook processado com sucesso',
                'transaction_id' => $transactionId,
                'status' => $transactionStatus
            ];
        } catch (Exception $e) {
            throw new SyncPayException('Erro ao processar webhook: ' . $e->getMessage());
        }
    }

    /**
     * Realiza requisições para a API usando Guzzle
     * 
     * @param string $method Método HTTP
     * @param string $endpoint Endpoint da API
     * @param array $data Dados da requisição
     * @param ?array $headers
     * @return array Resposta da API
     * @throws SyncPayException
     */
    private function makeRequest(string $method, string $endpoint, array $data = [], $headers = []): array
    {
        try {
            $mergeHeaders = array_merge($this->client->getConfig('headers'), $headers);


            $response = $this->client->request($method, $endpoint, [
                'json' => $data,
                'headers' => $mergeHeaders
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (ClientException $e) {
            // Erros 4xx
            $responseBody = json_decode($e->getResponse()->getBody()->getContents(), true);
            throw new SyncPayException(
                'Erro do cliente: ' . ($responseBody['message'] ?? $e->getMessage()) . 'Payload: ' . json_encode($data),
                $e->getCode()
            );
        } catch (ServerException $e) {
            // Erros 5xx
            throw new SyncPayException(
                'Erro do servidor: ' . $e->getMessage() . 'Payload: ' . json_encode($data),
                $e->getCode()
            );
        } catch (RequestException $e) {
            // Outros erros de rede
            throw new SyncPayException(
                'Erro na requisição: ' . $e->getMessage(),
                $e->getCode()
            );
        }
    }

    /**
     * Get transaction details
     * 
     * @param string $transactionId
     * @return array
     * @throws SyncPayException
     */
    public function getTransaction(string $transactionId): array
    {
        try {
            $token = $this->generateToken();
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $token
            ])->get('https://api.syncpay.pro/s1/getTransaction/api/getTransactionStatus.php?id_transaction=' . $transactionId);

            if (!$response->successful()) {
                throw new SyncPayException(
                    'API request failed: ' . ($response->json('message') ?? 'Unknown error'),
                    $response->status()
                );
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Failed to fetch transaction', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);
            throw new SyncPayException('Failed to fetch transaction details: ' . $e->getMessage());
        }
    }

    /**
     * Valida a assinatura do webhook
     * 
     * @param array{
     *      id: int,
     *      user_id: string,
     *      externalreference?: string,
     *      amount: float,
     *      client_name: string,
     *      client_document: string,
     *      client_email: string,
     *      data_registro: string,
     *      adquirente_ref: string,
     *      status: string,
     *      idtransaction: string,
     *      paymentcode: string,
     *      paymentCodeBase64: string,
     *      taxa_deposito: string,
     *      taxa_adquirente: string,
     *      deposito_liquido: string
     * } $webhookData Dados do webhook
     * @throws SyncPayException
     */
    private function validateWebhookSignature(array $webhookData, Deposit $deposit): void
    {



        $receivedSignature = $webhookData['status'] ?? null; // externalreference

        if (!$receivedSignature) {
            throw new SyncPayException('Status da transação não encontrada.');
        }

        if ($receivedSignature !== 'PAID_OUT') {
            throw new SyncPayException('Status do webhook inválido.');
        }

        $apiResponse = $this->getTransaction($webhookData['idtransaction']);

        Log::info("[SYNCPAY] Transaction find: ", $apiResponse);

        if (!$apiResponse['situacao']) {
            throw new SyncPayException('Situação inválida.');
        }

        if (!in_array($apiResponse['situacao'], self::VALID_API_SUCCESS_STATUS) || !in_array($receivedSignature, self::VALID_COMPLETED_STATUS)) {
            throw new SyncPayException('Validação de status reprovada.');
        }

        if (!$this->validateTransactionAmount($deposit->amount, (float) $apiResponse['valor_bruto'])) {
            throw new SyncPayException('Valores não condizem.');
        }
    }

    private function validateTransactionAmount(float $expectedAmount, float $actualAmount): bool
    {
        return abs($expectedAmount - $actualAmount) < 0.01;
    }
}

/**
 * Exceção customizada para erros do SyncPay
 */
class SyncPayException extends \Exception
{
    // Você pode adicionar métodos específicos para tratamento de erros aqui
}
