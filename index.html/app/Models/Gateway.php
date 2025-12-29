<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gateway extends Model
{
    protected $fillable = ['name', 'slug', 'active'];

    public function credentials()
    {
        return $this->hasMany(GatewayCredential::class);
    }
}
