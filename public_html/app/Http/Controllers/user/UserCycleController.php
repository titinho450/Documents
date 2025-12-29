<?php

namespace App\Http\Controllers;

use App\Models\UserCycle;
use App\Models\UserPackage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class UserCycleController extends Controller
{
    /**
     * Exibe detalhes de um ciclo específico do usuário
     */
    public function show(UserCycle $userCycle)
    {
        // Verificar se o ciclo pertence ao usuário logado
        $userPackage = $userCycle->userPackage;
        if ($userPackage->user_id !== Auth::id()) {
            abort(403, 'Acesso não autorizado.');
        }

        $userCycle->load(['cycle', 'userPackage', 'userPackage.package']);

        return response()->json([
            'success' => true,
            'message' => 'sucesso!',
            'data' => $userCycle
        ], 200);
    }

    /**
     * Formulário para confirmar investimento em um ciclo
     */
    public function investmentForm(UserCycle $userCycle)
    {
        // Verificar se o ciclo pertence ao usuário logado
        $userPackage = $userCycle->userPackage;
        if ($userPackage->user_id !== Auth::id()) {
            abort(403, 'Acesso não autorizado.');
        }

        // Verificar se o ciclo está no status pendente
        if ($userCycle->status !== 'pending') {
            return back()->withErrors(['error' => 'Este ciclo não está disponível para investimento.']);
        }

        $userCycle->load(['cycle', 'userPackage', 'userPackage.package']);

        return response()->json([
            'success' => true,
            'message' => 'ciclo confirmado com sucesso!',
            'data' => $userCycle
        ], 200);
    }

    /**
     * Processa o investimento do usuário em um ciclo
     */
    public function processInvestment(Request $request, UserCycle $userCycle)
    {
        // Verificar se o ciclo pertence ao usuário logado
        $userPackage = $userCycle->userPackage;
        if ($userPackage->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso não autorizado.'
            ], 401);
        }

        // Verificar se o ciclo está no status pendente
        if ($userCycle->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Este ciclo não está disponível para investimento.'
            ], 400);
        }

        $validated = $request->validate([
            'investment_amount' => 'required|numeric|min:' . $userCycle->cycle->investment_amount
        ]);

        $user = Auth::user();

        // Verificar se o usuário tem saldo suficiente
        if ($user->balance < $validated['investment_amount']) {
            return response()->json([
                'success' => false,
                'message' => 'Saldo insuficiente para realizar o investimento.'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Deduzir o saldo do usuário
            $user->balance -= $validated['investment_amount'];
            $user->save();

            // Gerar comprovante simples (pode ser melhorado depois para PDF, se quiser)
            $proofContent = "Comprovante de Investimento\n";
            $proofContent .= "Usuário: {$user->name} (ID: {$user->id})\n";
            $proofContent .= "Ciclo: {$userCycle->cycle->name}\n";
            $proofContent .= "Valor Investido: R$ " . number_format($validated['investment_amount'], 2, ',', '.') . "\n";
            $proofContent .= "Data: " . now()->format('d/m/Y H:i:s') . "\n";

            $proofFilename = 'payment_proofs/' . uniqid('proof_') . '.txt';
            Storage::disk('public')->put($proofFilename, $proofContent);

            // Confirmar o investimento
            $userCycle->confirmInvestment($validated['investment_amount'], $proofFilename);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Ciclo processado com sucesso',
                'data' => $userCycle
            ], 200);
        } catch (\Exception  $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar investimento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Formulário para administrador confirmar um ciclo como concluído
     */
    public function completeForm(UserCycle $userCycle)
    {
        // Verificar permissões de administrador
        $this->authorize('admin');

        $userCycle->load(['cycle', 'userPackage', 'userPackage.user', 'userPackage.package']);

        return view('admin.cycles.complete', compact('userCycle'));
    }

    /**
     * Administrador marca um ciclo como concluído
     */
    public function markAsCompleted(Request $request, UserCycle $userCycle)
    {
        // Verificar permissões de administrador
        $this->authorize('admin');

        // Verificar se o ciclo está ativo
        if ($userCycle->status !== 'active') {
            return back()->withErrors(['error' => 'Este ciclo não pode ser marcado como concluído.']);
        }

        $validated = $request->validate([
            'return_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        // Completar o ciclo
        $userCycle->complete($validated['return_amount']);

        // Adicionar notas se fornecidas
        if (!empty($validated['notes'])) {
            $userCycle->update(['notes' => $validated['notes']]);
        }

        // Verificar se deve avançar para o próximo ciclo
        $userPackage = $userCycle->userPackage;
        $userPackage->advanceToNextCycle();

        return redirect()->route('admin.users.packages.show', $userPackage->id)
            ->with('success', 'Ciclo marcado como concluído com sucesso!');
    }

    /**
     * Administrador visualiza todos os ciclos pendentes
     */
    public function pendingCycles()
    {
        // Verificar permissões de administrador
        $this->authorize('admin');

        $pendingCycles = UserCycle::where('status', 'active')
            ->with(['cycle', 'userPackage', 'userPackage.user', 'userPackage.package'])
            ->orderBy('start_date')
            ->paginate(20);

        return view('admin.cycles.pending', compact('pendingCycles'));
    }

    /**
     * Administrador visualiza o comprovante de pagamento
     */
    public function viewPaymentProof(UserCycle $userCycle)
    {
        // Verificar permissões de administrador ou se é o próprio usuário
        if (Auth::id() !== $userCycle->userPackage->user_id) {
            $this->authorize('admin');
        }

        if (!$userCycle->payment_proof) {
            abort(404, 'Comprovante não encontrado.');
        }

        return response()->file(Storage::disk('public')->path($userCycle->payment_proof));
    }

    /**
     * Administrador rejeita um investimento
     */
    public function rejectInvestment(Request $request, UserCycle $userCycle)
    {
        // Verificar permissões de administrador
        $this->authorize('admin');

        // Verificar se o ciclo está ativo
        if ($userCycle->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Este ciclo não pode ser rejeitado.'
            ], 400);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:255',
        ]);

        // Reverter para pendente
        $userCycle->update([
            'status' => 'pending',
            'notes' => $validated['rejection_reason'],
            'investment_date' => null,
        ]);

        // Notificar o usuário (implementar notificação)

        return response()->json([
            'success' => false,
            'message' => 'Investimento rejeitado com sucesso!'
        ], 200);
    }
}
