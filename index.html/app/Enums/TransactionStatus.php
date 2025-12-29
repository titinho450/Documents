<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case APPROVED = 'approved';
    case FAILED = 'failed';
    case CONFIRMING = 'confirming';
    case CONFIRMED = 'confirmed';
    case CANCELED = 'canceled';
    case PROCESSING = 'processing';
    case REFUNDED = 'refunded';
    case EXPIRED = 'expired';
    case REJECTED = 'rejected';
}
