<?php

namespace App\Http\Controllers\admin\api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\UserLedger;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function list()
    {
        $purchases = Purchase::paginate(20);

        return response()->json([
            'success' => true,
            'data' => $purchases,
            'message' => 'Purchases listados com sucesso!'
        ]);
    }

    public function statistics()
    {
        $total_purchases = Purchase::sum('amount');
        $total_paids = UserLedger::where('reason', 'daily_income')->sum('amount');

        return response()->json([
            'success' => true,
            'data' => [
                'total_purchases' => $total_purchases,
                'total_paids' => $total_paids
            ],
            'message' => 'Estatisticas de purchases listadas com sucesso!'
        ]);
    }
}
