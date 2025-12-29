<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GatewayCredential extends Model
{
    protected $fillable = ['gateway_id', 'key', 'value'];

    public function gateway()
    {
        return $this->belongsTo(Gateway::class);
    }
}
