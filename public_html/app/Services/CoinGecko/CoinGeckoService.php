<?php

namespace App\Services\CoinGecko;

use Illuminate\Support\Facades\Http;

class CoinGeckoService
{
    protected $baseUrl = 'https://api.coingecko.com/api/v3';

    public function getCurrentPrice(string $symbol): ?float
    {
        $response = Http::get("{$this->baseUrl}/simple/price", [
            'ids' => $symbol,
            'vs_currencies' => 'usd',
        ]);

        return $response->successful()
            ? $response->json()[$symbol]['usd'] ?? null
            : null;
    }
}
