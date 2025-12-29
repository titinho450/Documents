<?php

namespace App\Services\SyncPayment;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use App\Services\SyncPayment\SyncPaymentException;
use App\Services\SyncPayment\SyncPaymentAuthException;
use App\Services\SyncPayment\SyncPaymentValidationException;
use App\Services\SyncPayment\SyncPaymentApiException;
use Exception;
use Throwable;

class SyncPaymentService
{
    private string $apiUrl;
    private string $clientID;
    private string $clientSecret;

    // A SyncPaymentException (antiga) foi removida do arquivo e transformada em um namespace (veja acima)

    private const VALID_API_SUCCESS_STATUS = [
        'PAGO',
        'APROVADO',
        'PAGAMENTO_APROVADO',
        'COMPLETED',
        "PAID_OUT",
        'completed',
    ];

    /**
     * Constructor
     *
     * @param string $apiUrl
     * @param string $logFile (Mantido por compatibilidade, mas o Log::usa o log padrão)
     */
    public function __construct(
        string $apiUrl = 'https://api.syncpayments.com.br/',
        string $logFile = 'storage/logs/syncpayments.log'
    ) {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->clientID = env('SYNCPAYMENT_CLIENT_ID');
        $this->clientSecret = env('SYNCPAYMENT_CLIENT_SECRET');

        if (empty($this->clientID) || empty($this->clientSecret)) {
            // Lança exceção padrão, pois o service não pode funcionar sem as credenciais
            throw new \Exception('API Key é obrigatória. Verifique as variáveis de ambiente SYNCPAYMENT_CLIENT_ID e SYNCPAYMENT_CLIENT_SECRET.');
        }
    }

    /**
     * Método centralizado para requisições HTTP usando Laravel HTTP Client
     *
     * @param string $method
     * @param string $path
     * @param array $data
     * @param array $headers
     * @return array
     * @throws SyncPaymentAuthException
     * @throws SyncPaymentValidationException
     * @throws SyncPaymentApiException
     */
    private function makeRequest(string $method, string $path, array $data = [], array $headers = []): array
    {
        $url = $this->apiUrl . $path;

        $this->logInfo("[REQUEST]: {$method} {$url} | Data: " . json_encode($data));

        try {
            // Usa o Laravel HTTP Client
            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->connectTimeout(10)
                ->asJson()
                ->send($method, $url, [
                    'json' => $data, // Usa 'json' para POST/PUT/PATCH, envia como JSON
                ]);

            // Trata erros HTTP comuns da API e lança as exceções customizadas
            if ($response->status() === 401) {
                // Tenta pegar a mensagem da resposta, senão usa a padrão da exceção
                $message = $response->json('message', 'Não autorizado.');
                throw new SyncPaymentAuthException($message, 401);
            }

            if ($response->status() === 422) {
                $responseBody = $response->json();
                $message = $responseBody['message'] ?? 'Erro de parâmetro.';
                $errors = $responseBody['errors'] ?? [];
                throw new SyncPaymentValidationException($message, 422, $errors);
            }

            // Lança exceções para quaisquer outros códigos de erro (4xx ou 5xx)
            // O método throw lança Illuminate\Http\Client\RequestException
            $response->throw(function (Response $response, \Exception $e) {
                // Captura a RequestException e re-lança como SyncPaymentApiException
                $message = "Erro HTTP {$response->status()} na API: " . ($response->json('message') ?? $response->body());
                throw new SyncPaymentApiException($message, $response->status(), $response);
            });

            // Se a resposta for bem-sucedida, decodifica o JSON.
            // O Laravel HTTP Client já faz a decodificação JSON, mas checamos a validade.
            $responseData = $response->json();

            $this->logInfo("[RESPONSE]: Status {$response->status()} | " . json_encode($responseData));

            if (is_null($responseData)) {
                throw new SyncPaymentApiException('Resposta da API não é um JSON válido: ' . $response->body(), 500, $response);
            }

            return $responseData;
        } catch (SyncPaymentException $e) {
            // Re-lança as customizadas
            throw $e;
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // Isso deve ser coberto pelo $response->throw() acima, mas é um fallback seguro
            $message = "Erro de Requisição HTTP (Timeout/Conexão): " . $e->getMessage();
            throw new SyncPaymentApiException($message, $e->getCode(), null);
        } catch (Throwable $e) {
            $this->logError('[ERRO INESPERADO NA REQUISIÇÃO]: ' . $e->getMessage());
            throw new SyncPaymentException('Erro interno no Service: ' . $e->getMessage());
        }
    }


    /**
     * Gera o token de autorização
     * @return string
     * @throws SyncPaymentException
     */
    private function generateToken(): string
    {
        $path = '/api/partner/v1/auth-token';

        $data = [
            'client_id' => $this->clientID,
            'client_secret' => $this->clientSecret,
        ];

        try {
            $response = $this->makeRequest('POST', $path, $data, []);

            // Validação de estrutura de resposta de sucesso
            if (empty($response['access_token'])) {
                $this->logError('[ERRO NA GERAÇÃO DO TOKEN]: Token não encontrado na resposta');
                throw new SyncPaymentApiException('Token de acesso não identificado na resposta da API.');
            }

            return $response['access_token'];
        } catch (SyncPaymentException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logError('[ERRO INESPERADO NA GERAÇÃO DO TOKEN]: ' . $e->getMessage());
            throw new SyncPaymentException('Falha ao gerar o Token de Acesso.');
        }
    }

    // O método getWebhooks foi mantido, mas agora usa makeRequest

    /**
     * Busca os webhooks
     * @throws SyncPaymentException
     */
    public function getBalance(): float
    {
        try {
            $token = $this->generateToken();
            $headers = ['Authorization' => 'Bearer ' . $token];
            $path = '/api/partner/v1/balance';

            $response = $this->makeRequest('GET', $path, [], $headers);

            $this->logInfo("[LISTAGEM DE WEBHOOKS]: " . json_encode($response, JSON_PRETTY_PRINT));

            // Validação de estrutura de resposta de sucesso
            if (!isset($response['balance'])) {
                $this->logError('[ERRO AO BUSCAR SALDO]: Resposta inválida - missing data balance');
                throw new SyncPaymentApiException('Resposta inválida ao buscar SALDO: estrutura inesperada.');
            }

            return (float) $response['balance'];
        } catch (SyncPaymentException $e) {
            throw $e;
        }
    }

    /**
     * Busca os webhooks
     * @throws SyncPaymentException
     */
    private function getWebhooks(): array
    {
        try {
            $token = $this->generateToken();
            $headers = ['Authorization' => 'Bearer ' . $token];
            $path = '/api/partner/v1/webhooks';

            $response = $this->makeRequest('GET', $path, [], $headers);

            $this->logInfo("[LISTAGEM DE WEBHOOKS]: " . json_encode($response, JSON_PRETTY_PRINT));

            // Validação de estrutura de resposta de sucesso
            if (!isset($response['data']) || !is_array($response['data'])) {
                $this->logError('[ERRO AO BUSCAR WEBHOOKS]: Resposta inválida - missing data array');
                throw new SyncPaymentApiException('Resposta inválida ao buscar webhooks: estrutura inesperada.');
            }

            return $response['data'];
        } catch (SyncPaymentException $e) {
            throw $e;
        }
    }

    // O método registerWebHook foi mantido, mas agora usa makeRequest

    /**
     * Registra o webhook
     * @throws SyncPaymentException
     */
    private function registerWebHook(string $webhookUrl, string $event = 'cashin'): void
    {
        try {
            $path = '/api/partner/v1/webhooks';

            $existing_webhooks = $this->getWebhooks();

            if (!is_array($existing_webhooks)) {
                $existing_webhooks = [];
            }

            $found = array_filter($existing_webhooks, function ($webhook) use ($webhookUrl) {
                return isset($webhook['url']) && $webhook['url'] === $webhookUrl;
            });

            if (empty($found)) {
                $token = $this->generateToken();
                $headers = ['Authorization' => 'Bearer ' . $token];
                $data = [
                    'title' => "Webhook de depósitos",
                    'url' => $webhookUrl,
                    'event' => $event,
                    'trigger_all_products' => true,
                ];

                $this->logInfo("[REGISTRO DE WEBHOOK]: " . json_encode($data, JSON_PRETTY_PRINT));

                $response = $this->makeRequest('POST', $path, $data, $headers);

                // Validação de estrutura de resposta de sucesso
                if (!is_array($response) || empty($response['token'])) {
                    $this->logError('[ERRO NA GERAÇÃO DO TOKEN DO WEBHOOK]: ' . json_encode($response));
                    throw new SyncPaymentApiException('Token do webhook não identificado na resposta da API');
                }

                $this->logInfo("[WEBHOOK REGISTRADO COM SUCESSO]: Token recebido");
            } else {
                $this->logInfo("[WEBHOOK JÁ EXISTE]: Não foi necessário registrar novamente");
            }
        } catch (SyncPaymentException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->logError('[ERRO NO REGISTRO DO WEBHOOK]: ' . $e->getMessage());
            throw new SyncPaymentException('Erro ao registrar webhook: ' . $e->getMessage());
        }
    }

    /**
     * Valida os dados do payload para Cash In
     *
     * @param array $payload
     * @throws SyncPaymentValidationException
     */
    private function validateCashInPayload(array $payload): void
    {
        $required = ['value_cents', 'generator_name', 'generator_document', 'generator_email'];

        foreach ($required as $field) {
            if (empty($payload[$field])) {
                throw new SyncPaymentValidationException("Campo obrigatório ausente: {$field}");
            }
        }

        if (!is_numeric($payload['value_cents']) || $payload['value_cents'] <= 0) {
            throw new SyncPaymentValidationException('O valor deve ser um número positivo (value_cents)');
        }

        $document = preg_replace('/\D/', '', $payload['generator_document']);
        // A validação de CPF/CNPJ deve ser mais robusta, mas mantive o básico para o exemplo
        if (strlen($document) < 11 || strlen($document) > 14) {
            throw new SyncPaymentValidationException('CPF/CNPJ inválido (generator_document)');
        }
        // Removida a linha if (isset($payload['generator_document']) && !empty($payload['generator_document'])) { throw new SyncPaymentException('CPF inválido'); }
        // pois ela lançava uma exceção mesmo que o campo estivesse presente (erro lógico no código original)
    }

    /**
     * Realiza uma operação de Cash In (recebimento - PIX)
     *
     * @param array{
     * value_cents: float,
     * generator_document: string,
     * generator_name: string,
     * generator_email: string,
     * external_reference?: string,
     * }$payload
     *
     * @return array{
     * success: boolean,
     * response: array,
     * data: array{
     *      externalReference: string,
     *      status: boolean,
     *      paymentCode: string,
     *      idTransaction: string
     * }
     * }
     * @throws SyncPaymentException
     */
    public function cashIn(array $payload): array
    {
        try {
            $this->logInfo("[RAW PAYLOAD CASH IN]: " . json_encode($payload, JSON_PRETTY_PRINT));

            // 1. VALIDAÇÃO LOCAL DO PAYLOAD
            $this->validateCashInPayload($payload);

            $token = $this->generateToken();

            // Lógica do telefone aleatório mantida, mas é recomendável enviar o telefone real do cliente, se possível.
            $phones = [
                '21995585701',
                '21995585702',
                '21995585703',
                '21995585704',
                '21995585705',
                '21995585706',
                '21995585707',
            ];
            $randomPhone = $phones[array_rand($phones)];

            $document = preg_replace('/\D/', '', $payload['generator_document']);

            $data = [
                'amount' => (float)$payload['value_cents'],
                'description' => 'Venda de curso',
                'client' => [
                    'name' => $payload['generator_name'],
                    // Valor padrão melhorado para refletir a necessidade de um e-mail válido
                    'email' => $payload['generator_email'] ?? 'naoinformado@syncpay.com',
                    'cpf' => $document, // Não é mais opcional após a validação
                    'phone' => $randomPhone // Mantido
                ]
            ];

            // 2. REGISTRA O WEBHOOK (OPERAÇÃO INDEPENDENTE)
            $webhookUrl = route('syncpayment.webhook', ['type' => 'cashin']);
            $this->registerWebHook($webhookUrl);


            if (isset($payload['external_reference'])) {
                $data['external_reference'] = $payload['external_reference'];
            }

            $path = '/api/partner/v1/cash-in';
            $headers = ['Authorization' => 'Bearer ' . $token];

            $this->logInfo("[REQUEST CASH IN]: " . json_encode($data, JSON_PRETTY_PRINT));

            // 3. REQUISIÇÃO PARA A API
            $response = $this->makeRequest('POST', $path, $data, $headers);

            // 4. VALIDAÇÃO DE ESTRUTURA DE SUCESSO DA API
            // Resposta Esperada (200 OK): pix_code, identifier
            if (empty($response['pix_code']) || empty($response['identifier'])) {
                $this->logError('[ERRO ESTRUTURAL CASH IN]: ' . json_encode($response));
                throw new SyncPaymentApiException('Resposta de Cash In inválida: pix_code ou identifier ausente.');
            }

            $this->logInfo("[CASH IN SUCESSO] Transação gerada - ID: {$response['identifier']}");

            return [
                'success' => true,
                'response' => $response,
                'data' => [
                    'status' => true,
                    'paymentCode' => $response['pix_code'], // Código PIX
                    'idTransaction' => $response['identifier'], // ID da transação
                    'paymentCodeBase64' => $response['pix_code'] ?? null,
                    'externalReference' => $payload['external_reference'] ?? null // Usa a referência original do payload
                ]
            ];
        } catch (SyncPaymentException $e) {
            $this->logError("[CASH IN ERROR] " . $e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            $this->logError("[CASH IN EXCEPTION] " . $e->getMessage());
            throw new SyncPaymentException('Erro interno syncpayments Cash In: ' . $e->getMessage());
        }
    }

    /**
     * Valida os dados do payload para Cash Out
     *
     * @param array $payload
     * @throws SyncPaymentValidationException
     */
    private function validateCashOutPayload(array $payload): void
    {
        $required = ['amount', 'pix_type', 'pix_key', 'document', 'name'];

        foreach ($required as $field) {
            if (empty($payload[$field])) {
                throw new SyncPaymentValidationException("Campo obrigatório ausente: {$field}");
            }
        }

        if (!is_numeric($payload['amount']) || $payload['amount'] <= 0) {
            throw new SyncPaymentValidationException('O valor deve ser um número positivo (amount)');
        }

        // Adicionar validação de tipo de chave PIX (ex: 'cpf', 'cnpj', 'email', 'phone', 'random') se necessário.
    }

    /**
     * Realiza uma operação de Cash Out (saque/pagamento - PIX)
     *
     * @param array{
     * amount: float,
     * pix_type: string,
     * pix_key: string,
     * name: string,
     * document: string,
     * postbackUrl?: string,
     * externalreference?: string
     * } $payload
     * @return array
     * @throws SyncPaymentException
     */
    public function cashOut(array $payload): array
    {
        try {
            $this->logInfo("[RAW PAYLOAD CASH OUT]: " . json_encode($payload, JSON_PRETTY_PRINT));

            // 1. VALIDAÇÃO LOCAL DO PAYLOAD
            $this->validateCashOutPayload($payload);

            $token = $this->generateToken();

            $document = preg_replace('/\D/', '', $payload['document']);

            $pixKey = $payload['pix_key'];

            if (strtolower($payload['pix_type']) === 'cpf' || strtolower($payload['pix_type']) === 'phone') {
                $pixKey = preg_replace('/\D/', '', $payload['pix_key']);
            }

            if (strtolower($payload['pix_type']) === 'phone') {
                $pixKey = "+55" . $pixKey;
            }

            $data = [
                'amount' => (float) $payload['amount'],
                'description' => 'Saque via PIX', // Alterado a descrição para refletir Cash Out
                'pix_key_type' => $payload['pix_type'],
                'pix_key' => $pixKey,
                "document" => [
                    "type" => (strlen($document) === 11) ? "cpf" : "cnpj", // Assumindo base 11 ou 14
                    "number" => $document
                ]
            ];

            if (isset($payload['external_reference'])) {
                $data['external_reference'] = $payload['external_reference'];
            }

            $path = '/api/partner/v1/cash-out';
            $headers = ['Authorization' => 'Bearer ' . $token];

            $webhookUrl = route('syncpayment.webhook', ['type' => 'cashout']);
            $this->registerWebHook($webhookUrl, 'cashout');

            $this->logInfo("[REQUEST CASH OUT]: " . json_encode($data, JSON_PRETTY_PRINT));

            // 2. REQUISIÇÃO PARA A API
            $response = $this->makeRequest('POST', $path, $data, $headers);

            // 3. VALIDAÇÃO DE ESTRUTURA DE SUCESSO DA API
            // Resposta Esperada (200 OK): message, reference_id
            if (empty($response['reference_id'])) {
                $this->logError('[ERRO ESTRUTURAL CASH OUT]: ' . json_encode($response));
                throw new SyncPaymentApiException('Resposta de Cash Out inválida: reference_id ausente.');
            }

            $this->logInfo("[CASH OUT SUCESSO] Transação gerada - ID: {$response['reference_id']}");

            return [
                'success' => true,
                'data' => [
                    'amount' => (float) $payload['amount'],
                    'pixKey' => $payload['pix_key'],
                    'pixType' => $payload['pix_type'],
                    'beneficiaryName' => $payload['name'],
                    'beneficiaryDocument' => $payload['document'],
                    'externalReference' => $response['reference_id'],
                    'status' => 'PENDING', // Cash Out geralmente começa como pendente
                    'valor_liquido' => (float) $payload['amount'], // Ajustar se a API tiver taxas
                    'idTransaction' => $response['reference_id'],
                ]
            ];
        } catch (SyncPaymentException $e) {
            $this->logError("[CASH OUT ERROR] " . $e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            $this->logError("[CASH OUT EXCEPTION] " . $e->getMessage());
            throw new SyncPaymentException('Erro interno syncpayments Cash Out: ' . $e->getMessage());
        }
    }

    /**
     * Processa o webhook de Cash In
     *
     * @param array $webhookData
     * @return array {
     * success: boolean,
     * message: string,
     * transaction_id: string,
     * status: string,
     * }
     * @throws SyncPaymentValidationException
     */
    public function processCashInWebhook(array $webhookData): array
    {
        try {
            $this->logInfo('[WEBHOOK CASH IN]: ' . json_encode($webhookData));

            $requiredFields = ['data'];
            foreach ($requiredFields as $field) {
                if (empty($webhookData[$field])) {
                    throw new SyncPaymentValidationException("Campo obrigatório ausente no webhook: {$field}");
                }
            }

            $data = $webhookData['data'];
            $requiredDataFields = ['status', 'id', 'amount'];
            foreach ($requiredDataFields as $field) {
                if (empty($data[$field])) {
                    throw new SyncPaymentValidationException("Campo obrigatório ausente no bloco 'data' do webhook: {$field}");
                }
            }

            $transactionStatus = $data['status'];
            $transactionId = $data['id'];

            // Validação de Status de Sucesso
            if (!in_array($transactionStatus, self::VALID_API_SUCCESS_STATUS)) {
                // Para o webhook, se o status não for de sucesso, a exceção deve ser mais específica ou apenas logada
                $message = "Webhook recebido com status não-sucesso: {$transactionStatus}. ID: {$transactionId}";
                $this->logInfo($message);
                // Você pode optar por lançar uma exceção ou retornar uma resposta indicando que o status não é de interesse
                return [
                    'success' => false,
                    'message' => 'Status da transação não é de sucesso ou completo',
                    'transaction_id' => $transactionId,
                    'status' => $transactionStatus
                ];
            }

            return [
                'success' => true,
                'message' => 'Webhook processado com sucesso',
                'transaction_id' => $transactionId,
                'status' => $transactionStatus,
                'amount' => $data['amount'],
                // Outros dados importantes do webhook podem ser adicionados aqui
            ];
        } catch (SyncPaymentException $e) {
            $this->logError('Erro ao processar webhook: ' . $e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            $this->logError('Exceção inesperada no webhook: ' . $e->getMessage());
            throw new SyncPaymentException('Erro interno no processamento do webhook: ' . $e->getMessage());
        }
    }

    /**
     * Processa o webhook de Cash Out
     *
     * @param array $webhookData
     * @return array {
     * success: boolean,
     * message: string,
     * transaction_id: string,
     * status: string,
     * }
     * @throws SyncPaymentValidationException
     */
    public function processCashOutWebhook(array $webhookData): array
    {
        try {
            $this->logInfo('[WEBHOOK CASH IN]: ' . json_encode($webhookData));

            $requiredFields = ['data'];
            foreach ($requiredFields as $field) {
                if (empty($webhookData[$field])) {
                    throw new SyncPaymentValidationException("Campo obrigatório ausente no webhook: {$field}");
                }
            }

            $data = $webhookData['data'];
            $requiredDataFields = ['status', 'id', 'amount', 'payment_method', 'pix_type', 'pix_key', 'final_amount'];
            foreach ($requiredDataFields as $field) {
                if (empty($data[$field])) {
                    throw new SyncPaymentValidationException("Campo obrigatório ausente no bloco 'data' do webhook: {$field}");
                }
            }

            $transactionStatus = $data['status'];
            $transactionId = $data['id'];

            // Validação de Status de Sucesso
            if (!in_array($transactionStatus, self::VALID_API_SUCCESS_STATUS)) {
                // Para o webhook, se o status não for de sucesso, a exceção deve ser mais específica ou apenas logada
                $message = "Webhook recebido com status não-sucesso: {$transactionStatus}. ID: {$transactionId}";
                $this->logInfo($message);
                // Você pode optar por lançar uma exceção ou retornar uma resposta indicando que o status não é de interesse
                return [
                    'success' => false,
                    'message' => 'Status da transação não é de sucesso ou completo',
                    'transaction_id' => $transactionId,
                    'status' => $transactionStatus
                ];
            }

            return [
                'success' => true,
                'message' => 'Webhook processado com sucesso',
                'transaction_id' => $transactionId,
                'status' => $transactionStatus,
                'amount' => $data['amount'],
                // Outros dados importantes do webhook podem ser adicionados aqui
            ];
        } catch (SyncPaymentException $e) {
            $this->logError('Erro ao processar webhook: ' . $e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            $this->logError('Exceção inesperada no webhook: ' . $e->getMessage());
            throw new SyncPaymentException('Erro interno no processamento do webhook: ' . $e->getMessage());
        }
    }

    /**
     * Log de informações
     */
    public function logInfo(string $message): void
    {
        Log::channel('syncpayments')->info($message); // Usando um canal de log dedicado
    }

    /**
     * Log de erros
     */
    public function logError(string $message): void
    {
        Log::channel('syncpayments')->error($message); // Usando um canal de log dedicado
    }
}

// A classe SyncPaymentException foi movida para um namespace separado para melhor organização.