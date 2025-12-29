<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Cycle;
use App\Models\CyclePlan;
use App\Models\UserPackage;
use App\Models\UserCycle;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminPackageController extends Controller
{


    /**
     * Lista todos os pacotes (ativos e inativos)
     */
    public function index()
    {
        $packages = Package::withCount(['userPackages' => function ($query) {
            $query->where('status', 'active');
        }])
            ->orderBy('status')
            ->orderBy('name')
            ->paginate(20);

        return view('admin.packages.index', compact('packages'));
    }

    /**
     * Lista todos os usuários inscritos em um pacote específico
     */
    public function users(Package $package)
    {
        $userPackages = UserPackage::where('package_id', $package->id)
            ->with(['user', 'currentCycle', 'currentCycle.cycle'])
            ->orderBy('status')
            ->orderBy('start_date', 'desc')
            ->paginate(20);

        return view('admin.packages.users', compact('package', 'userPackages'));
    }

    /**
     * Lista detalhes de um pacote de usuário específico
     */
    public function showUserPackage(UserPackage $userPackage)
    {
        $userPackage->load([
            'user',
            'package',
            'currentCycle',
            'userCycles' => function ($query) {
                $query->orderBy('cycle_id');
            },
            'userCycles.cycle'
        ]);

        return view('admin.packages.user_package_details', compact('userPackage'));
    }

    /**
     * Formulário para adicionar ou editar um ciclo
     */
    public function editCycle(Package $package, Cycle $cycle)
    {
        $isEditing = $cycle;
        $title = $isEditing ? 'Editar Ciclo' : 'Adicionar Ciclo';

        // Se for edição, verificar se o ciclo pertence ao pacote
        if ($isEditing && $cycle->package_id !== $package->id) {
            abort(404);
        }

        // Obter a próxima sequência disponível
        $nextSequence = $isEditing ? $cycle->sequence : ($package->cycles()->max('sequence') + 1);

        return view('admin.packages.cycles.edit', compact('package', 'cycle', 'isEditing', 'title', 'nextSequence'));
    }

    /**
     * Formulário para adicionar ou editar um ciclo
     */
    public function createCycle(Package $package)
    {
        $isEditing = null;
        $title = $isEditing ? 'Editar Ciclo' : 'Adicionar Ciclo';

        // Obter a próxima sequência disponível
        $nextSequence = $isEditing ? $cycle->sequence : ($package->cycles()->max('sequence') + 1);

        return view('admin.packages.cycles.create', compact('package', 'isEditing', 'title', 'nextSequence'));
    }


    /**
     * Salvar um ciclo (novo ou editado)
     */
    public function edit(Request $request, Package $package, ?Cycle $cycle = null)
    {
        $isEditing = $cycle !== null;

        // Validar o formulário
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sequence' => 'required|integer|min:1',
            'investment_amount' => 'required|numeric|min:0',
            'duration_days' => 'required|integer|min:1',
            'commission_percentage' => 'required|numeric|min:0|max:100',
            'return_percentage' => 'required|numeric|min:0',
            'requirements' => 'nullable|array',
        ]);

        // Garantir que requirements seja um array ou null
        if (empty($validated['requirements'])) {
            $validated['requirements'] = null;
        }

        DB::beginTransaction();
        try {
            // Verificar se a sequência solicitada já está em uso por outro ciclo
            if ($isEditing) {
                $existingCycle = Cycle::where('package_id', $package->id)
                    ->where('sequence', $validated['sequence'])
                    ->where('id', '!=', $cycle->id)
                    ->first();
            } else {
                $existingCycle = Cycle::where('package_id', $package->id)
                    ->where('sequence', $validated['sequence'])
                    ->first();
            }

            // Se a sequência já estiver em uso, reorganizar os ciclos
            if ($existingCycle) {
                // Aumentar a sequência de todos os ciclos iguais ou maiores
                Cycle::where('package_id', $package->id)
                    ->where('sequence', '>=', $validated['sequence'])
                    ->increment('sequence');
            }

            // Salvar o ciclo
            if ($isEditing) {
                // Remover campos que não devem ser atualizados automaticamente
                $updateData = collect($validated)->except(['status'])->toArray();
                $cycle->update($updateData);
            } else {
                $cycle = new Cycle();
                $cycle->fill($validated);
                $cycle->package_id = $package->id;
                $cycle->status = 'active';
                $cycle->save();
            }

            // Recalcular os totais do pacote
            $totalDuration = $package->cycles()->sum('duration_days');
            $totalInvestment = $package->cycles()->sum('investment_amount');

            $package->update([
                'total_duration' => $totalDuration,
                'total_investment' => $totalInvestment,
            ]);

            DB::commit();

            return redirect()->route('admin.packages.show', $package)
                ->with('success', $isEditing ? 'Ciclo atualizado com sucesso!' : 'Ciclo adicionado com sucesso!');
        } catch (\Exception $e) {
            DB::rollBack();
            // Log o erro para debug
            \Log::error('Erro ao salvar ciclo: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());

            return back()->withInput()->withErrors(['error' => 'Erro ao salvar ciclo: ' . $e->getMessage()]);
        }
    }

    /**
     * Salvar um ciclo (novo ou editado)
     */
    public function saveCycle(Request $request, Package $package)
    {
        $rules = [
            'description' => 'nullable|string',
            'sequence' => 'required|integer|min:1',
            'requirements' => 'nullable|array',
        ];

        $messages = [
            'sequence.required' => 'A sequência é obrigatória.',
            'sequence.integer' => 'A sequência deve ser um número inteiro.',
            'sequence.min' => 'A sequência deve ser no mínimo 1.',
        ];

        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return back()->withInput()->withErrors($validator);
        }

        $validated = $validator->validated();

        // Garantir que requirements seja um array ou null
        if (empty($validated['requirements'])) {
            $validated['requirements'] = null;
        }

        DB::beginTransaction();
        try {

            // Salvar o ciclo
            $cycle = new Cycle();
            $cycle->fill($validated);
            $cycle->package_id = $package->id;
            $cycle->status = 'active';
            $cycle->save();

            DB::commit();

            return redirect()->route('admin.packages.index', $package)
                ->with('success', 'Ciclo adicionado com sucesso!');
        } catch (\Exception $e) {
            DB::rollBack();
            // Log o erro para debug
            \Log::error('Erro ao salvar ciclo: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());

            return back()->withInput()->withErrors(['error' => 'Erro ao salvar ciclo: ' . $e->getMessage()]);
        }
    }

    /**
     * Remover um ciclo
     */
    public function deleteCycle(Package $package, Cycle $cycle)
    {
        // Verificar se o ciclo pertence ao pacote
        if ($cycle->package_id !== $package->id) {
            abort(404);
        }

        // Verificar se há usuários usando este ciclo
        $userCycles = UserCycle::where('cycle_id', $cycle->id)->count();
        if ($userCycles > 0) {
            return back()->withErrors(['error' => 'Este ciclo não pode ser removido pois há usuários inscritos nele.']);
        }

        DB::beginTransaction();
        try {
            // Salvar a sequência do ciclo que será removido
            $removedSequence = $cycle->sequence;

            // Remover o ciclo
            $cycle->delete();

            // Reorganizar as sequências dos ciclos restantes
            Cycle::where('package_id', $package->id)
                ->where('sequence', '>', $removedSequence)
                ->decrement('sequence');

            // Recalcular os totais do pacote
            $totalDuration = $package->cycles()->sum('duration_days');
            $totalInvestment = $package->cycles()->sum('investment_amount');

            $package->update([
                'total_duration' => $totalDuration,
                'total_investment' => $totalInvestment,
            ]);

            DB::commit();

            return redirect()->route('admin.packages.show', $package)
                ->with('success', 'Ciclo removido com sucesso!');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Erro ao remover ciclo: ' . $e->getMessage()]);
        }
    }

    /**
     * Formulário para adicionar manualmente um usuário a um pacote
     */
    public function addUserForm(Package $package)
    {
        $users = User::orderBy('name')->get();

        return view('admin.packages.add_user', compact('package', 'users'));
    }

    /**
     * Adicionar manualmente um usuário a um pacote
     */
    public function addUser(Request $request, Package $package)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'start_cycle' => 'required|exists:cycles,id',
        ]);

        // Verificar se o ciclo pertence ao pacote
        $startCycle = Cycle::findOrFail($validated['start_cycle']);
        if ($startCycle->package_id !== $package->id) {
            return back()->withErrors(['error' => 'O ciclo selecionado não pertence a este pacote.']);
        }

        // Verificar se o usuário já está inscrito neste pacote
        $existingSubscription = UserPackage::where('user_id', $validated['user_id'])
            ->where('package_id', $package->id)
            ->whereIn('status', ['active', 'pending'])
            ->first();

        if ($existingSubscription) {
            return back()->withErrors(['error' => 'Este usuário já está inscrito neste pacote.']);
        }

        DB::beginTransaction();
        try {
            // Calcular duração restante
            $remainingDuration = $package->cycles()
                ->where('sequence', '>=', $startCycle->sequence)
                ->sum('duration_days');

            // Criar a inscrição do usuário no pacote
            $userPackage = UserPackage::create([
                'user_id' => $validated['user_id'],
                'package_id' => $package->id,
                'start_date' => now(),
                'current_cycle_id' => $startCycle->id,
                'current_sequence' => $startCycle->sequence,
                'expected_end_date' => now()->addDays($remainingDuration),
                'total_invested' => 0,
                'total_earned' => 0,
                'status' => 'active',
            ]);

            // Criar o ciclo inicial do usuário
            UserCycle::create([
                'user_package_id' => $userPackage->id,
                'cycle_id' => $startCycle->id,
                'start_date' => now(),
                'expected_end_date' => now()->addDays($startCycle->duration_days),
                'investment_amount' => $startCycle->investment_amount,
                'status' => 'pending',
            ]);

            DB::commit();

            return redirect()->route('admin.packages.users', $package)
                ->with('success', 'Usuário adicionado ao pacote com sucesso!');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Erro ao adicionar usuário: ' . $e->getMessage()]);
        }
    }

    /**
     * Dashboard de visão geral de pacotes
     */
    public function dashboard()
    {
        $stats = [
            'total_packages' => Package::where('status', 'active')->count(),
            'total_active_users' => UserPackage::where('status', 'active')->count(),
            'total_invested' => UserCycle::where('status', 'active')->sum('investment_amount'),
            'total_completed' => UserCycle::where('status', 'completed')->count(),
        ];

        $topPackages = Package::withCount(['userPackages' => function ($query) {
            $query->where('status', 'active');
        }])
            ->orderByDesc('user_packages_count')
            ->limit(5)
            ->get();

        $recentInvestments = UserCycle::where('status', 'active')
            ->with(['userPackage.user', 'cycle'])
            ->orderByDesc('investment_date')
            ->limit(10)
            ->get();

        return view('admin.packages.dashboard', compact('stats', 'topPackages', 'recentInvestments'));
    }

    /**
     * formulário de cadastro de plano
     */
    public function planForm(Request $request, Cycle $cycle, ?CyclePlan $plan = null)
    {

        return view('admin.packages.plans.create', compact('cycle', 'plan'));
    }

    /**
     * formulário de cadastro de plano
     */
    public function planEditForm(Request $request, Cycle $cycle, CyclePlan $plan)
    {

        return view('admin.packages.plans.update', compact('cycle', 'plan'));
    }
    /**
     * Cadastro e edição novo plano de ciclo
     */
    public function planCreate(Request $request, Cycle $cycle)
    {

        // Validar o formulário
        $validated = $request->validate([
            'duration_days' => 'required|integer|min:1',
            'investment_amount' => 'nullable|string',
            'return_percentage' => 'required|integer|min:1',
            'sequence' => 'required|integer|min:1'
        ]);

        DB::beginTransaction();
        try {
            // Verificar se a sequência solicitada já está em uso por outro ciclo
            $returnPercentage = $validated['return_percentage'] / 100;
            $validated['return_amount'] = $validated['investment_amount'] * $returnPercentage * $validated['duration_days'];

            $existingPlan = CyclePlan::where('cycle_id', $cycle->id)->first();

            // Se a sequência já estiver em uso, reorganizar os ciclos
            if ($existingPlan) {
                // Aumentar a sequência de todos os planos iguais ou maiores
                CyclePlan::where('cycle_id', $cycle->id)
                    ->where('sequence', '>=', $validated['sequence'])
                    ->increment('sequence');
            }

            $totalInvestment = $cycle->plans()->sum('investment_amount');

            $plan = new CyclePlan($validated);
            $plan->cycle_id = $cycle->id;
            $plan->status = 'active';
            $plan->save();

            // Recalcular os totais do pacote
            $totalDuration = $cycle->plans()->sum('duration_days');
            $totalInvestment = $cycle->plans()->sum('investment_amount');
            $totalPercentage = $cycle->plans()->sum('return_percentage');

            $returnPercentage = $totalPercentage / 100;
            $return_amount = $totalInvestment * $returnPercentage * $totalDuration;


            $package = $cycle->package;

            $package->update([
                'total_duration' => $totalDuration,
                'total_investment' => $totalInvestment,
                'return_amount' => $return_amount
            ]);

            DB::commit();

            return redirect()->route('admin.packages.index', $package)
                ->with('success', 'Plano adicionado com sucesso!');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->withErrors(['error' => 'Erro ao salvar plano: ' . $e->getMessage() . ' Na linha ' . $e->getLine() . ', No arquivo: ' . $e->getFile()]);
        }
    }

    /**
     * Cadastro e edição novo plano de ciclo
     */
    public function planUpdate(Request $request, CyclePlan $plan)
    {

        // Validar o formulário
        $validated = $request->validate([
            'duration_days' => 'required|integer|min:1',
            'investment_amount' => 'nullable|string',
            'return_percentage' => 'required|integer|min:1',
            'sequence' => 'required|integer|min:1'
        ]);

        DB::beginTransaction();
        try {
            // Verificar se a sequência solicitada já está em uso por outro ciclo
            $returnPercentage = $validated['return_percentage'] / 100;

            $validated['return_amount'] = $validated['investment_amount'] * $returnPercentage * $validated['duration_days'];

            $existingPlan = CyclePlan::find($plan->id);

            // Se a sequência já estiver em uso, reorganizar os ciclos
            if ($existingPlan) {
                // Aumentar a sequência de todos os planos iguais ou maiores
                CyclePlan::where('cycle_id', $cycle->id)
                    ->where('sequence', '>=', $validated['sequence'])
                    ->increment('sequence');
            }

            // Recalcular os totais do pacote


            $plan->update($validated);

            $cycle = $plan->cycle;

            $package = $cycle->package;


            $totalDuration = $cycle->plans()->sum('duration_days');
            $totalInvestment = $cycle->plans()->sum('investment_amount');
            $totalPercentage = $cycle->plans()->sum('return_percentage');

            $returnPercentage = $totalPercentage / 100;
            $return_amount = $totalInvestment * $returnPercentage * $totalDuration;

            $package->update([
                'total_duration' => $totalDuration,
                'total_investment' => $totalInvestment,
                'return_amount' => $return_amount
            ]);

            DB::commit();

            return redirect()->route('admin.packages.index', $package)
                ->with('success', 'Plano atualizado com sucesso!');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->withErrors(['error' => 'Erro ao salvar plano: ' . $e->getMessage() . ' Na linha ' . $e->getLine()]);
        }
    }

    public function deletePlan(Request $request, CyclePlan $plan)
    {
        DB::beginTransaction();
        try {
            //code...
            $cycle = $plan->cycle;
            if ($plan->delete()) {
                // total_investment
                $total_investiment = $cycle->plans()->sum('investment_amount');
                // total_duration
                $total_duration = $cycle->plans()->sum('duration_days');

                $package = $cycle->package;

                $package->update([
                    'total_investment' => $total_investiment,
                    'total_duration' => $total_duration
                ]);

                DB::commit();
                return back()->with('success', 'Plano excluído com sucesso');
            } else {
                return back()->withInput()->withErrors(['error' => 'Falha ao excluir plano']);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->withErrors(['error' => 'Erro ao excluir plano: ' . $e->getMessage() . ' Na linha ' . $e->getLine()]);
        }
    }
}
