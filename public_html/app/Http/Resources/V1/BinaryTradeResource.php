<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BinaryTradeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount_cents / 100,
            'direction' => $this->direction,
            'status' => $this->status,
            'expires_at' => $this->expires_at->toDateTimeString(),
            'settled_at' => $this->settled_at?->toDateTimeString(),
            'created_at' => $this->created_at->toDateTimeString(),
        ];
    }
}
