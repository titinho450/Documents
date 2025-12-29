<?php

namespace App\Services\SyncPayment;

class SyncPaymentAuthException extends SyncPaymentException
{
    public function __construct(string $message = "Acesso não autorizado. Verifique as credenciais (Client ID/Secret) ou o Token.", int $code = 401)
    {
        parent::__construct($message, $code);
    }
}
