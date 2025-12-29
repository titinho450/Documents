<?php

namespace App\Http\Controllers\admin;

use App\Enums\PurchaseStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionTypes;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Deposit;
use App\Models\FundInvest;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\Rebate;
use App\Models\User;
use App\Models\UserLedger;
use App\Models\Visit;
use App\Models\Withdrawal;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function login()
    {

        return view('admin.index');
    }

    public function adminCheck(): JsonResponse
    {
        try {
            $admin = Auth::guard('admin')->user();

            if (!$admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Login realizado com sucesso',
                'data' => [
                    'admin' => [
                        'id' => $admin->id,
                        'name' => $admin->name,
                        'email' => $admin->email,
                        'phone' => $admin->phone,
                    ],
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erro de validação',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erro ao realizar login',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function apiLogin(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => ['required', 'string', 'email'],
                'password' => ['required', 'string'],
            ]);

            $admin = Admin::where('email', $request->email)->first();

            if (!$admin || !Hash::check($request->password, $admin->password)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Credenciais incorretas',
                ], 401);
            }

            // Login via guard session
            Auth::guard('admin')->login($admin);

            // Regenera a sessão para proteger contra session fixation
            $request->session()->regenerate();

            return response()->json([
                'status' => 'success',
                'message' => 'Login realizado com sucesso',
                'data' => [
                    'admin' => [
                        'id' => $admin->id,
                        'name' => $admin->name,
                        'email' => $admin->email,
                        'phone' => $admin->phone,
                    ],
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erro de validação',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erro ao realizar login',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function commission()
    {
        Log::info("[TYPE]PACKAGES PAID -> Iniciando processo de pagamento de pacotes");

        try {
            // Janela de tempo: 2 horas atrás até 2 horas à frente
            $from = Carbon::now()->subMinutes(20);
            $to = Carbon::now()->addMinutes(20);

            $purchases = Purchase::where('status', PurchaseStatus::ACTIVE)
                ->whereBetween('date', [$from, $to])
                ->with(['user', 'package'])
                ->get(); // Altera chunk() para get()

            foreach ($purchases as $purchase) {
                // Pular se o usuário ou pacote não for encontrado
                Log::warning("ID DA COMPRA: " . $purchase->id . ' HORA A SER PAGO: ' . $purchase->date);
                if (empty($purchase->user)) {
                    Log::warning("Compra ID {$purchase->id}: Usuário ou pacote não encontrado");
                    continue;
                }

                DB::beginTransaction();
                try {
                    // 1. Processar o pagamento diário
                    $this->processDailyIncome($purchase);

                    // 2. Verificar validade do pacote
                    $this->checkPackageExpiry($purchase);

                    DB::commit();
                    Log::info("Processamento bem-sucedido para compra ID {$purchase->id}");
                    // Removido o retorno aqui, pois ele encerraria o loop na primeira iteração
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error("Erro ao processar compra ID {$purchase->id}: {$e->getMessage()}");
                    Log::error($e->getTraceAsString());
                }
            }

            // Removido o retorno para que a função continue e retorne apenas no final
            // A resposta JSON deve ser movida para o final do método, se for necessária
            return response()->json("ok", 200);
        } catch (\Exception $e) {
            Log::error("Erro crítico no processamento de comissões: {$e->getMessage()}");
            Log::error($e->getTraceAsString());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Processa o pagamento diário para o usuário
     */
    private function processDailyIncome(Purchase $purchase): void
    {
        /** @var \App\Models\User $user */
        $user = $purchase->user;

        /** @var \App\Models\Package $package */
        $package = $purchase->package;

        $planName = $package ? $package->name : "Pacote de investimeno";

        // Atualiza o saldo do usuário
        $user->addBalance($purchase->daily_income);

        // Atualiza a data do próximo pagamento
        $purchase->date = Carbon::now()->addDay(1);
        $purchase->save();

        $orderId = TransactionTypes::YIELD . '_' . $purchase->id . '_' . time();

        // Registra a transaction.
        $user->transactions()->create([
            'type' => TransactionTypes::YIELD,
            'currency' => 'BRL',
            'amount' => (float) $purchase->daily_income,
            'purchase_id' => $purchase->id,
            'payment_id' => $orderId,
            'order_id' => $orderId,
            'payment_address' => 'BALANCE',
            'status' => TransactionStatus::COMPLETED,
            'description' => 'Rendimento de plano: ' . $planName
        ]);

        // Registra o ledger
        UserLedger::create([
            'user_id' => $user->id,
            'reason' => 'daily_income',
            'perticulation' => $planName . ' Commission Added',
            'amount' => $purchase->daily_income,
            'credit' => $purchase->daily_income,
            'status' => TransactionStatus::APPROVED,
            'date' => Carbon::now()
        ]);
    }

    /**
     * Verifica a validade do pacote e atualiza o status se necessário
     */
    private function checkPackageExpiry(Purchase $purchase)
    {
        $validityDate = Carbon::parse($purchase->validity);
        if ($validityDate->isPast()) {
            $purchase->status = PurchaseStatus::INACTIVE;
            $purchase->save();
            Log::info("Pacote ID {$purchase->id} expirou e foi marcado como inativo");
        }
    }

    /**
     * Processa as comissões para os usuários que indicaram
     */
    private function processReferralCommissions(Purchase $purchase, array $commissionRates)
    {
        $currentUser = $purchase->user;
        $dailyIncome = $purchase->daily_income;
        $package = $purchase->package;

        // Processamos até 3 níveis de indicação
        $refBy = $currentUser->ref_by;

        for ($level = 1; $level <= 5; $level++) {
            // Verificamos se existe um usuário indicador para este nível
            if (!$refBy) {
                Log::info("Nenhum ref_by encontrado para o nível {$level}");
                break;
            }

            // Buscamos o usuário indicador
            $uplineUser = User::where('ref_id', $refBy)
                ->where('status', 'active')
                ->where('ban_unban', 'unban')
                ->first();

            if (!$uplineUser) {
                Log::info("Usuário upline não encontrado ou inativo para ref_id: {$refBy} no nível {$level}");
                break;
            }

            // Verificação específica para afiliados - EXCLUIR afiliados das comissões
            if ($uplineUser->is_afiliate == 1) {
                Log::info("Usuário ID {$uplineUser->id} é afiliado - pulando comissão do nível {$level}");
                // Continua para o próximo nível sem gerar comissão
                $refBy = $uplineUser->ref_by;
                continue;
            }

            // Verificamos se existe taxa de comissão para este nível
            if (!isset($commissionRates[$level]) || $commissionRates[$level] <= 0) {
                Log::info("Taxa de comissão não definida ou zero para o nível {$level}");
                $refBy = $uplineUser->ref_by;
                continue;
            }

            // Calculamos a comissão baseada na taxa do nível atual
            $commissionAmount = $dailyIncome * $commissionRates[$level];

            // Validação adicional para evitar comissões negativas ou zero
            if ($commissionAmount <= 0) {
                Log::info("Valor de comissão inválido: {$commissionAmount} para o nível {$level}");
                $refBy = $uplineUser->ref_by;
                continue;
            }

            // Atualizamos o saldo do usuário upline
            $uplineUser->balance += $commissionAmount;
            $uplineUser->total_commission += $commissionAmount;
            $uplineUser->save();

            // Registramos a comissão no ledger
            UserLedger::create([
                'user_id' => $uplineUser->id,
                'get_balance_from_user_id' => $uplineUser->id,
                'reason' => 'commission',
                'perticulation' => "Nível {$level} Comissão de Referência de {$currentUser->name} - {$package->name}",
                'amount' => $commissionAmount,
                'credit' => $commissionAmount,
                'status' => 'approved',
                'date' => Carbon::now(),
                'step' => $level
            ]);

            Log::info("Comissão de referência nível {$level} de {$commissionAmount} paga ao usuário ID {$uplineUser->id} (não afiliado)");

            // Preparamos para o próximo nível
            $refBy = $uplineUser->ref_by;
        }
    }

    public function apiAuthValidate(Request $request)
    {
        $admin = Auth::guard('admin-api')->user();

        if ($admin) {
            Log::info('Admin autenticado: ' . $admin->email);
            return response()->json([
                'success' => true,
                'admin' => $admin
            ], 200);
        } else {
            Log::error('Tentativa de login admin falhou - token inválido ou ausente');
            return response()->json([
                'success' => false
            ], 401);
        }
    }


    public function login_submit(Request $request)
    {
        // Validação de dados
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|min:6',
        ], [
            'email.required' => 'O campo e-mail é obrigatório.',
            'email.email' => 'Por favor, insira um e-mail válido.',
            'password.required' => 'O campo senha é obrigatório.',
            'password.min' => 'A senha deve ter pelo menos 6 caracteres.',
        ]);

        // Se a validação falhar, redireciona de volta com os erros
        if ($validator->fails()) {
            return redirect()->route('admin.login')
                ->withErrors($validator)
                ->withInput($request->except('password'));
        }

        // Tenta autenticar
        $credentials = $request->only('email', 'password');
        if (Auth::guard('admin')->attempt($credentials)) {
            $admin = Auth::guard('admin')->user();
            if ($admin->type == 'admin') {
                return redirect()->route('admin.dashboard.manager')->with('success', 'Logged In Successful.');
            } else {
                return redirect()->route('admin.login')->with('error', 'Admin Credentials Very Secured Please Don"t try Again.');
            }
        } else {
            return redirect()->route('admin.login')->with('error', 'Admin Credentials Does Not Match.');
        }
    }

    public function logout()
    {
        if (Auth::guard('admin')->check()) {
            Auth::guard('admin')->logout();
            return redirect()->route('admin.login')->with('success', 'Logged out successful.');
        } else {
            return error_redirect('admin.login', 'error', 'You are already logged out.');
        }
    }

    public function setAfiliate(Request $request)
    {
        try {

            $validate = Validator::make($request->all(), [
                'user_id' => ['required', 'numeric', 'exists:users,id'],
                'is_affiliate' => ['required', 'boolean']
            ]);

            if ($validate->fails()) {
                Log::error('[TYPE]:UPDATE PACKAGE -> ' . $validate->errors());
                return response()->json([
                    'success' => false,
                    'message' => $validate->errors()
                ], 400);
            }

            $validated = $validate->validated();

            $user = User::find($validated['user_id']);

            if (!$user) { // Corrigido: lógica invertida (estava retornando erro quando usuário EXISTIA)
                Log::error('[TYPE]:AFILIATE USER -> Usuário não encontrado: ' . $validated['user_id']);
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado'
                ], 404); // Corrigido: status code para 404 (Not Found)
            }

            $user->update([
                'is_afiliate' => $validated['is_affiliate'] // Corrigido: nome do campo para corresponder ao validado
            ]);

            return response()->json([
                'success' => true,
                'user' => $user
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function dashboard()
    {
        return view('admin.dashboard');
    }

    private function calculateDailySalesPercentageIncrease()
    {
        $today = now();
        $yesterday = $today->subDay();

        $todaySales = Purchase::whereDate('created_at', $today)->sum('amount');
        $yesterdaySales = Purchase::whereDate('created_at', $yesterday)->sum('amount');

        return $yesterdaySales > 0
            ? number_format(($todaySales - $yesterdaySales) / $yesterdaySales * 100, 2)
            : 0;
    }

    public function dashboard2()
    {
        $admin = Auth::guard('admin')->user();

        if (!$admin) {

            return error_redirect('admin.login', 'error', 'Admin Credentials Does Not Match.');
        }

        $Admintoken = $admin->createToken('Admin Token')->plainTextToken;

        $today = Carbon::today(); // Obtém o início do dia atual
        $yesterday = now()->subDay();
        $sevenDaysAgo = Carbon::today()->subDays(7);
        $usersCountToday = User::whereDate('created_at', $today)->count();

        // Obter o início e o fim do mês atual
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        // Soma das vendas do mês atual
        $salesThisMonth = Purchase::whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        // Soma total de vendas
        $totalSales = Purchase::sum('amount');

        // Calcular o percentual
        $percentageThisMonth = $totalSales > 0
            ? ($salesThisMonth / $totalSales) * 100
            : 0;

        $approvedDepositsToday = Deposit::where('status', 'approved')
            ->whereDate('created_at', $today)
            ->get();

        $totalAmountToday = Deposit::where('status', 'approved')
            ->whereDate('created_at', $today)
            ->sum('amount');

        // Soma dos depósitos aprovados nos últimos 7 dias
        $totalAmountLast7Days = Deposit::where('status', 'approved')
            ->whereBetween('created_at', [$sevenDaysAgo, $today->endOfDay()])
            ->sum('amount');

        // Calcula a porcentagem de hoje em relação aos últimos 7 dias
        $percentageToday = $totalAmountLast7Days > 0
            ? ($totalAmountToday / $totalAmountLast7Days) * 100
            : 0;

        $salesAmountToday = Purchase::where('status', 'active')
            ->whereDate('created_at', $today)
            ->sum('amount');

        $lastSales = Purchase::with('package') // Carrega a relação 'package'
            ->latest() // Ordena pela coluna 'created_at' em ordem decrescente
            ->limit(10) // Limita os resultados a 10
            ->get();

        $packages = Package::withCount(['userPackages as purchase_percentage' => function ($query) {
            $query->select(DB::raw('(COUNT(*) / (SELECT COUNT(*) FROM purchases) * 100) AS percentage'));
        }])
            ->orderByDesc('purchase_percentage')
            ->get();

        $dailySales = Purchase::select(
            DB::raw('MONTH(created_at) as month'),
            DB::raw('SUM(amount) as total_sales')
        )
            ->groupBy(DB::raw('MONTH(created_at)'))
            ->orderBy(DB::raw('MONTH(created_at)'))
            ->get()
            ->pluck('total_sales')
            ->toArray();

        $salesPercentageIncrease = $this->calculateDailySalesPercentageIncrease();

        $visits = Visit::orderBy('date')
            ->take(30)
            ->get(['date', 'count']);

        $allUsers = User::all()->count();

        $totalDeposits = Deposit::where('status', 'approved')->sum('amount');
        $depositsCount = Deposit::where('status', 'approved')->count();

        $totalWithdraws = Withdrawal::where('status', 'approved')->sum('amount');
        $withdrawsCount = Withdrawal::count();

        $totalCodesUsed = User::whereNotNull('ref_by')->count();

        $amountWithdrawToday = Withdrawal::where('status', 'approved')
            ->whereDate('created_at', $today)
            ->sum('amount');

        $countWithdrawToday = Withdrawal::where('status', 'approved')
            ->whereDate('created_at', $today)
            ->count();


        return view('admin.dashboard.index', compact(
            'usersCountToday',
            'totalAmountToday',
            'salesAmountToday',
            'percentageToday',
            'lastSales',
            'percentageThisMonth',
            'packages',
            'dailySales',
            'salesPercentageIncrease',
            'visits',
            'Admintoken',
            'allUsers',
            'totalDeposits',
            'depositsCount',
            'totalWithdraws',
            'withdrawsCount',
            'totalCodesUsed',
            'amountWithdrawToday',
            'countWithdrawToday'
        ));
    }

    public function usersList()
    {
        $admin = Auth::guard('admin')->user();

        if (!$admin) {
            return error_redirect('admin.login', 'error', 'Admin Credentials Does Not Match.');
        }

        $users = User::with(['referrals', 'referrals.investments'])->get();

        // Calcular o total de investimentos dos indicados para cada usuário
        foreach ($users as $user) {
            $user->referrals_investment_total = $user->referrals->sum(function ($referral) {
                return $referral->investments->sum('amount'); // ajuste 'amount' para a coluna correta
            });
        }

        $Admintoken = $admin->createToken('Admin Token')->plainTextToken;

        return view('admin.dashboard.users', compact('users', 'Admintoken'));
    }

    public function deletePurchase($id)
    {
        $purchase = Purchase::find($id);

        if ($purchase) {
            $purchase->delete();

            return response()->json([
                'success' => true,
            ], 200);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Compra não encontrada'
            ], 400);
        }
    }

    public function rejectDeposit($id)
    {
        $deposit = Deposit::find($id);

        if ($deposit) {

            if ($deposit->status === 'approved') {
                $user = User::find($deposit->user_id);

                if ($user) {
                    $user->balance = $user->balance - $deposit->amount;
                    $user->save();
                }
            }

            $deposit->status = 'rejected';
            $deposit->save();


            return response()->json([
                'success' => true,
                'deposit' => $deposit
            ], 200);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Deposito não encontrado'
            ], 400);
        }
    }

    public function approveDeposit($id)
    {
        $deposit = Deposit::find($id);

        if ($deposit) {
            $deposit->status = 'approved';
            $deposit->save();

            $user = User::find($deposit->user_id);

            if ($user) {
                $user->balance = $user->balance + $deposit->amount;
                $user->save();
            }


            return response()->json([
                'success' => true,
                'deposit' => $deposit
            ], 200);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Deposito não encontrado'
            ], 400);
        }
    }

    public function userPackageDelete($id)
    {
        $package = Package::find($id);

        if ($package) {
            $package->delete();

            return response()->json([
                'success' => true,
            ], 200);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Pacote não encontrado'
            ], 400);
        }
    }

    public function getUserTransactions($id)
    {
        $purchases = Purchase::where('user_id', $id)->with('package')->get();
        $deposits = Deposit::where('user_id', $id)->get();
        $withdraw = Withdrawal::where('user_id', $id)->get();

        return response()->json([
            'purchases' => $purchases,
            'deposits' => $deposits,
            'withdraws' => $withdraw,
        ], 200);
    }

    public function getDataUser($id)
    {
        $user = User::find($id);

        $user_refs = User::where('ref_by', $user->ref_id)->get();
        $refList = [];
        foreach ($user_refs as $ref) {
            $refNv2 = User::where('ref_by', $ref->ref_id)->get();
            $usersNv2 = [];
            foreach ($refNv2 as $nv2) {
                $refNv3 = User::where('ref_by', $nv2->ref_id)->get();
                $nv3 = $nv2;
                $userPaid = UserLedger::where('get_balance_from_user_id', $nv3->id)->sum('amount');
                $nv3->refference = $refNv3;
                $nv3->comission_paid = $userPaid;
                $usersNv2[] = $nv3;
            }

            $nv1 = $ref;
            $userPaid = UserLedger::where('get_balance_from_user_id', $ref->id)->sum('amount');
            $nv1->refference = $usersNv2;
            $nv1->comission_paid = $userPaid;
            $refList[] = $nv1;
        }

        return response()->json([
            'user' => $user,
            'members' => $refList
        ], 200);
    }

    public function updateUserData(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validatedData = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'unique:users,email,' . $id],
            'password' => ['nullable', 'confirmed', 'min:6'],
            'ref_by' => ['nullable', 'string', 'min:3'],
            'ref_id' => ['nullable', 'string', 'min:3'],
            'phone_code' => ['nullable', 'string', 'min:2'],
            'phone' => ['nullable', 'string', 'min:6'],
            'username' => ['nullable', 'string', 'min:6'],
            'balance' => ['nullable', 'numeric', 'min:0'],
        ]);

        $fillableFields = [
            'name',
            'email',
            'ref_by',
            'ref_id',
            'phone_code',
            'username',
            'balance',
            'phone'
        ];

        foreach ($fillableFields as $field) {
            if (isset($validatedData[$field])) {
                $user->$field = $validatedData[$field];
            }
        }

        if ($request->has('receive_able_amount')) {
            $user->receive_able_amount = intval($request->receive_able_amount);
        }

        if ($request->has('password')) {
            $user->password = Hash::make($validatedData['password']);
        }

        $user->save();

        return response()->json([
            'message' => 'Usuário atualizado com sucesso.',
            'user' => $user
        ], 200);
    }

    public function developer()
    {
        return view('admin.developer');
    }

    public function profile()
    {
        return view('admin.profile.index');
    }

    public function profile_update()
    {
        $admin = Admin::first();
        return view('admin.profile.update-details', compact('admin'));
    }

    public function profile_update_submit(Request $request)
    {
        $admin = Admin::find(1);
        $path = uploadImage(false, $request, 'photo', 'admin/assets/images/profile/', $admin->photo);
        $admin->photo = $path ?? $admin->photo;
        $admin->name = $request->name;
        $admin->email = $request->email;
        $admin->phone = $request->phone;
        $admin->address = $request->address;
        $admin->update();
        return redirect()->route('admin.profile.update')->with('success', 'Admin profile updated.');
    }

    public function change_password()
    {
        $admin = admin()->user();
        return view('admin.profile.change-password', compact('admin'));
    }

    public function check_password(Request $request)
    {
        $admin = admin()->user();
        $password = $request->password;
        if (Hash::check($password, $admin->password)) {
            return response()->json(['message' => 'Password matched.', 'status' => true]);
        } else {
            return response()->json(['message' => 'Password dose not match.', 'status' => false]);
        }
    }

    public function change_password_submit(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'old_password' => 'required',
            'new_password' => 'required',
            'confirm_password' => 'required'
        ]);
        if ($validate->fails()) {
            session()->put('errors', true);
            return redirect()->route('admin.changepassword')->withErrors($validate->errors());
        }

        $admin = admin()->user();
        $password = $request->old_password;
        if (Hash::check($password, $admin->password)) {
            if (strlen($request->new_password) > 5 && strlen($request->confirm_password) > 5) {
                if ($request->new_password === $request->confirm_password) {
                    $admin->password = Hash::make($request->new_password);
                    $admin->update();
                    return redirect()->route('admin.changepassword')->with('success', 'Password changed successfully');
                } else {
                    return error_redirect('admin.changepassword', 'error', 'New password and confirm password dose not match');
                }
            } else {
                return error_redirect('admin.changepassword', 'error', 'Password must be greater then 6 or equal.');
            }
        } else {
            return error_redirect('admin.changepassword', 'error', 'Password dose not match');
        }
    }
}
