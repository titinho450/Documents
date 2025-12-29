<?php

namespace App\Services\VizionPay;

use App\Models\Gateway;
use App\Models\GatewayCredential;
use Exception;
use Illuminate\Support\Facades\Log;

class VizionPayService
{
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

    public function __construct(string $externalreference = '')
    {
        $this->apiUrl = 'https://app.vizzionpay.com/api/v1';
        $this->externalreference = $externalreference;
    }


    public function gerarUuidV4(): string
    {
        $data = random_bytes(16);

        // Ajusta os bits para versão e variante conforme o padrão UUID v4
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // versão 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variante RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function normalizarNome(string $nome): string
    {
        // Converte para UTF-8 se necessário
        $nome = mb_convert_encoding($nome, 'UTF-8', 'UTF-8');

        // Remove acentos
        $nome = iconv('UTF-8', 'ASCII//TRANSLIT', $nome);

        // Remove quaisquer caracteres não alfanuméricos adicionais (opcional)
        $nome = preg_replace('/[^A-Za-z0-9\s]/', '', $nome);

        // Remove espaços extras
        $nome = trim(preg_replace('/\s+/', ' ', $nome));

        return $nome;
    }

    private function decodeUnicodeString(string $text): string
    {
        return json_decode('"' . addcslashes($text, '"\\') . '"');
    }

    /**
     * Realiza uma operação de Cash In (recebimento)
     * 
     * @param array{
     *     value_cents: float,
     *     generator_name: string,
     *     generator_document: string,
     *     phone: string,
     *     email?: string,
     *     expiration_time?: int,
     *     external_reference?: string,
     *     callback_url?: string
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
     * } Resposta do servidor
     * @throws VizionPayException
     */
    public function cashIn(array $payload): array
    {
        try {

            $identifier = $this->gerarUuidV4();
            $data = [
                'identifier' => $identifier,
                'amount' => $payload['value_cents'],
                'callbackUrl' => $payload['callback_url'] ?? null,
                'client' => [
                    'name' => $payload['generator_name'],
                    'email' => $payload['email'] ?? 'suport@vizionpay.com',
                    'phone' => $payload['phone'],
                    'document' => $payload['generator_document']
                ]
            ];

            $gateway = Gateway::where('slug', 'vizionpay')->first();



            $public_key = GatewayCredential::where('gateway_id', $gateway->id)->where('key', 'x-public-key')->value('value');
            $secret_key = GatewayCredential::where('gateway_id', $gateway->id)->where('key', 'x-secret-key')->value('value');



            $response = $this->makeRequest('POST', '/gateway/pix/receive', $data, [
                'x-public-key' => $public_key,
                'x-secret-key' => $secret_key,
            ]);

            $paymentCode = $response['pix'] ? $response['pix']['code'] : null;
            $transactionId = $response['transactionId'] ?? null;

            if (!$paymentCode || !$transactionId) {
                if (isset($response['message'])) {
                    throw new VizionPayException('Erro ao gerar transação: ' . $response['message']);
                }

                throw new VizionPayException('Erro ao gerar transação: ' . json_encode($response));
            }

            $this->logInfo("[TYPE]:DEPOSIT VIZIONPAY -> Depósito gerado com sucesso: " . json_encode($response));

            return [
                'success' => true,
                'response' => json_encode($response),
                'data' => [
                    'status' => true,
                    'paymentCode' => $paymentCode ?? null,
                    'idTransaction' => $transactionId ?? null,
                    'paymentCodeBase64' => $response['pix']['base64'] ?? null,
                    'externalRefference' => $identifier ?? null
                ]
            ];
        } catch (VizionPayException $s) {
            $this->logError("[TYPE]:[VIZIONPAY ERROR] -> " . $s->getMessage());
            throw $s;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Processa o webhook de Cash In
     * 
     * @param array $webhookData Dados recebidos no webhook
     * @param array $depositData Dados do depósito para validação
     * @param callable|null $callback Função de callback para ser executada após o processamento
     * @return array{
     *      success: boolean,
     *      message: string,
     *      transaction_id: string,
     *      amount: float,
     *      status: string
     * } Status do processamento
     * @throws VizionPayException
     */
    public function processCashInWebhook(array $webhookData, ?callable $callback = null): array
    {
        try {
            $this->logInfo('Recebido webhook para processar: ' . json_encode($webhookData));

            $event = $webhookData['event'] ?? null;


            if (empty($event)) {
                throw new VizionPayException('Evento do webhook não identificado');
            }

            $transaction = $webhookData['transaction'] ?? null;

            if ($transaction['status'] !== 'COMPLETED') {
                throw new VizionPayException('Transação não confirmada pelo gateway');
            }

            $messages = $webhookData['transaction'] ?? null;

            if (!$messages) {
                throw new VizionPayException('Informações do webhook não encontrada');
            }

            // Processar status da transação
            $transactionStatus = $webhookData['transaction']['status'] ?? null;
            $transactionId = $webhookData['transaction']['id'] ?? null;
            $amount = $webhookData['transaction']['amount'] ?? null;

            if (!$transactionId) {
                throw new VizionPayException('ID da transação não encontrado no webhook');
            }

            if (empty($amount)) {
                throw new VizionPayException('Valor da transação não recebido no webhook');
            }

            $result = [
                'success' => true,
                'message' => 'Webhook processado com sucesso',
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'status' => $transactionStatus
            ];

            // Executar a função de callback se ela foi fornecida
            if ($callback !== null && is_callable($callback)) {
                $transactionData = [
                    'id' => $transactionId,
                    'status' => $transactionStatus,
                    'amount' => $amount,
                ];

                call_user_func($callback, $result, $transactionData);
            }

            return $result;
        } catch (Exception $e) {
            $this->logError('Erro ao processar webhook: ' . $e->getMessage() . ' - Dados: ' . json_encode($webhookData));
            throw new VizionPayException('Erro ao processar webhook: ' . $e->getMessage());
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
     *     ip?: string,
     *     postbackUrl?: string,
     *     externalreference?: string
     * } $payloadParams
     * 
     * @return array{
     *     success: boolean,
     *     data: array{
     *         amount: float,
     *         pixKey: string, 
     *         pix_type: 'cpf'|'email'|'phone',
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
     * @throws VizionPayException
     */
    public function cashOut($payloadParams): array
    {
        $ip = $this->getClientIp();
        $externalreference = $this->gerarUuidV4();
        $payload = [
            'identifier' => $payloadParams['externalreference'] ?? $externalreference,
            'amount' => (float) $payloadParams['amount'],
            'pix' => [
                'type' => strtolower($payloadParams['pix_type']),
                'key' => $payloadParams['pix_key'],
            ],
            'owner' => [
                'ip' => $payloadParams['ip'] ?? $ip,
                'name' => $this->normalizarNome($payloadParams['name']),
                'document' => [
                    'type' => 'cpf',
                    'number' => $payloadParams['document']
                ],
            ],
            'callbackUrl' => route('vizion.webhook', 'payment')
        ];

        $this->logInfo("[TYPE]WITHDRAWN VIZIONPAY -> Iniciando processo de saque: " . json_encode($payload));

        try {
            $gateway = Gateway::where('slug', 'vizionpay')->first();



            $public_key = GatewayCredential::where('gateway_id', $gateway->id)->where('key', 'x-public-key')->value('value');
            $secret_key = GatewayCredential::where('gateway_id', $gateway->id)->where('key', 'x-secret-key')->value('value');

            if (!$public_key || !$secret_key) {
                $this->logError('[TYPE]WITHDRAWN VIZION -> Erro ao gerar credenciais: ' . json_encode($token));
                throw new VizionPayException('[TYPE]WITHDRAWN VIZIONPAY -> Erro ao gerar token: ' . json_encode($token));
            }

            $response = $this->makeRequest('POST', '/gateway/transfers', $payload, [
                'x-public-key' => $public_key,
                'x-secret-key' => $secret_key,
            ]);

            if (!($response['withdraw'] ?? null)) {
                $this->logError('[TYPE]WITHDRAWN VIZIONPAY -> Dados recebidos inválidos: ' . json_encode($response));
                throw new VizionPayException('[TYPE]WITHDRAWN VIZIONPAY -> Dados recebidos inválidos: ' . json_encode($response));
            }

            $withdraw = $response['withdraw'];

            $idTransaction = $withdraw['id'] ?? null;

            $this->logInfo('[TYPE]WITHDRAWN VIZIONPAY -> Processando saque: ' . json_encode($response));

            if (empty($withdraw['status']) || empty($idTransaction)) {
                $this->logError('[TYPE]WITHDRAWN VIZIONPAY -> Status ou ID ausente: ' . json_encode($response));
                throw new VizionPayException('Erro ao processar saque: ' . json_encode($response));
            }

            return [
                'success' => true,
                'data' => [
                    'amount' => $withdraw['amount'],
                    'pixKey' => $payloadParams['pix_key'],
                    'pixType' => $payloadParams['pix_type'],
                    'beneficiaryName' => $payloadParams['name'],
                    'beneficiaryDocument' => $payloadParams['document'],
                    'externalreference' => $response['webhookToken'] ?? null,
                    'status' => $withdraw['status'],
                    'valor_liquido' => $withdraw['amount'] ? ($withdraw['amount'] - $withdraw['feeAmount']) : null,
                    'idTransaction' => $idTransaction,
                ]
            ];
        } catch (VizionPayException $e) {
            $this->logError('[TYPE]WITHDRAWN VIZIONPAY -> Erro ao processar saque: ' . $e->getMessage() . ' na linha ' . $e->getLine());
            throw new VizionPayException('Erro ao processar webhook: ' . $e->getMessage());
        }
    }

    /**
     * Processa o webhook de Cash Out
     * 
     * @param array $webhookData Dados recebidos no webhook
     * @param callable|null $callback Função de callback para ser executada após o processamento
     * @return array{
     *      success: boolean,
     *      message: string,
     *      transaction_id: string,
     *      amount: float,
     *      status: string
     * } Status do processamento
     * @throws VizionPayException
     */
    public function processCashOutWebhook(array $webhookData, ?callable $callback = null): array
    {
        try {


            // Processar status da transação
            /** @var array $withdraw */
            $withdraw = $webhookData['withdraw'];
            $transactionStatus = $withdraw['status'] ?? null;
            $transactionId = $withdraw['id'] ?? null;
            $amount = $withdraw['amount'] ?? null;


            if (!$transactionId || !$transactionStatus) {
                $this->logError("[TYPE]:PAYMENT VIZIONPAY -> Erro ao validar informações: " . json_encode($webhookData, JSON_PRETTY_PRINT));
                throw new VizionPayException('ID da transação não encontrado no webhook');
            }

            if (!in_array($transactionStatus, self::VALID_COMPLETED_STATUS)) {
                throw new VizionPayException('Webhook inválido');
            }

            if (empty($amount)) {
                throw new VizionPayException('Valor não recebido no webhook - CashOut');
            }

            $this->logInfo("[VIZION WEBHOOK CASHOUT]: Iniciando processo de saque TRANSACTION_ID: {$transactionId}");

            $result = [
                'success' => true,
                'message' => 'Webhook processado com sucesso',
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'status' => $transactionStatus
            ];

            // Executar a função de callback se ela foi fornecida
            if ($callback !== null && is_callable($callback)) {
                $transactionData = [
                    'id' => $transactionId,
                    'status' => $transactionStatus,
                    'amount' => $amount,
                ];

                call_user_func($callback, $result, $transactionData);
            }

            return $result;
        } catch (Exception $e) {
            throw new VizionPayException('Erro ao processar webhook: ' . $e->getMessage());
        }
    }

    // Com callback example
    // $processor->processCashInWebhook($webhookData, $depositData, function($result, $webhookData, $depositData) {
    //     // Realize operações adicionais aqui
    //     // Por exemplo, atualizar o banco de dados, enviar notificação, etc.
    //     echo "Transação {$result['transaction_id']} processada com status: {$result['status']}";
    // });

    /**
     * Realiza requisições para a API usando cURL
     * 
     * @param string $method Método HTTP
     * @param string $endpoint Endpoint da API
     * @param array $data Dados da requisição
     * @param ?array $headers Headers adicionais
     * @param callable|null $callback Função de callback a ser executada após a requisição
     * @return array Resposta da API
     * @throws VizionPayException
     */
    private function makeRequest(string $method, string $endpoint, array $data = [], array $headers = [], ?callable $callback = null): array
    {
        $url = $this->apiUrl . $endpoint;

        $curlHeaders = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        foreach ($headers as $key => $value) {
            $curlHeaders[] = "$key: $value";
        }

        $curl = curl_init();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ];

        if (!empty($data) && ($method === 'POST' || $method === 'PUT')) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }

        curl_setopt_array($curl, $options);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);

        // Capturar informações adicionais para depuração
        $requestInfo = [
            'url' => $url,
            'method' => $method,
            'headers' => $curlHeaders,
            'data' => $data,
            'http_code' => $httpCode,
            'curl_error' => $error,
            'raw_response' => $response
        ];

        $this->logInfo('Request Vizion info: ' . json_encode($requestInfo));

        curl_close($curl);

        if ($error) {
            throw new VizionPayException("Erro cURL: $error");
        }

        $decodedResponse = json_decode($response, true);

        // Informações completas da requisição e resposta para o callback
        $requestResult = [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'response' => $decodedResponse,
            'raw_response' => $response,
            'request' => [
                'url' => $url,
                'method' => $method,
                'data' => $data,
                'headers' => $curlHeaders
            ]
        ];

        // Executar o callback se fornecido
        if ($callback !== null && is_callable($callback)) {
            call_user_func($callback, $requestResult);
        }

        if ($httpCode >= 400) {
            $responseBody = is_string($response) ? $response : json_encode($decodedResponse);
            $errorMessage = isset($decodedResponse['message'])
                ? $decodedResponse['message']
                : "Erro HTTP $httpCode";

            throw new VizionPayException(
                "Erro na requisição: $errorMessage. Payload: " . json_encode([
                    'payload' => $data,
                    'response' => $decodedResponse,
                    'raw_response' => $responseBody
                ]),
                $httpCode
            );
        }

        return $decodedResponse;
    }


    /**
     * Get transaction details
     * 
     * @param string $transactionId
     * @return array
     * @throws VizionPayException
     */
    public function getTransaction(string $transactionId): array
    {
        try {
            $public_key = $setting->getAttributes()['suitpay_cliente_id'];
            $secret_key = $setting->getAttributes()['suitpay_cliente_secret'];

            $url = '/gateway/transactions?id=' . $transactionId;

            $response = $this->makeRequest('GET', $url, [], [
                'x-public-key: ' . $public_key,
                'x-secret-key: ' . $secret_key,
            ]);

            curl_close($curl);

            if ($error) {
                throw new VizionPayException("Erro cURL: $error");
            }

            return $response;
        } catch (\Exception $e) {
            $this->logError('Failed to fetch transaction. Transaction ID: ' . $transactionId . '. Error: ' . $e->getMessage());
            throw new VizionPayException('Failed to fetch transaction details: ' . $e->getMessage());
        }
    }

    /**
     * Valida a assinatura do webhook
     * 
     * @param array $webhookData Dados do webhook
     * @param array $depositData Dados do depósito para validação
     * @throws VizionPayException
     */
    private function validateWebhookSignature(array $webhookData): void
    {
        $receivedSignature = $webhookData['transaction'] ?? null;

        if (!$receivedSignature) {
            throw new VizionPayException('Status da transação não encontrada.');
        }

        if ($receivedSignature['status'] !== 'PAID_OUT') {
            throw new VizionPayException('Status do webhook inválido.');
        }

        $apiResponse = $this->getTransaction($webhookData['idtransaction']);

        // $this->logInfo("[VIZIONPAY] Transaction find: " . json_encode($apiResponse));


        if (!in_array($receivedSignature['status'], self::VALID_COMPLETED_STATUS)) {
            throw new VizionPayException('Validação de status reprovada.');
        }
    }

    /**
     * Valida se o valor do depósito corresponde ao valor retornado pela API
     * 
     * @param float $expectedAmount
     * @param float $actualAmount
     * @return bool
     */
    private function validateTransactionAmount(float $expectedAmount, float $actualAmount): bool
    {
        return abs($expectedAmount - $actualAmount) < 0.01;
    }

    /**
     * Obtém o IP IPv4 do cliente
     * 
     * @return string
     */
    private function getClientIp(): string
    {
        $ip = '89.116.115.30';

        $candidatos = [
            $_SERVER['HTTP_CLIENT_IP'] ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        foreach ($candidatos as $valor) {
            if ($valor) {
                // Se houver múltiplos IPs no header (ex: "1.2.3.4, 5.6.7.8")
                $ips = explode(',', $valor);
                foreach ($ips as $ipPossivel) {
                    $ipPossivel = trim($ipPossivel);
                    if (filter_var($ipPossivel, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        return $ipPossivel;
                    }
                }
            }
        }

        return $ip; // fallback para localhost
    }

    /**
     * Log de informações
     * 
     * @param string $message
     * @return void
     */
    private function logInfo(string $message): void
    {
        $logMessage = '[' . date('Y-m-d H:i:s') . '] INFO: ' . $message . PHP_EOL;
        Log::channel('vizion')->info($logMessage);
    }

    /**
     * Log de erros
     * 
     * @param string $message
     * @return void
     */
    private function logError(string $message): void
    {
        $logMessage = '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $message . PHP_EOL;
        Log::channel('vizion')->error($logMessage);
    }
}

/**
 * Exceção customizada para erros do SyncPay
 */
class VizionPayException extends Exception
{
    // Você pode adicionar métodos específicos para tratamento de erros aqui
}
