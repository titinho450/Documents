<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChartController extends Controller
{
    public function getData(Request $request): JsonResponse
    {
        // Retorna dados de gráfico mockados (velas japonesas)
        $data = [];
        $lastClose = 1000;
        for ($i = 0; $i < 110; $i++) {
            $open = $lastClose + (rand(-100, 100) / 100);
            $close = $open + (rand(-100, 100) / 100);
            $high = max($open, $close) + (rand(0, 50) / 100);
            $low = min($open, $close) - (rand(0, 50) / 100);
            $data[] = [
                'time' => now()->subSeconds(60 - $i)->timestamp,
                'open' => round($open, 2),
                'high' => round($high, 2),
                'low' => round($low, 2),
                'close' => round($close, 2),
            ];
            $lastClose = $close;
        }

        return response()->json($data);
    }
}
