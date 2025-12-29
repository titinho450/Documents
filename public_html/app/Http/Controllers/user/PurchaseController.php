<?php

namespace App\Http\Controllers\user;

use App\Enums\TransactionStatus;
use App\Enums\TransactionTypes;
use App\Http\Controllers\Controller;
use App\Models\CyclePlan;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\Rebate;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserCycle;
use App\Models\UserLedger;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Request;
use App\Services\ChallengeGoalService;

class PurchaseController extends Controller
{

    private ChallengeGoalService $userChallengeGoal;

    public function __construct()
    {
        $this->userChallengeGoal = new ChallengeGoalService();
    }

    public function purchase_vip($id)
    {
        $package = Package::find($id);
        $user = auth()->user();
        $token = $user->createToken('Api Token')->plainTextToken;
        return view('app.main.vip_confirm', compact('package', 'token'));
    }

    public function list()
    {
        $user = auth()->user();
        $purchases = Purchase::with('package')->where('user_id', $user->id)->get();

        return response()->json($purchases, 200);
    }


    public function legders()
    {
        // ledgers
        $user = auth()->user();

        $userLedgers = UserLedger::where('user_id', $user->id)->where('reason', 'daily_income')->get();
        return response()->json($userLedgers, 200);
    }




    /**
     * Verifica a disponibilidade do plano
     */
    public function checkDisponibilityPlan(CyclePlan $plan)
    {
        $prevCycle = $plan->cycle->previousCycle();

        // Verifica se existe um ciclo anterior
        // if ($prevCycle) {
        //     $plansPrevCycle = $prevCycle->plans;

        //     if ($plansPrevCycle && $plansPrevCycle->count() > 0) {
        //         foreach ($plansPrevCycle as $prevCyclePlan) {
        //             $purchase = $prevCyclePlan->purchase;
        //             if (empty($purchase) || $purchase->status !== 'completed') {
        //                 return response()->json([
        //                     'success' => false,
        //                     'message' => 'Você precisa completar o ciclo anterior para comprar um plano deste ciclo'
        //                 ], 400);
        //             }
        //         }
        //     }
        // }

        return response()->json([
            'success' => true,
            'message' => 'Plano disponível para a compra'
        ], 200);
    }

    public function purchaseConfirmation(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|integer', // Aqui você define as regras de validação
            'transaction_id' => 'required|unique:purchases,transaction_id'
        ]);

        // transaction_id

        $id = $validated['id'];
        $transactionId = $validated['transaction_id'];

        /** @var \App\Models\Package $plan */
        $plan = Package::findOrFail($id);
        $user = Auth::user();

        //Check status
        if ($plan->status != 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Plano inativo'
            ], 402);
        }

        // if ($plan->featured) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Por favor aguarde a liberação do plano!'
        //     ], 400);
        // }

        // if ($plan->limitOnFeatured($user)) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Plano Limitado a somente uma compra!'
        //     ], 400);
        // }

        if ($user->purchases()->where('package_id', $plan->id)->where('created_at', '>', now()->subMinute())->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Compra recente do mesmo pacote'
            ], 400);
        }

        DB::beginTransaction();
        try {
            if ($user->balance < $plan->total_investment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Saldo insuficiente'
                ], 402);
            }

            Log::info("Antes da compra - Usuário ID: {$user->id}, Saldo: {$user->balance}, Preço do pacote: {$plan->total_investment}");
            // Atualiza saldo do usuário
            $user->subtractBalance($plan->total_investment);
            $updatedUser = $user->update(['investor' => 1]);

            $updatedUser = $user->refresh();

            Log::info("Depois da compra - Usuário ID: {$user->id}, Novo saldo: {$updatedUser->balance}");

            $daily_income = $plan->total_investment * ($plan->commission_percentage / 100);

            // Verifica se é a primeira compra deste pacote
            $checkIsFirst = $user->purchases()->where('package_id', $plan->id)->first();

            // Cria compra com identificador único
            $purchase = new Purchase();
            $purchase->user_id = $user->id;
            $purchase->transaction_id = $transactionId;
            $purchase->package_id = $plan->id;
            $purchase->amount = $plan->total_investment;
            $purchase->daily_income = $daily_income;
            $purchase->date = now()->addHours(24);
            $purchase->validity = $plan->packageTime();
            $purchase->status = 'active';
            $purchase->save();

            // Registra a transaction
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'type' => TransactionTypes::PURCHASE,
                'currency' => 'BRL',
                'amount' => $plan->total_investment,
                'purchase_id' => $purchase->id,
                'payment_id' => $transactionId,
                'order_id' => $transactionId,
                'payment_address' => 'BALANCE',
                'status' => TransactionStatus::COMPLETED,
                'description' => 'Investimento em pacote ' . $plan->name
            ]);

            if (!$transaction) {
                throw new \Exception('Transação não registrada');
            }

            if (empty($checkIsFirst)) {
                $user->processComissionReferral($plan->total_investment, 'Comissão de investimento de ' . $plan->total_investment);
            }

            $user->referralsChallengeGoal($plan->total_investment);


            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Compra efetuada com sucesso',
                'purchase' => $purchase
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao processar compra' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    private function handleComissionFromuser(User $user, $amount, $from, $step)
    {

        $user->increment('balance', $amount);

        $ledger = new UserLedger();
        $ledger->user_id = $user->id;
        $ledger->get_balance_from_user_id = $from;
        $ledger->reason = 'commission';
        $ledger->perticulation = 'Comissão de compra de pacotes';
        $ledger->amount = $amount;
        $ledger->debit = $amount;
        $ledger->status = 'approved';
        $ledger->step = $step;
        $ledger->date = now();
        $ledger->save();
    }


    public function vip_confirm($vip_id)
    {
        $vip = Package::find($vip_id);
        return view('app.main.vip_confirm', compact('vip'));
    }

    protected function ref_user($ref_by)
    {
        return User::where('ref_id', $ref_by)->first();
    }
}
