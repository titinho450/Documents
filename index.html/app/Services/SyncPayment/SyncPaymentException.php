<?php

namespace App\Services\SyncPayment;

use Exception;
use Throwable;

class SyncPaymentException extends Exception
{
    public function __construct(string $message = "Erro na comunicação com a SyncPayment.", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
