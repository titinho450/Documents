<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionTypes;
use App\Models\Transaction;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Purchase;
use App\Models\UserLedger;
use App\Models\Withdrawal;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class TransactionController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $deposits = Deposit::where('user_id', $user->id)
            ->where('status', 'approved')
            ->latest()
            ->limit(500) // limite opcional
            ->get();
        $withdraws = Withdrawal::where('user_id', $user->id)->get();
        $purchases = Purchase::where('user_id', $user->id)->with('package')->get();
        $ledgers = UserLedger::where('user_id', $user->id)->get();
        $comissions = $user->ledgers()->where('reason', 'commission_indication')->get();

        $monthlyTransactions = [];
        $now = now();
        $sixMonthsAgo = $now->copy()->subMonths(6);

        // Criar estrutura mensal
        for ($i = 0; $i < 6; $i++) {
            $month = $sixMonthsAgo->copy()->addMonths($i);
            $monthName = $month->format('M'); // Abreviação do mês em português

            $monthlyTransactions[] = [
                'name' => $monthName,
                'depositos' => 0,
                'saques' => 0,
                'investimentos' => 0
            ];
        }

        // Preencher os depósitos por mês
        foreach ($deposits as $deposit) {
            $depositDate = Carbon::parse($deposit->created_at);

            // Verificar se o depósito está nos últimos 6 meses
            if ($depositDate->between($sixMonthsAgo, $now)) {
                $monthIndex = $depositDate->diffInMonths($sixMonthsAgo);
                if (isset($monthlyTransactions[$monthIndex])) {
                    $monthlyTransactions[$monthIndex]['depositos'] += $deposit->amount;
                }
            }
        }

        // Preencher os saques por mês
        foreach ($withdraws as $withdraw) {
            $withdrawDate = Carbon::parse($withdraw->created_at);

            if ($withdrawDate->between($sixMonthsAgo, $now)) {
                $monthIndex = $withdrawDate->diffInMonths($sixMonthsAgo);
                if (isset($monthlyTransactions[$monthIndex])) {
                    $monthlyTransactions[$monthIndex]['saques'] += $withdraw->amount;
                }
            }
        }

        // Preencher os investimentos por mês
        foreach ($purchases as $purchase) {
            $purchaseDate = Carbon::parse($purchase->created_at);

            if ($purchaseDate->between($sixMonthsAgo, $now)) {
                $monthIndex = $purchaseDate->diffInMonths($sixMonthsAgo);
                if (isset($monthlyTransactions[$monthIndex])) {
                    $monthlyTransactions[$monthIndex]['investimentos'] += $purchase->amount ?? $purchase->package->price;
                }
            }

            // Soma o total de transactions com o mesmo pacote
            $totalPaid = $purchase->transactions()->where('type', TransactionTypes::YIELD)->sum('amount');

            $purchase->totalPaid = (float) $totalPaid;
        }

        // Para dados semanais
        $weeklyTransactions = [
            ['name' => 'Seg', 'depositos' => 0, 'saques' => 0, 'investimentos' => 0],
            ['name' => 'Ter', 'depositos' => 0, 'saques' => 0, 'investimentos' => 0],
            ['name' => 'Qua', 'depositos' => 0, 'saques' => 0, 'investimentos' => 0],
            ['name' => 'Qui', 'depositos' => 0, 'saques' => 0, 'investimentos' => 0],
            ['name' => 'Sex', 'depositos' => 0, 'saques' => 0, 'investimentos' => 0],
            ['name' => 'Sab', 'depositos' => 0, 'saques' => 0, 'investimentos' => 0],
            ['name' => 'Dom', 'depositos' => 0, 'saques' => 0, 'investimentos' => 0],
        ];

        // Calcular início da semana (segunda-feira)
        $startOfWeek = $now->copy()->startOfWeek();
        $endOfWeek = $now->copy()->endOfWeek();

        // Preencher depósitos da semana
        foreach ($deposits as $deposit) {
            $depositDate = Carbon::parse($deposit->created_at);

            if ($depositDate->between($startOfWeek, $endOfWeek)) {
                $dayIndex = $depositDate->dayOfWeek - 1; // 0 (segunda) a 6 (domingo)
                if ($dayIndex < 0) $dayIndex = 6; // Ajuste para domingo

                $weeklyTransactions[$dayIndex]['depositos'] += $deposit->amount;
            }
        }

        // Preencher saques da semana
        foreach ($withdraws as $withdraw) {
            $withdrawDate = Carbon::parse($withdraw->created_at);

            if ($withdrawDate->between($startOfWeek, $endOfWeek)) {
                $dayIndex = $withdrawDate->dayOfWeek - 1;
                if ($dayIndex < 0) $dayIndex = 6;

                $weeklyTransactions[$dayIndex]['saques'] += $withdraw->amount;
            }
        }

        // Preencher investimentos da semana
        foreach ($purchases as $purchase) {
            $purchaseDate = Carbon::parse($purchase->created_at);

            if ($purchaseDate->between($startOfWeek, $endOfWeek)) {
                $dayIndex = $purchaseDate->dayOfWeek - 1;
                if ($dayIndex < 0) $dayIndex = 6;

                $weeklyTransactions[$dayIndex]['investimentos'] += $purchase->amount ?? $purchase->package->price;
            }
        }

        // Juntar os dados em um único objeto para retornar
        $transactions = [
            'monthly' => $monthlyTransactions,
            'weekly' => $weeklyTransactions
        ];



        return response()->json([
            'transactions' => $transactions,
            'deposits' => $deposits,
            'withdraws' => $withdraws,
            'purchases' => $purchases,
            'comissions' => $comissions,
            'ledgers' => $ledgers
        ], 200);
    }

    public function list(): JsonResponse
    {
        $user = Auth::user();

        $transactions = Transaction::where('user_id', $user->id)->get();

        return response()->json([
            'data' => $transactions,
            'success' => true,
            'message' => 'Transações listadas com sucesso'
        ]);
    }
}
