<?php

namespace App\Services\SyncPayment;

use Illuminate\Http\Client\Response;

class SyncPaymentApiException extends SyncPaymentException
{
    // Permite passar um objeto Response do Laravel HTTP Client para logs mais detalhados
    public function __construct(string $message, int $code = 500, Response $response = null)
    {
        if ($response) {
            $message .= ' | URL: ' . $response->effectiveUri() . ' | Response: ' . $response->body();
        }
        parent::__construct($message, $code);
    }
}
