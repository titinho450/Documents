<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GatewayMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'api_key',
        'client_id',
        'client_secret',
        'status'
    ];
}
