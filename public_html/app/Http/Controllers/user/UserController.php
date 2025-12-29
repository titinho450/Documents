<?php

namespace App\Http\Controllers\user;

use App\Enums\TransactionTypes;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\SyncPay;
use App\Mail\ResetPassword;
use App\Models\Admin;
use App\Models\BonusLedger;
use App\Models\Checkin;
use App\Models\Commission;
use App\Models\Deposit;
use App\Models\Fund;
use App\Models\Improvment;
use App\Models\Mining;
use App\Models\Notice;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\Rebate;
use App\Models\Setting;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserLedger;
use App\Models\VipSlider;
use App\Models\Withdrawal;
use App\Models\WithdrawalAccount;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    private $syncpay;

    public function __construct(SyncPay $syncpay)
    {
        $this->syncpay = $syncpay;
    }

    public function login()
    {
        return view('app.main.index');
    }

    public function dashboard()
    {
        $user = auth()->user();
        $packages = Package::where('status', 'active')->get();
        $token = $user->createToken('Api Token')->plainTextToken;
        return view('app.main.index', compact('packages', 'token'));
    }

    public function get()
    {
        try {
            $user = auth()->user();

            // Carrega os relacionamentos e accessors que você precisa
            // Exemplo:
            $user->load(['transactions', 'activeInvestments', 'withdrawAccount']);

            // Se você precisar dos outros accessors, chame-os individualmente antes de retornar
            $user->referral_data = $user->getReferralAttribute();
            $user->network_data = $user->getNetworkAttribute();
            $user->total_invested_data = $user->getTotalInvestedAttribute();
            $user->active_investments_data = $user->getActiveInvestmentsAttribute();
            $referral = $user->referrer;

            $token = $user->createToken('Api Token')->plainTextToken;

            return response()->json([
                'user' => $user,
                'referral' => $referral,
                'token' => $token
            ], 200);
        } catch (\Exception $e) {
            // ... (seu código de tratamento de erro)
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                // Adicione a linha abaixo para ver a stack trace do erro
                'trace' => $e->getTraceAsString()
            ], 400);
        }
    }

    public function update(Request $request)
    {
        // Obter o usuário autenticado usando Sanctum
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Não autorizado'
            ], 401);
        }

        // Obter o tipo de atualização
        $updateType = $request->input('update_type');

        switch ($updateType) {
            case 'perfil':
                return $this->updateProfile($request, $user);
            case 'carteira':
                return $this->updateWallet($request, $user);
            case 'senha':
                return $this->updatePassword($user, $request);
            default:
                return response()->json([
                    'status' => 400,
                    'message' => 'Tipo de atualização inválido ou não especificado.'
                ], 400);
        }
    }

    /**
     * Atualiza as informações do perfil do usuário
     */
    private function updateProfile(Request $request, User $user)
    {
        // Validação utilizando o sistema do Laravel
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|min:3|max:100',
            'email' => [
                'required',
                'email',
                'max:100',
                'unique:users,email,' . $user->id
            ],
            'phone' => 'nullable|regex:/^\d{10,11}$/',
            'phone_code' => 'nullable|string|max:255',
            'realname' => 'required|string|min:3|max:400',
            'username' => 'required|string|min:3|max:400',
        ], [
            'nome.required' => 'O nome é obrigatório',
            'nome.min' => 'O nome deve ter pelo menos 3 caracteres',
            'email.required' => 'O email é obrigatório',
            'email.email' => 'Formato de email inválido',
            'email.unique' => 'Este email já está em uso',
            'telefone.regex' => 'O telefone deve conter entre 10 e 11 dígitos',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erro de validação',
                'errors' => $validator->errors()
            ], 422);
        }

        // Atualizar o usuário
        $user->update([
            'nome' => $request->nome,
            'email' => $request->email,
            'phone' => $request->phone,
            'phone_code' => $request->phone_code,
            'realname' => $request->realname,
            'username' => $request->username,
        ]);

        // Carregar o usuário atualizado
        $user->refresh();

        // Retornar resposta, ocultando dados sensíveis
        return response()->json([
            'status' => 'success',
            'message' => 'Perfil atualizado com sucesso',
            'data' => $user->makeHidden(['senha', 'password'])
        ]);
    }


    /**
     * Atualiza as informações da carteira do usuário
     */
    private function updateWallet(Request $request, User $user)
    {
        // Definir regras de validação para carteira
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|min:5|max:300',
            'cpf' => 'required|string|min:11|max:11',
            'phone' => 'required|string|min:11|max:11',
            'pix_key_type' => 'required|string|in:CPF,EMAIL,PHONE,RANDOM',
            'pix_key' => 'required|string|min:9',
            'is_default' => 'nullable|boolean',
            'status' => 'required|string|in:active,inactive'
        ], [
            'full_name.required' => 'O nome completo é obrigatório',
            'full_name.min' => 'O nome deve ter no mínimo 5 letras',
            'cpf.required' => 'O número do CPF é obrigatório',
            'phone.required' => 'O número de telefone é obrigatório',
            'phone.min' => 'Informe um telefone no padrão brasileiro com ddd',
            'pix_key_type.required' => 'Tipo de chave obrigatório',
            'pix_key_type.in' => 'Tipo de chave inválida',
            'pix_key.required' => 'A chave pix para recebimento é obrigatória',
            'status.required' => 'O status é obrigatório'
        ]);


        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erro de validação',
                'errors' => $validator->errors()
            ], 422);
        }

        // Salvar atualização ou criar nova carteira
        try {
            // Buscar carteira existente ou criar nova
            $wallet = WithdrawalAccount::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'full_name' => $request->full_name,
                    'cpf' => $request->cpf,
                    'phone' => $request->phone,
                    'pix_key_type' => $request->pix_key_type,
                    'pix_key' => $request->pix_key,
                    'is_default' => $request->is_default ?? false,
                    'status' => $request->status ?? 'active',
                ]
            );

            $walletResponse = $wallet->toArray();

            return response()->json([
                'status' => 'success',
                'message' => 'Informações de pagamento atualizadas com sucesso',
                'data' => $walletResponse
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'status' => 500,
                'message' => 'Erro ao atualizar informações de pagamento.',
                'error' => $e->getMessage()
            ])->setStatusCode(500);
        }
    }

    /**
     * Atualiza a senha do usuário
     */
    private function updatePassword($user, $data)
    {
        // Definir regras de validação para senha
        $rules = [
            'senha_atual' => 'required',
            'nova_senha' => 'required|min_length[8]|max_length[255]',
            'confirmar_senha' => 'required|matches[nova_senha]',
        ];

        $validation = \Config\Services::validation();
        $validation->setRules($rules);

        if (!$validation->run($data)) {
            return $this->response->setJSON([
                'status' => 422,
                'message' => 'Erro de validação',
                'errors' => $validation->getErrors()
            ])->setStatusCode(422);
        }

        // Verificar se a senha atual está correta
        if (!password_verify($data['senha_atual'], $user['senha'])) {
            return $this->response->setJSON([
                'status' => 401,
                'message' => 'Senha atual incorreta.'
            ])->setStatusCode(401);
        }

        // Preparar dados para atualização
        $updateData = [
            'senha' => password_hash($data['nova_senha'], PASSWORD_DEFAULT),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Salvar atualização
        $userModel = new \App\Models\UserModel();
        if (!$userModel->update($user['id'], $updateData)) {
            return $this->response->setJSON([
                'status' => 500,
                'message' => 'Erro ao atualizar a senha.'
            ])->setStatusCode(500);
        }

        return $this->response->setJSON([
            'status' => 200,
            'message' => 'Senha atualizada com sucesso!'
        ])->setStatusCode(200);
    }

    public function exchangeApi(Request $request)
    {
        try {
            $validate = Validator::make($request->all(), [
                'wallet_type' => ['required', 'string', 'in:profit,balance'],
                'amount' => ['required', 'numeric', 'min:1']
            ]);

            if ($validate->fails()) {
                return response()->json($validate->fails());
            }

            $user = auth()->user();

            if (!$user) {
                return response()->json("Unauthenticated", 401);
            }

            $validated = $validate->validated();

            switch ($validated['wallet_type']) {
                case 'profit':
                    if ($user->profit_balance < $validated['amount']) {
                        return response()->json([
                            'success' => false,
                            'message' => "Saldo insuficiente"
                        ], 400);
                    }

                    $user->increment('balance', $validated['amount']);
                    $user->decrement('profit_balance', $validated['amount']);
                    break;

                default:
                    if ($user->balance < $validated['amount']) {
                        return response()->json([
                            'success' => false,
                            'message' => "Saldo insuficiente"
                        ], 400);
                    }

                    $user->increment('profit_balance', $validated['amount']);
                    $user->decrement('balance', $validated['amount']);
                    break;
            }

            $user = User::with('withdrawAccount')->find($user->id);

            return response()->json($user, 200);
        } catch (\Exception $e) {
            \Log::error("[TYPE]:EXCANGE-ERROR -> " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Informaçoes estatisticas de usuarios
     */
    public function statistics()
    {
        try {
            $totalCount = User::count();
            $totalActiveCount = User::where('status', 'active')->count();
            $totalBalance = User::sum('balance');
            $totalComissions = Transaction::where('type', TransactionTypes::COMISSION)->sum('amount');

            return response()->json(
                [
                    'total' => $totalCount,
                    'total_active' => $totalActiveCount,
                    'total_balance' => $totalBalance,
                    'total_comissions' => $totalComissions
                ]
            );
        } catch (\Exception $e) {
            return response()->json($e->getMessage(), 500);
        }
    }

    /**
     * List all custommers from admin
     * @return JsonResponse
     */
    public function customersList(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 10);
        $customers = User::with('withdrawAccount', 'purchases.plans')
            ->withCount('referrals')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        foreach ($customers as $user) {
            // Se você precisar dos outros accessors, chame-os individualmente antes de retornar
            $user->referral_data = $user->getReferralAttribute();
            $user->network_data = $user->getNetworkAttribute();
            $user->total_invested_data = $user->getTotalInvestedAttribute();
            $user->active_investments_data = $user->getActiveInvestmentsAttribute();
        }

        return response()->json($customers, 200);
    }

    /**
     * Acessar conta de cliente
     * @return JsonResponse
     */
    public function accessAccount(Request $request, User $user): JsonResponse
    {
        Auth::guard('web')->login($user);

        // Regenera a sessão para proteger contra session fixation
        // $request->session()->regenerate();

        return response()->json([
            'success' => true,
            'message' => 'Login efetuado com sucesso!',
            'data' => $user
        ], 200);
    }

    /**
     * Buscar usuários por nome, telefone, email, ref_id, username e CPF da conta de saque
     */
    public function searchCustomers(Request $request): JsonResponse
    {
        $query = $request->input('query'); // termo de busca

        $users = User::with(['withdrawAccount:id,user_id,cpf']) // carrega as contas de saque
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('phone', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%")
                    ->orWhere('ref_id', 'like', "%{$query}%")
                    ->orWhere('username', 'like', "%{$query}%");
            })
            ->orWhereHas('withdrawAccount', function ($q) use ($query) {
                $q->where('cpf', 'like', "%{$query}%");
            })
            ->paginate(10);

        // Adiciona o referral_data em cada usuário
        $users->getCollection()->transform(function ($user) {
            $user->referral_data = $user->getReferralAttribute();
            return $user;
        });

        return response()->json($users, 200);
    }



    public function register() {}

    public function renewPassword(Request $request)
    {
        $request->validate([
            'phone' => 'required|integer'
        ]);

        $user = User::where('phone', $validated['phone']);

        if (!$user) {
            response()->json([
                'message' => 'Usuário não encontrado'
            ], 400);
        }

        $code = mt_rand(100000, 999999);

        $user->remember_token = $code;
        $user->save();

        Mail::to($user->email)->send(new ResetPassword($code, $user->name));

        response()->json([
            'message' => 'Alteração se senha solicitada com sucesso!'
        ], 200);
    }


    public function single_deposit__pay($amount, $channel)
    {
        $user = auth()->user();

        $payload = [
            'value_cents' => $amount,
            'generator_name' => env('GATE_GENERATOR_NAME'),
            'generator_document' => env('GATE_GENERATOR_DOCUMENT')
        ];

        $deposit = $this->syncpay->cashIn($payload);

        $paymentCodeBase64 = $deposit['data']['paymentCodeBase64'];
        $paymentCode = $deposit['data']['paymentCode'];

        $model = new Deposit();
        $model->user_id = $user->id;
        $model->method_name = $channel;
        $model->address = 'PrimePag';
        $model->order_id = rand(0, 999999);
        $model->transaction_id = $deposit['data']['idTransaction'];
        $model->amount = $amount;
        $model->date = date('d-m-Y H:i:s');
        $model->status = 'pending';
        $model->save();

        $channel = PaymentMethod::where('name', $channel)->first();

        return view('app.main.deposit.recharge_confirm', compact('amount', 'channel', 'paymentCodeBase64', 'paymentCode', 'deposit'));
    }


    public function apiPayment(Request $request)
    {
        $webHookData = $request->all();

        $verify = $this->syncpay->processCashInWebhook($webHookData);

        if ($verify['success'] === true) {

            $deposit = Deposit::where('transaction_id', $verify['transaction_id'])->first();

            if ($deposit) {

                if ($deposit->status == 'approved') {
                    return response()->json([
                        'status' => 'fail',
                        'message' => 'Estra transação já foi processada.'
                    ], 400);
                }

                $deposit->status = 'approved';
                $deposit->save();

                $user = User::find($deposit->user_id);

                if ($user) {
                    $newBalance = $user->balance + $deposit->amount;

                    $user->balance = $newBalance;
                    $user->save();
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'Deposit successfully updated.'
                ], 200);
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Deposit not found.'
                ], 404);
            }
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Webhook verification failed.'
            ], 400);
        }
    }


    public function vip()
    {
        return view('app.main.vip');
    }


    public function description()
    {
        return view('app.main.description');
    }


    public function rating_immediate()
    {
        return view('app.main.rating_immediate');
    }

    public function message()
    {
        return view('app.main.message');
    }

    public function purchase_history()
    {
        return view('app.main.purchase_history');
    }

    public function history($condition = null)
    {
        return view('app.main.history', compact('condition'));
    }

    public function history_all()
    {
        return view('app.main.history_all');
    }

    public function ordered()
    {
        $activePackages = Purchase::where('user_id', auth()->user()->id)
            ->with('package')
            ->get();

        $users_ledgers = UserLedger::where('user_id', auth()->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return view('app.main.ordered', compact('activePackages', 'users_ledgers'));
    }


    public function exchange()
    {
        return view('app.main.exchange');
    }

    public function checkin()
    {
        $user = \auth()->user();
        if ($user->checkin > 0) {
            $checkin = new Checkin();
            $checkin->user_id = $user->id;
            $checkin->date = date('Y-m-d');
            $checkin->amount = $user->checkin;
            $checkin->save();

            $userUpdate = User::where('id', $user->id)->first();
            $userUpdate->balance = $user->balance + $user->checkin;
            $userUpdate->checkin = 0;
            $userUpdate->save();

            $ledger = new UserLedger();
            $ledger->user_id = $user->id;
            $ledger->reason = 'checkin';
            $ledger->perticulation = 'checkin commission received';
            $ledger->amount = $user->checkin;
            $ledger->debit = $user->checkin;
            $ledger->status = 'approved';
            $ledger->step = 'third';
            $ledger->date = date('d-m-Y H:i');
            $ledger->save();

            return response()->json(['message' => 'Check-in balance received.']);
        } else {
            return response()->json(['message' => 'Check-in balance 0']);
        }
    }

    public function vip_commission()
    {
        return view('app.main.vip_commission');
    }


    public function promotion()
    {
        return view('app.main.promotion');
    }

    public function task()
    {
        $user = Auth::user();
        //First Level Users
        $first_level_users = User::where('ref_by', $user->ref_id)->get();
        $first_level_users_ids = [];
        foreach ($first_level_users as $user) {
            array_push($first_level_users_ids, $user->id);
        }

        //Second Level Users
        $second_level_users_ids = [];
        foreach ($first_level_users as $element) {
            $users = User::where('ref_by', $element->ref_id)->get();
            foreach ($users as $user) {
                array_push($second_level_users_ids, $user->id);
            }
        }
        $second_level_users = User::whereIn('id', $second_level_users_ids)->get();

        //Third Level Users
        $third_level_users_ids = [];
        foreach ($second_level_users as $element) {
            $users = User::where('ref_by', $element->ref_id)->get();
            foreach ($users as $user) {
                array_push($third_level_users_ids, $user->id);
            }
        }
        $third_level_users = User::whereIn('id', $third_level_users_ids)->get();
        $team_size = $first_level_users->count() + $second_level_users->count() + $third_level_users->count();

        //Get level wise user ids
        $first_ids = $first_level_users->pluck('id'); //first
        $second_ids = $second_level_users->pluck('id'); //Second
        $third_ids = $third_level_users->pluck('id'); //Third

        $lv1Recharge = Deposit::whereIn('user_id', $first_ids)->where('status', 'approved')->sum('amount');
        $lv2Recharge = Deposit::whereIn('user_id', $second_ids)->where('status', 'approved')->sum('amount');
        $lv3Recharge = Deposit::whereIn('user_id', $third_ids)->where('status', 'approved')->sum('amount');
        $lvTotalDeposit = $lv1Recharge + $lv2Recharge + $lv3Recharge;

        $lv1Withdraw = Withdrawal::whereIn('user_id', $first_ids)->where('status', 'approved')->sum('amount');
        $lv2Withdraw = Withdrawal::whereIn('user_id', $second_ids)->where('status', 'approved')->sum('amount');
        $lv3Withdraw = Withdrawal::whereIn('user_id', $third_ids)->where('status', 'approved')->sum('amount');
        $lvTotalWithdraw = $lv1Withdraw + $lv2Withdraw + $lv3Withdraw;

        $activeMembers1 = Deposit::whereIn('user_id', $first_ids)->where('status', 'approved')->groupBy('user_id')->count();
        $activeMembers2 = Deposit::whereIn('user_id', $second_ids)->where('status', 'approved')->groupBy('user_id')->count();
        $activeMembers3 = Deposit::whereIn('user_id', $third_ids)->where('status', 'approved')->groupBy('user_id')->count();


        $Lv1active = 0;
        $Lv2active = 0;
        $Lv3active = 0;

        foreach ($first_level_users as $uuss) {
            $purchase = Purchase::where('user_id', $uuss->id)->first();
            if ($purchase) {
                $Lv1active = $Lv1active + 1;
            }
        }
        foreach ($second_level_users as $uuss) {
            $purchase = Purchase::where('user_id', $uuss->id)->first();
            if ($purchase) {
                $Lv2active = $Lv2active + 1;
            }
        }
        foreach ($third_level_users as $uuss) {
            $purchase = Purchase::where('user_id', $uuss->id)->first();
            if ($purchase) {
                $Lv3active = $Lv3active + 1;
            }
        }

        $teamTotalActiveMembers = $Lv1active + $Lv2active + $Lv3active;


        return view('app.main.task', compact('team_size', 'teamTotalActiveMembers', 'lv1Recharge', 'lv2Recharge', 'lv3Recharge', 'lv1Withdraw', 'lv2Withdraw', 'lv3Withdraw', 'first_level_users', 'second_level_users', 'third_level_users'));
    }

    public function task_history()
    {
        return view('app.main.task_history');
    }

    public function reword_history()
    {
        return view('app.main.reword_history');
    }

    public function recharge_history()
    {
        return view('app.main.deposit_history');
    }

    public function commission()
    {
        return view('app.main.commission');
    }

    public function amount_history()
    {
        return view('app.main.amount_history');
    }


    public function profile()
    {
        return view('app.main.profile');
    }

    public function team()
    {
        $settings = Setting::first();
        $rebates = Rebate::first();
        return view('app.main.team.index', compact('settings', 'rebates'));
    }


    public function setting()
    {
        return view('app.main.mine.setting');
    }

    public function recharge()
    {
        $settings = Setting::first();
        return view('app.main.deposit.index', compact('settings'));
    }

    public function recharge_amount($amount)
    {
        return view('app.main.deposit.recharge_confirm', compact('amount'));
    }

    public function payment_confirm($amount, $payment_method)
    {
        $payment_method = PaymentMethod::where('name', $payment_method)->inRandomOrder()->first();
        if (!$payment_method) {
            return back()->with('success', 'Method not available.');
        }

        return view('app.main.deposit.payment-confirm', compact('amount', 'payment_method'));
    }

    public function depositSubmit(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'acc_acount' => 'required',
            'amount' => 'required',
            'payment_method' => 'required',
            'transaction_id' => 'required',
            'photo' => 'required',
        ]);

        if ($validate->fails()) {
            return back()->withErrors($validate->errors());
        }

        $model = new Deposit();
        $model->user_id = Auth::id();

        $path = uploadImage(false, $request, 'photo', 'upload/payment/', 200, 200, $model->photo);
        $model->photo = $path ?? $model->photo;

        $model->method_name = $request->payment_method;
        $model->method_number = $request->acc_acount;
        $model->order_id = rand(00000, 99999);
        $model->transaction_id = $request->transaction_id;
        $model->amount = $request->amount;
        $model->final_amount = $request->amount;
        $model->date = date('d-m-Y H:i:s');
        $model->status = 'pending';
        $model->save();
        return redirect()->route('user.deposit')->with('success', 'Successful');
    }

    public function update_profile(Request $request)
    {
        $user = User::find(Auth::id());
        $path = uploadImage(false, $request, 'photo', 'upload/profile/', 200, 200, $user->photo);
        $user->photo = $path ?? $user->photo;

        $user->update();
        return redirect()->route('my.profile')->with('success', 'Successful');
    }

    public function personal_details()
    {
        return view('app.main.update_personal_details');
    }

    public function card()
    {
        $methods = PaymentMethod::where('status', 'active')->where('id', '!=', 4)->get();

        return view('app.main.gateway_setup', compact('methods'));
    }

    public function setupGateway(Request $request)
    {
        $validateData = $request->validate([
            'name' => 'required|string|max:255',
            'gateway_method' => 'required|string|max:255',
            'gateway_number' => 'required|string|max:255',
            'pix_type' => 'required|string|max:255',
            'pix_key' => 'required|string|max:255',
        ]);

        if ($request->name == '' || $request->gateway_method == '' || $request->gateway_number == '') {
            return redirect()->back()->with('success', 'Please enter correct bank info');
        }


        User::where('id', Auth::id())->update([
            'name' => $request->name,
            'realname' => $request->name,
            'gateway_method' => $request->gateway_method,
            'gateway_number' => $request->gateway_number,
            'pix_type' => $request->pix_type,
            'pix_key' => $request->pix_key
        ]);
        return redirect()->back()->with('success', 'Bank info created.');
    }

    public function invite()
    {
        return view('app.main.invite');
    }

    public function level()
    {
        return view('app.main.level');
    }


    public function service()
    {
        return view('app.main.service');
    }


    public function appreview()
    {
        return view('app.main.appreview');
    }

    public function rule()
    {
        return view('app.main.rule');
    }

    public function partner()
    {
        return view('app.main.partner');
    }

    public function climRecord()
    {
        return view('app.main.climRecord');
    }

    public function add_bank()
    {
        return view('app.main.gateway_setup');
    }

    public function add_bank_create()
    {
        return view('app.main.add_bank_create');
    }

    public function setting_change_password(Request $request)
    {
        //Check current password
        $user = User::find(Auth::id());
        if (Hash::check($request->old_password, $user->password)) {
            if ($request->new_password == $request->confirm_password) {
                $user->password = Hash::make($request->new_password);
                $user->update();
                return redirect()->route('login_password')->with('success', 'Password changed');
            } else {
                return redirect()->route('login_password')->with('success', 'Password not match.');
            }
        } else {
            return redirect()->route('login_password')->with('success', 'Password not match');
        }
    }

    public function confirm_submit(Request $request)
    {
        $data = $request->all();
        $model = new Deposit();
        $model->user_id = $data['ui'];
        $model->method_name = $data['pm'];
        $model->method_number = '01000000000';
        $model->order_id = $data['oid'];
        $model->transaction_id = $data['tid'];
        $model->number = $data['aca'];
        $model->amount = $data['amount'];
        $model->final_amount = $data['amount'];
        $model->usdt = $data['amount'] / setting('rate');
        $model->date = Carbon::now();
        $model->status = 'pending';
        $model->save();
        return response()->json(['status' => true, 'data' => $data]);
    }

    public function download_apk()
    {
        return response()->json(['message' => 'not file']);
    }
}
