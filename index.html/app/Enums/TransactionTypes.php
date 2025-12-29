<?php

namespace App\Enums;

enum TransactionTypes
{
    public const PIX = 'PIX';
    public const USDT = 'USDT';
    public const DEPOSIT = 'deposit';
    public const WITHDRAW = 'withdraw';
    public const COMISSION = 'commission';
    public const PURCHASE = 'purchase';
    public const YIELD = 'yield';
}
