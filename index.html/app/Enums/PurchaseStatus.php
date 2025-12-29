<?php

namespace App\Enums;

enum PurchaseStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}
