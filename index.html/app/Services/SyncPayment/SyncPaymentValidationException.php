<?php

namespace App\Services\SyncPayment;

class SyncPaymentValidationException extends SyncPaymentException
{
    // Opcionalmente, pode armazenar os erros de validação retornados pela API
    public function __construct(string $message = "Erro de validação de parâmetro na API da SyncPayment.", int $code = 422, array $errors = [])
    {
        // Se a API retornar erros detalhados, inclua-os na mensagem
        if (!empty($errors)) {
            $message .= ' Detalhes: ' . json_encode($errors);
        }
        parent::__construct($message, $code);
    }
}
