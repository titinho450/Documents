<?php

namespace App\Services\DigitoPay;

use App\Models\Gateway;
use App\Models\GatewayCredential;
use Exception;
use Illuminate\Support\Facades\Log;

class DigitoPayService
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
        $this->apiUrl = 'https://api.digitopayoficial.com.br';
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
     * @throws DigitoPayException
     */
    public function cashIn(array $payload): array
    {
        try {

            $identifier = $this->gerarUuidV4();
            $data = [
                'dueDate' => now()->addMinutes(30),
                'identifier' => $identifier,
                'paymentOptions' => ['PIX'],
                'value' => $payload['value_cents'],
                'callbackUrl' => route('digito.webhook'),
                'person' => [
                    'name' => $payload['generator_name'],
                    'cpf' => $payload['generator_document']
                ]
            ];



            $response = $this->makeRequest('POST', '/api/deposit', $data, [
                'Authorization' => 'Bearer ' . $this->generateToken(),
            ]);

            $paymentCode = $response['pixCopiaECola'] ? $response['pixCopiaECola'] : null;
            $transactionId = $response['id'] ?? null;

            if (!$paymentCode || !$transactionId) {
                if (isset($response['message'])) {
                    throw new DigitoPayException('Erro ao gerar transação: ' . $response['message']);
                }

                throw new DigitoPayException('Erro ao gerar transação: ' . json_encode($response));
            }

            $this->logInfo("[TYPE]:DEPOSIT DIGITOPAY -> Depósito gerado com sucesso: " . json_encode($response));

            return [
                'success' => $response['success'] ?? false,
                'response' => json_encode($response),
                'data' => [
                    'status' => true,
                    'paymentCode' => $paymentCode ?? null,
                    'idTransaction' => $transactionId ?? null,
                    'paymentCodeBase64' => $response['qrCodeBase64'] ?? null,
                    'externalRefference' => $identifier ?? null
                ]
            ];
        } catch (DigitoPayException $s) {
            $this->logError("[TYPE]:[DIGITOPAY ERROR] -> " . $s->getMessage());
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
     * @throws DigitoPayException
     */
    public function processCashInWebhook(array $webhookData, ?callable $callback = null): array
    {
        try {
            $this->logInfo('Recebido webhook para processar: ' . json_encode($webhookData));

            $messages = $webhookData['id'] ?? null;

            if (!$messages) {
                throw new DigitoPayException('Informações do webhook não encontrada');
            }

            // Processar status da transação
            $transactionStatus = $webhookData['status'] ?? null;
            $transactionId = $webhookData['id'] ?? null;
            $amount = $webhookData['valor'] ?? null;

            if (!$transactionId) {
                throw new DigitoPayException('ID da transação não encontrado no webhook');
            }

            if (empty($amount)) {
                throw new DigitoPayException('Valor da transação não recebido no webhook');
            }

            if (empty($transactionStatus)) {
                throw new DigitoPayException('Status da transação não recebido no webhook DIGITO');
            }

            if ($transactionStatus !== 'REALIZADO') {
                throw new DigitoPayException('Status da transação não pago no webhook DIGITO');
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
            throw new DigitoPayException('Erro ao processar webhook: ' . $e->getMessage());
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
     * @throws DigitoPayException
     */
    public function cashOut($payloadParams): array
    {
        $ip = $this->getClientIp();
        $externalreference = $this->gerarUuidV4();
        $payload = [
            'paymentOptions' => ['PIX'],
            'identifier' => $payloadParams['externalreference'] ?? $externalreference,
            'value' => (float) $payloadParams['amount'],
            'person' => [
                'pixKeyTypes' => strtolower($payloadParams['pix_type']),
                'pixKey' => $payloadParams['pix_key'],
                'name' => $payloadParams['name'],
                'cpf' => $payloadParams['document']
            ],
            'callbackUrl' => $payloadParams['postbackUrl'] ?? null
        ];

        $this->logInfo("[TYPE]WITHDRAWN DIGITOPAY -> Iniciando processo de saque: " . json_encode($payload));

        try {

            $response = $this->makeRequest('POST', '/api/withdraw', $payload, [
                'Authorization' => 'Bearer ' . $this->generateToken()
            ]);

            if (!($response['withdraw'] ?? null)) {
                $this->logError('[TYPE]WITHDRAWN DIGITOPAY -> Dados recebidos inválidos: ' . json_encode($response));

                if ($response['mensagem']) {
                    throw new DigitoPayException($response['mensagem']);
                }

                throw new DigitoPayException('[TYPE]WITHDRAWN DIGITOPAY -> Dados recebidos inválidos: ' . json_encode($response));
            }

            $withdraw = $response['withdraw'];

            $idTransaction = $withdraw['id'] ?? null;

            $this->logInfo('[TYPE]WITHDRAWN DIGITOPAY -> Processando saque: ' . json_encode($response));

            if (empty($withdraw['status']) || empty($idTransaction)) {
                $this->logError('[TYPE]WITHDRAWN DIGITOPAY -> Status ou ID ausente: ' . json_encode($response));
                throw new DigitoPayException('Erro ao processar saque: ' . json_encode($response));
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
        } catch (DigitoPayException $e) {
            $this->logError('[TYPE]WITHDRAWN DIGITOPAY -> Erro ao processar saque: ' . $e->getMessage() . ' na linha ' . $e->getLine());
            throw new DigitoPayException($e->getMessage());
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
     * @throws DigitoPayException
     */
    public function processCashOutWebhook(array $webhookData, ?callable $callback = null): array
    {
        try {
            // Processar status da transação
            $withdraw = $webhookData['withdraw'];
            $transactionStatus = $withdraw['status'] ?? null;
            $transactionId = $withdraw['id'] ?? null;
            $amount = $withdraw['amount'] ?? null;

            if (!$transactionId || !$transactionStatus) {
                $this->logError("[TYPE]:PAYMENT DIGITOPAY -> Erro ao validar informações: " . json_encode($webhookData, JSON_PRETTY_PRINT));
                throw new DigitoPayException('ID da transação não encontrado no webhook');
            }

            if (!in_array($transactionStatus, self::VALID_COMPLETED_STATUS)) {
                throw new DigitoPayException('Webhook inválido');
            }

            if (empty($amount)) {
                throw new DigitoPayException('Valor não recebido no webhook - CashOut');
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
            throw new DigitoPayException('Erro ao processar webhook: ' . $e->getMessage());
        }
    }

    // Com callback example
    // $processor->processCashInWebhook($webhookData, $depositData, function($result, $webhookData, $depositData) {
    //     // Realize operações adicionais aqui
    //     // Por exemplo, atualizar o banco de dados, enviar notificação, etc.
    //     echo "Transação {$result['transaction_id']} processada com status: {$result['status']}";
    // });

    /**
     * Gerar Token de autenticação
     * @return string
     * @throws DigitoPayException
     */
    private function generateToken(): string
    {
        $url = '/api/token/api';

        $gateway = Gateway::where('slug', 'digitopay')->first();



        $clientId = GatewayCredential::where('gateway_id', $gateway->id)->where('key', 'clientId')->get('value');
        $secret = GatewayCredential::where('gateway_id', $gateway->id)->where('key', 'secret')->get('value');

        $digito_credentials = GatewayCredential::where('gateway_id', $gateway->id)->get();

        $this->logInfo('Gerando token [DIGITOPAY]: ' . json_encode($digito_credentials, JSON_PRETTY_PRINT));

        $response = $this->makeRequest('POST', $url, [
            'clientId' => $clientId[0]->value,
            'secret' => $secret[0]->value,
        ], []);

        if (!isset($response['accessToken'])) {
            throw new DigitoPayException('Erro ao gerar token: ' . json_encode($response));
        }

        return $response['accessToken'];
    }

    /**
     * Realiza requisições para a API usando cURL
     * 
     * @param string $method Método HTTP
     * @param string $endpoint Endpoint da API
     * @param array $data Dados da requisição
     * @param ?array $headers Headers adicionais
     * @param callable|null $callback Função de callback a ser executada após a requisição
     * @return array Resposta da API
     * @throws DigitoPayException
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

        $this->logInfo('Request Digito info: ' . json_encode($requestInfo));

        curl_close($curl);

        if ($error) {
            throw new DigitoPayException("Erro cURL: $error");
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

        if ($httpCode >= 400 && $httpCode != 422) {
            $responseBody = is_string($response) ? $response : json_encode($decodedResponse);
            $errorMessage = isset($decodedResponse['message'])
                ? $decodedResponse['message']
                : "Erro HTTP $httpCode";

            $this->logError("Erro na requisição: $errorMessage. Payload: " . json_encode([
                'payload' => $data,
                'response' => $decodedResponse,
                'raw_response' => $responseBody,
                'request_data' => $requestResult
            ], JSON_PRETTY_PRINT));

            throw new DigitoPayException(
                "Erro na requisição: $errorMessage. Payload: " . json_encode([
                    'payload' => $data,
                    'response' => $decodedResponse,
                    'raw_response' => $responseBody,
                    'request_data' => $requestResult
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
     * @throws DigitoPayException
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
                throw new DigitoPayException("Erro cURL: $error");
            }

            return $response;
        } catch (\Exception $e) {
            $this->logError('Failed to fetch transaction. Transaction ID: ' . $transactionId . '. Error: ' . $e->getMessage());
            throw new DigitoPayException('Failed to fetch transaction details: ' . $e->getMessage());
        }
    }

    /**
     * Valida a assinatura do webhook
     * 
     * @param array $webhookData Dados do webhook
     * @param array $depositData Dados do depósito para validação
     * @throws DigitoPayException
     */
    private function validateWebhookSignature(array $webhookData): void
    {
        $receivedSignature = $webhookData['transaction'] ?? null;

        if (!$receivedSignature) {
            throw new DigitoPayException('Status da transação não encontrada.');
        }

        if ($receivedSignature['status'] !== 'PAID_OUT') {
            throw new DigitoPayException('Status do webhook inválido.');
        }

        $apiResponse = $this->getTransaction($webhookData['idtransaction']);

        // $this->logInfo("[DIGITOPAY] Transaction find: " . json_encode($apiResponse));


        if (!in_array($receivedSignature['status'], self::VALID_COMPLETED_STATUS)) {
            throw new DigitoPayException('Validação de status reprovada.');
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
        $ip = '127.0.0.1';

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
        Log::channel('digito')->info($logMessage);
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
        Log::channel('digito')->error($logMessage);
    }
}

/**
 * Exceção customizada para erros do SyncPay
 */
class DigitoPayException extends Exception
{
    // Você pode adicionar métodos específicos para tratamento de erros aqui
}
