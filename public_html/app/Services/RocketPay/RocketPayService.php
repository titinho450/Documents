<?php

namespace App\Services\RocketPay;

use Exception;
use Illuminate\Support\Facades\Log;

class RocketPayService
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
        $this->apiUrl = 'https://api.rocketpaydigital.com';
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
     *     email: string,
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
     * @throws RocketPayException
     */
    public function cashIn(array $payload): array
    {
        try {

            $identifier = $this->gerarUuidV4();
            $data = [
                'dueDate' => now()->addMinutes(30),
                'requestNumber' => $identifier,
                'api-key' => env('ROCKET_API_KEY'),
                'amount' => $payload['value_cents'],
                'callbackUrl' => route('wizzionpay.webhook'),
                'client' => [
                    'name' => $payload['generator_name'],
                    'email' => $payload['email'],
                    'document' => $payload['generator_document'],
                    'phone' => $payload['phone']
                ]
            ];



            $response = $this->makeRequest('POST', '/v1/gateway/', $data, []);

            $paymentCode = $response['paymentCode'] ? $response['paymentCode'] : null;
            $transactionId = $response['idTransaction'] ?? null;

            if (!$paymentCode || !$transactionId) {
                if (isset($response['message'])) {
                    throw new RocketPayException('Erro ao gerar transação: ' . $response['message']);
                }

                throw new RocketPayException('Erro ao gerar transação: ' . json_encode($response));
            }

            $this->logInfo("[TYPE]:DEPOSIT ROCKETPAY -> Depósito gerado com sucesso: " . json_encode($response));

            return [
                'success' => $response['success'] ?? false,
                'response' => json_encode($response),
                'data' => [
                    'status' => true,
                    'paymentCode' => $paymentCode ?? null,
                    'idTransaction' => $transactionId ?? null,
                    'paymentCodeBase64' => $response['paymentCodeBase64'] ?? null,
                    'externalRefference' => $identifier ?? null
                ]
            ];
        } catch (RocketPayException $s) {
            $this->logError("[TYPE]:[ROCKETPAY ERROR] -> " . $s->getMessage());
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
     * @throws RocketPayException
     */
    public function processCashInWebhook(array $webhookData, ?callable $callback = null): array
    {
        try {
            $this->logInfo('Recebido webhook para processar: ' . json_encode($webhookData));

            $messages = $webhookData['idTransaction'] ?? null;

            if (!$messages) {
                throw new RocketPayException('Informações do webhook não encontrada');
            }

            // Processar status da transação
            $transactionStatus = $webhookData['status'] ?? null;
            $transactionId = $webhookData['idTransaction'] ?? null;

            if (!$transactionId) {
                throw new RocketPayException('ID da transação não encontrado no webhook');
            }

            if (empty($transactionStatus)) {
                throw new RocketPayException('Status da transação não recebido no webhook ROCKETPAY');
            }

            if ($transactionStatus !== 'paid') {
                throw new RocketPayException('Status da transação não pago no webhook ROCKETPAY');
            }

            $result = [
                'success' => true,
                'message' => 'Webhook processado com sucesso',
                'transaction_id' => $transactionId,
                'amount' => 0,
                'status' => $transactionStatus
            ];

            // Executar a função de callback se ela foi fornecida
            if ($callback !== null && is_callable($callback)) {
                $transactionData = [
                    'id' => $transactionId,
                    'status' => $transactionStatus,
                    'amount' => 0,
                ];

                call_user_func($callback, $result, $transactionData);
            }

            return $result;
        } catch (Exception $e) {
            $this->logError('Erro ao processar webhook: ' . $e->getMessage() . ' - Dados: ' . json_encode($webhookData));
            throw new RocketPayException('Erro ao processar webhook: ' . $e->getMessage());
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
     * @throws RocketPayException
     */
    public function cashOut($payloadParams): array
    {
        $ip = $this->getClientIp();

        $payload = [
            'typepix' => $payloadParams['pix_type'], // 'cpf', 'email', 'phone'
            'keypix' => $payloadParams['pix_key'],
            'name' => $payloadParams['name'],
            'cpf' => $payloadParams['document'],
            'amount' => $payloadParams['amount'], // ou use $valor formatado
            'tipo_pagamento' => "externo",
            'type' => 'pix',
            'api-key' => env('ROCKET_API_KEY'),
        ];

        $this->logInfo("[TYPE]WITHDRAWN ROCKETPAY -> Iniciando processo de saque: " . json_encode($payload));

        try {

            $response = $this->makeRequest('POST', '/c1/cashout/', $payload, []);

            if (!isset($response['status']) || $response['status'] === 'error') {
                $this->logError('[TYPE]WITHDRAWN ROCKETPAY -> Dados recebidos inválidos: ' . json_encode($response));

                if ($response['mensagem']) {
                    throw new RocketPayException($response['mensagem']);
                }

                throw new RocketPayException('[TYPE]WITHDRAWN ROCKETPAY -> Dados recebidos inválidos: ' . json_encode($response));
            }


            $idTransaction = $this->gerarUuidV4();

            $this->logInfo('[TYPE]WITHDRAWN ROCKETPAY -> Processando saque: ' . json_encode($response));

            if (empty($idTransaction)) {
                $this->logError('[TYPE]WITHDRAWN ROCKETPAY -> Status ou ID ausente: ' . json_encode($response));
                throw new RocketPayException('Erro ao processar saque: ' . json_encode($response));
            }

            return [
                'success' => true,
                'data' => [
                    'amount' => $payloadParams['amount'],
                    'pixKey' => $payloadParams['pix_key'],
                    'pixType' => $payloadParams['pix_type'],
                    'beneficiaryName' => $payloadParams['name'],
                    'beneficiaryDocument' => $payloadParams['document'],
                    'externalreference' => $idTransaction ?? null,
                    'status' => 'processed',
                    'valor_liquido' => $payloadParams['amount'],
                    'idTransaction' => $idTransaction,
                ]
            ];
        } catch (RocketPayException $e) {
            $this->logError('[TYPE]WITHDRAWN ROCKETPAY -> Erro ao processar saque: ' . $e->getMessage() . ' na linha ' . $e->getLine());
            throw new RocketPayException($e->getMessage());
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
     * @throws RocketPayException
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
                $this->logError("[TYPE]:PAYMENT ROCKETPAY -> Erro ao validar informações: " . json_encode($webhookData, JSON_PRETTY_PRINT));
                throw new RocketPayException('ID da transação não encontrado no webhook');
            }

            if (!in_array($transactionStatus, self::VALID_COMPLETED_STATUS)) {
                throw new RocketPayException('Webhook inválido');
            }

            if (empty($amount)) {
                throw new RocketPayException('Valor não recebido no webhook - CashOut');
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
            throw new RocketPayException('Erro ao processar webhook: ' . $e->getMessage());
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
     * @throws RocketPayException
     */
    private function generateToken(): string
    {
        $url = '/api/token/api';

        $clientId = env('ROCKETPAY_CLIENT_ID');
        $secret = env('ROCKETPAY_CLIENT_SECRET');


        $response = $this->makeRequest('POST', $url, [
            'clientId' => $clientId,
            'secret' => $secret,
        ], []);

        if (!isset($response['accessToken'])) {
            throw new RocketPayException('Erro ao gerar token: ' . json_encode($response));
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
     * @throws RocketPayException
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

        $this->logInfo('Request RocketPay info: ' . json_encode($requestInfo));

        curl_close($curl);

        if ($error) {
            throw new RocketPayException("Erro cURL: $error");
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

            throw new RocketPayException(
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
     * @throws RocketPayException
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
                throw new RocketPayException("Erro cURL: $error");
            }

            return $response;
        } catch (\Exception $e) {
            $this->logError('Failed to fetch transaction. Transaction ID: ' . $transactionId . '. Error: ' . $e->getMessage());
            throw new RocketPayException('Failed to fetch transaction details: ' . $e->getMessage());
        }
    }

    /**
     * Valida a assinatura do webhook
     * 
     * @param array $webhookData Dados do webhook
     * @param array $depositData Dados do depósito para validação
     * @throws RocketPayException
     */
    private function validateWebhookSignature(array $webhookData): void
    {
        $receivedSignature = $webhookData['transaction'] ?? null;

        if (!$receivedSignature) {
            throw new RocketPayException('Status da transação não encontrada.');
        }

        if ($receivedSignature['status'] !== 'PAID_OUT') {
            throw new RocketPayException('Status do webhook inválido.');
        }

        $apiResponse = $this->getTransaction($webhookData['idtransaction']);

        // $this->logInfo("[ROCKETPAY] Transaction find: " . json_encode($apiResponse));


        if (!in_array($receivedSignature['status'], self::VALID_COMPLETED_STATUS)) {
            throw new RocketPayException('Validação de status reprovada.');
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
        Log::channel('rocket')->info($logMessage);
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
        Log::channel('rocket')->error($logMessage);
    }
}

/**
 * Exceção customizada para erros do SyncPay
 */
class RocketPayException extends Exception
{
    // Você pode adicionar métodos específicos para tratamento de erros aqui
}
