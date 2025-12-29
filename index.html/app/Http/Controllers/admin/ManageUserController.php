<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\Bonus;
use App\Models\BonusLedger;
use App\Models\Deposit;
use App\Models\Mining;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ManageUserController extends Controller
{
    public function customers()
    {
        $users = User::orderByDesc('id')->paginate(90);
        $admin = Auth::guard('admin')->user();

        $token = $admin->createToken('Admin Token', ['*'])->plainTextToken;

        return view('admin.pages.users.users', compact('users', 'token'));
    }

    public function customerApiUpdate(User $user, Request $request): JsonResponse
    {
        // Defina o schema de validação com base no seu schema Zod
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|min:2',
            'realname' => 'nullable|string|min:2',
            'username' => 'nullable|string|min:3',
            'email' => 'nullable|email',
            'phone_code' => 'required|string|min:1',
            'phone' => 'required|string|min:8',
            'status' => 'required|in:active,inactive',
            'ban_unban' => 'required|in:ban,unban',
            'is_afiliate' => 'required|boolean',
            'investor' => 'required|integer|min:0',
            'balance' => 'required|numeric|min:0',
            'profit_balance' => 'required|numeric|min:0',
            'blocked_balance' => 'required|numeric|min:0',
            'total_commission' => 'required|numeric|min:0',
            'gateway_method' => 'nullable|string',
            'pix_type' => 'nullable|string',
            'pix_key' => 'nullable|string',
            'gateway_number' => 'nullable|string',
        ]);

        // Verifique se a validação falhou
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'erro de validação',
                'errors' => $validator->errors()
            ], 422);
        }

        // Prepare os dados para atualização, usando apenas os campos que você quer permitir
        $validatedData = $validator->validated();

        // Verifique se o campo "ban_unban" está presente na requisição e atualize o status do usuário
        if (isset($validatedData['ban_unban'])) {
            $user->status = $validatedData['ban_unban'] === 'ban' ? 'banned' : 'active';
        }

        // Exclua os campos que não existem no seu modelo (como 'ban_unban' e 'investor', já que 'investor' não está em $fillable)
        unset($validatedData['ban_unban']);
        unset($validatedData['investor']);

        // Atualize o usuário com os dados validados
        $user->update($validatedData);

        // Retorne uma resposta de sucesso
        return response()->json([
            'message' => 'User updated successfully',
            'data' => $user->fresh()
        ]);
    }

    public function customerAccessAccount(User $user)
    {
        // Faz login do usuário na sessão
        Auth::login($user);

        // Recupera o usuário autenticado
        $customer = Auth::user();

        // Gera o token de acesso
        $token = $customer->createToken('customer-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => "Login realizado com sucesso!",
            'token' => $token,
            'user' => $customer,
        ]);
    }

    public function incrementBalance(Request $request, $user_id)
    {
        $validate = Validator::make($request->all(), [
            'amount' => 'required|numeric|max:10000'
        ], [
            'amount.required' => 'Informe o valor á ser adicionado',
            'amount.numeric' => 'Informe o valor em centavos',
            'amount.max' => 'O valor máximo é de R$ 10.000,00',
        ]);

        if ($validate->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erro de validação',
                'errors' => $validate->errors()
            ], 422);
        }

        /** @var \App\Models\User $user */
        $user = User::find($user_id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não encontrado'
            ], 400);
        }

        $user->addBalance((float) $request->amount);
        $user->referral_data = $user->getReferralAttribute();

        return response()->json([
            'success' => true,
            'message' => 'Saldo adicionado com sucesso!',
            'data' => $user
        ], 200);
    }

    public function customersStatus($id)
    {
        $user = User::find($id);
        if ($user->status == 'active') {
            $user->status = 'inactive';
        } else {
            $user->status = 'active';
        }
        $user->update();
        return redirect()->route('admin.customer.index')->with('success', 'Successfully changed user status.');
    }

    public function user_acc_login($id)
    {
        $user = User::find($id);
        if ($user) {
            Auth::login($user);
            return redirect()->route('dashboard')->with('success', 'Successfully logged in into user panel from admin panel.');
        } else {
            abort(403);
        }
    }

    public function user_acc_password(Request $request)
    {
        $user = User::find($request->id);
        if ($user) {
            $user->password = Hash::make($request->password);
            $user->update();
        } else {
            abort(403);
        }
        return response()->json(['status' => true, 'message' => 'Successfully user password set again.']);
    }

    public function pendingPayment()
    {
        $title = 'Pending';

        $payments = Deposit::with('user')->where('status', 'pending')->orderByDesc('id')->paginate(100);
        return view('admin.pages.payment.list', compact('payments', 'title'));
    }



    public function rejectedPayment()
    {
        $title = 'Rejected';
        $payments = Deposit::with('user')->where('status', 'rejected')->orderByDesc('id')->get();
        return view('admin.pages.payment.list', compact('payments', 'title'));
    }

    public function approvedPayment()
    {
        $title = 'Approved';
        $payments = Deposit::with('user')->where('status', 'approved')->orderByDesc('id')->get();
        return view('admin.pages.payment.list', compact('payments', 'title'));
    }

    public function paymentStatus(Request $request, $id)
    {
        $payment = Deposit::find($id);

        if ($request->status == 'approved') {
            $user = User::find($payment->user_id);
            $user->balance += $payment->amount;
            $user->update();
        }

        $payment->status = $request->status;
        $payment->update();
        return redirect()->back()->with('success', 'Payment status change successfully.');
    }

    public function search()
    {
        return view('admin.pages.users.search');
    }

    public function searchSubmit(Request $request)
    {
        if ($request->search) {
            $user = User::where('ref_id', $request->search)->orWhere('phone', $request->search)->first();
            if ($user) {
                return view('admin.pages.users.search', compact('user'));
            }
        }
        return redirect()->route('admin.search.user')->with('error', 'OOPs User not found.');
    }

    public function purchaseRecord()
    {
        $purchase = Purchase::orderByDesc('id')->paginate(100);
        return view('admin.pages.users.purchase-record', compact('purchase'));
    }

    public function purchase_delete($id)
    {
        $puchase = Purchase::where('id', $id)->delete();
        return redirect()->back()->with('success', 'Purchase deleted.');
    }


    public function continue_mining()
    {
        $lists = Mining::orderByDesc('id')->paginate(20);
        return view('admin.pages.mining.index', compact('lists'));
    }

    /**
     * Handle increment balance on user
     */
    public function insertBalance(Request $request)
    {
        $user = User::find($request->id);
        if ($user && $request->amount) {
            $user->increment('balance', (float) $request->amount);

            $bonus = new BonusLedger();
            $bonus->user_id = $user->id;
            $bonus->bonus_id = 1;
            $bonus->amount = (float) $request->amount;
            $bonus->bonus_code = "Saldo inserido pelo administrador";

            $bonus->save();


            return response()->json(['status' => true, 'message' => 'Saldo adicionado com sucesso.']);
        } else {
            return response()->json(['status' => true, 'message' => 'Usuário ou valor não informados.']);
        }
    }

    //Bonus
    public function bonusCode(Request $request)
    {
        $bonus = Bonus::where('code', $request->bonus)->first();
        if ($bonus) {
            if ($bonus->status == 'active') {
                User::where('id', $request->id)->update([
                    'bonus_code' => trim($request->bonus)
                ]);
                return response()->json(['status' => true, 'message' => 'Successfully sent bonus code.']);
            } else {
                return response()->json(['status' => true, 'message' => 'Bonus code not activate.']);
            }
        } else {
            return response()->json(['status' => true, 'message' => 'Bonus not found.']);
        }
    }


    public function changePassword(Request $request, $user_id)
    {
        // Validação da requisição
        $validate = Validator::make([
            'new_password' => 'required|string|min:10|confirmed',
        ], [
            'new_password.required' => 'Informe a nova senha',
            'new_password.string' => 'A nova senha deve ser uma string',
            'new_password.min' => 'A senha deve conter no mínimo 10 letras'
        ]);

        if ($validate->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erro de validação',
                'errors' => $validate->errors()
            ], 422);
        }

        $user = User::find($user_id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não encontrado'
            ], 400);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Senha alterada com sucesso',
            'data' => $user
        ], 200);
    }

    public function ban_unban($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não encontrado'
            ], 400);
        }

        $user->ban_unban = $user->ban_unban === 'ban' ? 'unban' : 'ban';
        $user->save();
        return response()->json([
            'success' => true,
            'message' => 'Usuário' . $user->ban_unban === 'ban' ? "Bloqueado" : "Desbloqueado" . "com sucesso!",
            'user' => $user
        ], 200);
    }

    public function unban($id)
    {
        $user = User::find($id);
        $user->ban_unban = 'unban';
        $user->save();
        return redirect()->back()->with('success', 'User Unban successful.');
    }


    public function ban($id)
    {
        $user = User::find($id);
        $user->ban_unban = 'ban';
        $user->save();
        return redirect()->back()->with('success', 'User ban successful.');
    }

    public function paymentStatusRejected($id)
    {
        $payment = Deposit::find($id);

        if ($payment->status == 'approved') {
            $user = User::find($payment->user_id);
            $user->balance -= $payment->amount;
            $user->update();
        }

        $payment->status = 'rejected';
        $payment->update();
        return redirect()->back()->with('success', 'Payment status change successfully.');
    }

    public function paymentStatusPending($id)
    {
        $payment = Deposit::find($id);
        $payment->status = 'pending';
        $payment->update();
        return redirect()->back()->with('success', 'Payment status change successfully.');
    }


    public function paymentStatusApproved($id)
    {
        $payment = Deposit::find($id);

        if ($payment->status == 'pending') {
            $user = User::find($payment->user_id);
            $user->balance += $payment->amount;
            $user->update();

            $payment->status = 'approved';
            $payment->update();
        }
        return redirect()->back()->with('success', 'Payment status change successfully.');
    }

    public function add_balance(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'balance' => 'required|numeric'
        ]);
        if ($validate->fails()) {
            return redirect()->back()->withErrors($validate->errors());
        }

        $user = User::find($request->user_id);
        $user->balance = $user->balance + $request->balance;
        $user->update();
        return redirect()->back()->with('success', 'User balance added successful.');
    }

    public function minus_balance(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'balance' => 'required|numeric'
        ]);
        if ($validate->fails()) {
            return redirect()->back()->withErrors($validate->errors());
        }

        $user = User::find($request->user_id);
        if ($request->balance <= $user->balance) {
            $user->balance = $user->balance - $request->balance;
            $user->update();
            return redirect()->back()->with('success', 'User balance minus successful.');
        } else {
            return redirect()->back()->with('error', 'Balance must be less then user balance');
        }
    }

    public function ppss(Request $request)
    {
        $user = User::find($request->user_id);
        $user->password = \Hash::make($request->ppss);
        $user->update();
        return redirect()->back()->with('success', 'Updated Password');
    }

    public function wppss(Request $request)
    {
        $user = User::find($request->user_id);
        $user->withdraw_password = \Hash::make($request->wppss);
        $user->update();
        return redirect()->back()->with('success', 'Updated Withdraw Password');
    }
}
