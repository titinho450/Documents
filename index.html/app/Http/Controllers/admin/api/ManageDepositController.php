<?php

namespace App\Http\Controllers\admin\api;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DepositUpdateRequest;
use App\Models\Deposit;
use App\Services\DepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Http\Requests\UpdateDepositSettingsRequest;
use App\Models\Setting;

class ManageDepositController extends Controller
{
    protected DepositService $depositService;

    public function __construct(DepositService $depositService)
    {
        $this->depositService = $depositService;
    }

    /**
     * Updating deposit request
     * @param App\Http\Requests\DepositUpdateRequest $request
     * @param Deposit $deposit
     * @return Illuminate\Http\JsonResponse
     */
    public function update(DepositUpdateRequest $request, Deposit $deposit): JsonResponse
    {
        DB::beginTransaction();

        try {
            // Atualiza com dados validados
            $deposit->update($request->validated());

            // Verifica status após update
            if ($deposit->status === TransactionStatus::APPROVED) {
                $this->depositService->approveDeposit($deposit);
            } elseif ($deposit->status === TransactionStatus::REJECTED) {
                $this->depositService->rejectDeposit($deposit);
            } else {
                $this->depositService->cancelDeposit($deposit);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Depósito atualizado com sucesso',
                'data' => $deposit,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Falha ao atualizar depósito',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Updating deposit status request
     * @param App\Http\Requests\DepositUpdateRequest $request
     * @param Deposit $deposit
     * @return Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, Deposit $deposit): JsonResponse
    {

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                TransactionStatus::PENDING,
                TransactionStatus::APPROVED,
                TransactionStatus::REJECTED,
                TransactionStatus::CANCELED,
            ])],
        ]);

        DB::beginTransaction();

        try {
            // Atualiza com dados validados
            $deposit->update($validated);

            // Verifica status após update
            if ($deposit->status === TransactionStatus::APPROVED) {
                $this->depositService->approveDeposit($deposit);
            } elseif ($deposit->status === TransactionStatus::REJECTED) {
                $this->depositService->rejectDeposit($deposit);
            } elseif ($deposit->status === TransactionStatus::CANCELED) {
                $this->depositService->cancelDeposit($deposit);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Depósito atualizado com sucesso',
                'data' => Deposit::with('user')->find($deposit->id),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Falha ao atualizar depósito',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function statistics(): JsonResponse
    {
        $totalAmount = Deposit::sum('amount');
        $totalAprroved = Deposit::where('status', TransactionStatus::APPROVED)->sum('amount');
        $totalPending = Deposit::where('status', TransactionStatus::PENDING)->sum('amount');
        $totalRejected = Deposit::where('status', TransactionStatus::REJECTED)->sum('amount');
        $totalProcessing = Deposit::where('status', TransactionStatus::PROCESSING)->sum('amount');

        return response()->json([
            'total_pending' => $totalPending,
            'total_approved' => $totalAprroved,
            'total_processing' => $totalProcessing,
            'total_rejected' => $totalRejected,
            'total_amount' => $totalAmount,
        ]);
    }

    /**
     * Buscar depósitos por valor, id, transaction_id, user.name, user.phone, user.email, user.ref_id, user.withdrawAccount.cpf, user.withdrawAccount.pix_key e CPF da conta de saque
     */
    public function searchDeposits(Request $request): JsonResponse
    {
        $query = $request->input('query'); // termo de busca

        $deposits = Deposit::with(['user', 'user.withdrawAccount'])
            ->where(function ($q) use ($query) {
                // Busca direta nos campos da própria tabela deposits
                $q->where('id', 'like', "%{$query}%")
                    ->orWhere('transaction_id', 'like', "%{$query}%")
                    ->orWhere('amount', 'like', "%{$query}%");
            })
            // Busca nos campos da relação user
            ->orWhereHas('user', function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('phone', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%")
                    ->orWhere('ref_id', 'like', "%{$query}%")
                    ->orWhere('username', 'like', "%{$query}%");
            })
            // Busca nos campos da relação withdrawAccount (via user)
            ->orWhereHas('user.withdrawAccount', function ($q) use ($query) {
                $q->where('cpf', 'like', "%{$query}%")
                    ->orWhere('pix_key', 'like', "%{$query}%");
            })
            ->paginate(10);


        return response()->json([
            'success' => true,
            'deposits' => $deposits
        ], 200);
    }

    /**
     * Atualiza as configurações relacionadas a depósitos.
     *
     * @param UpdateDepositSettingsRequest $request
     * @return JsonResponse
     */
    public function updateSettings(UpdateDepositSettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        DB::beginTransaction();

        try {
            // Você pode ajustar isso caso tenha mais de um registro de settings.
            $settings = Setting::firstOrFail();

            $settings->update($validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Configurações de depósito atualizadas com sucesso.',
                'data' => $settings,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            report($e); // Para registrar no log

            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar as configurações de depósito.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function delete($id): JsonResponse
    {
        $deposit = Deposit::find($id);


        if (!$deposit) {
            return response()->json([
                'success' => false,
                'message' => 'Deposito não identificado',
            ], 400);
        }

        $deposit->delete();

        return response()->json([
            'success' => false,
            'message' => 'Deposito excluido com sucesso',
            'data' => [
                'deposit' => $deposit
            ]
        ], 400);
    }

    public function listing(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 10);
        $deposits = Deposit::with(['user:id,name,email'])
            ->paginate($perPage);


        return response()->json([
            'success' => true,
            'deposits' => $deposits
        ], 200);
    }

    /**
     * Busca depósitos pelo nome ou telefone
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->input('query'); // termo de busca

        $deposits = Deposit::with(['user:id,name,email,phone'])
            ->whereHas('user', function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('phone', 'like', "%{$query}%");
            })
            ->paginate(10);

        return response()->json([
            'success' => true,
            'deposits' => $deposits
        ], 200);
    }

    /**
     * 
     * Function from push notification Deposit on gateway
     * 
     * @param Withdraw
     * @return void
     * @throws Exception
     */
    private static function pushNotification(Deposit $deposit): void
    {

        $pusher = new \Pusher\Pusher(
            config('broadcasting.connections.pusher.key'),
            config('broadcasting.connections.pusher.secret'),
            config('broadcasting.connections.pusher.app_id'),
            config('broadcasting.connections.pusher.options')
        );

        $pusher->trigger('chanel-user-' . $deposit->user->id, 'paid', [
            'user_id' => $deposit->user->id,
            'message' => "O Deposito no valor de R$" . $deposit->amount . " foi creditado em sua conta ",
            'user' => $deposit->user,
            'timestamp' => now(),
            'type' => 'info'
        ]);
    }
}
