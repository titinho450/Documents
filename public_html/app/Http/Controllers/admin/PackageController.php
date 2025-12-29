<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\Cycle;
use App\Models\CyclePlan;
use App\Models\Package;
use App\Models\Purchase;
use App\Models\User;
use App\Models\UserLedger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PackageController extends Controller
{
    public $route = 'admin.package';
    public function index()
    {
        $packages = Package::where('status', 'active')
            ->with(['cycles' => function ($query) {
                $query->orderBy('sequence');
            }])
            ->get();
        return view('admin.pages.package.index', compact('packages'));
    }

    public function list()
    {
        $packages = Package::where('status', 'active')
            ->with([
                'cycles' => function ($query) {
                    $query->orderBy('sequence');
                },
                'cycles.plans'
            ])
            ->get();
        return response()->json($packages, 200);
    }

    /**
     * Exibe detalhes de um pacote específico
     */
    public function show(Package $package)
    {
        $package->load([
            'cycles' => function ($query) {
                $query->orderBy('sequence');
            },
            'cycles.plans'
        ]);

        return view('packages.show', compact('package'));
    }

    /**
     * Formulário para criar um novo pacote (admin)
     */
    public function edit(Package $package)
    {
        $package->load(
            [
                'cycles' => function ($query) {
                    $query->orderBy('sequence');
                },
                'cycles.plans'
            ]
        );
        return view('admin.packages.edit', compact('package'));
    }

    /**
     * Formulário para criar um novo pacote (admin)
     */
    public function create()
    {
        return view('admin.packages.create');
    }

    /**
     * Atualiza um pacote existente (admin)
     */
    public function update(Request $request, Package $package)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'photo' => 'nullable|file|mimes:png,webp,jpg,jpeg',
            'featured' => 'boolean',
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'commission_percentage' => 'required|numeric|min:0|max:100',
        ]);

        DB::beginTransaction();
        try {
            // Processar upload da nova foto, se existir
            if ($request->hasFile('photo')) {
                // Remover foto anterior se existir
                if ($package->photo && Storage::disk('public')->exists($package->photo)) {
                    Storage::disk('public')->delete($package->photo);
                }

                $path = $request->file('photo')->store('packages', 'public');
                $validated['photo'] = $path; // Guarda o novo caminho no banco
            }

            // Atualizar o pacote
            $package->update($validated);

            // Processar os ciclos enviados com o formulário
            $cyclesData = $request->input('cycles', []);
            $totalDuration = 0;
            $totalInvestment = 0;

            // IDs dos ciclos enviados no request
            $updatedCycleIds = [];

            foreach ($cyclesData as $index => $cycleData) {
                // Verificar se os dados necessários estão presentes
                if (
                    !isset($cycleData['name']) ||
                    !isset($cycleData['investment_amount']) ||
                    !isset($cycleData['duration_days']) ||
                    !isset($cycleData['return_percentage'])
                ) {
                    continue; // Pula este ciclo se estiver faltando dados essenciais
                }
                $sequence = $index + 1;

                // Atualizar ciclo existente ou criar novo
                if (isset($cycleData['id'])) {
                    $cycle = Cycle::findOrFail($cycleData['id']);
                    $cycle->update([
                        'name' => $cycleData['name'],
                        'description' => $cycleData['description'] ?? null,
                        'sequence' => $sequence,
                        'investment_amount' => $cycleData['investment_amount'],
                        'duration_days' => $cycleData['duration_days'],
                        'commission_percentage' => $cycleData['commission_percentage'] ?? $validated['commission_percentage'],
                        'return_percentage' => $cycleData['return_percentage'],
                        'requirements' => $cycleData['requirements'] ?? [],
                        'status' => $cycleData['status'] ?? 'active',
                    ]);

                    $updatedCycleIds[] = $cycle->id;
                } else {
                    $cycle = new Cycle([
                        'name' => $cycleData['name'],
                        'description' => $cycleData['description'] ?? null,
                        'sequence' => $sequence,
                        'investment_amount' => $cycleData['investment_amount'],
                        'duration_days' => $cycleData['duration_days'],
                        'commission_percentage' => $cycleData['commission_percentage'] ?? $validated['commission_percentage'],
                        'return_percentage' => $cycleData['return_percentage'],
                        'requirements' => $cycleData['requirements'] ?? [],
                        'status' => 'active',
                    ]);

                    $package->cycles()->save($cycle);
                    $updatedCycleIds[] = $cycle->id;
                }

                // Processar planos para este ciclo 
                // Verifica se existem planos, ou usa um array vazio para evitar erros
                $plans = isset($cycleData['plans']) && is_array($cycleData['plans']) ? $cycleData['plans'] : [];
                // IDs dos planos enviados no request para este ciclo
                $updatedPlanIds = [];

                foreach ($cycleData['plans'] as $planIndex => $planData) {
                    // Verificar se os dados necessários estão presentes
                    if (
                        !isset($planData['investment_amount']) ||
                        !isset($planData['return_percentage']) ||
                        !isset($planData['duration_days'])
                    ) {
                        continue; // Pula este plano se estiver faltando dados essenciais
                    }

                    $planSequence = $planIndex + 1;

                    // Calcular valor de retorno
                    $returnAmount = $planData['investment_amount'] * ($planData['return_percentage'] / 100) * $planData['duration_days'];

                    $totalDuration += $planData['duration_days'];
                    $totalInvestment += $planData['investment_amount'];

                    // Atualizar plano existente ou criar novo
                    if (isset($planData['id'])) {
                        $plan = CyclePlan::findOrFail($planData['id']);
                        $plan->update([
                            'investment_amount' => $planData['investment_amount'],
                            'duration_days' => $planData['duration_days'],
                            'return_percentage' => $planData['return_percentage'],
                            'return_amount' => $returnAmount,
                            'status' => $planData['status'] ?? 'active',
                            'sequence' => $planSequence,
                        ]);

                        $updatedPlanIds[] = $plan->id;
                    } else {
                        $plan = new CyclePlan([
                            'investment_amount' => $planData['investment_amount'],
                            'duration_days' => $planData['duration_days'],
                            'return_percentage' => $planData['return_percentage'],
                            'return_amount' => $returnAmount,
                            'status' => 'active',
                            'sequence' => $planSequence,
                        ]);

                        $cycle->plans()->save($plan);
                        $updatedPlanIds[] = $plan->id;
                    }
                }

                // Remover planos que não foram enviados no request (excluídos pelo usuário)
                if (!empty($updatedPlanIds)) {
                    $cycle->plans()->whereNotIn('id', $updatedPlanIds)->delete();
                } else if (empty($plans)) {
                    // Se nenhum plano foi enviado, remover todos os planos existentes deste ciclo
                    $cycle->plans()->delete();
                }
            }

            // Remover ciclos que não foram enviados no request (excluídos pelo usuário)
            if (!empty($updatedCycleIds)) {
                $package->cycles()->whereNotIn('id', $updatedCycleIds)->delete();
            }

            // Atualizar totais no pacote
            $package->update([
                'total_duration' => $totalDuration,
                'total_investment' => $totalInvestment,
            ]);

            DB::commit();

            return response()->json(
                [
                    'success' => true,
                    'data' => $package->fresh()->load('cycles.plans'),
                    'message' => 'Pacote atualizado com sucesso'
                ],
                200
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Erro ao atualizar pacote: ' . $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
                500
            );
        }
    }




    /**
     * Armazena um novo pacote (admin)
     */
    public function store(Request $request)
    {
        $request->merge([
            'featured' => $request->has('featured'),
            'status' => $request->get('status', 'inactive'),
        ]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'photo' => 'nullable|image|mimes:png,webp,jpg,jpeg|max:2048',
            'featured' => 'boolean',
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'commission_percentage' => 'required|numeric|min:0|max:100',
        ]);

        DB::beginTransaction();
        try {
            // Processar upload da foto, se existir
            if ($request->hasFile('photo')) {
                $path = $request->file('photo')->store('packages', 'public');
                $validated['photo'] = $path; // Guarda o caminho no banco
            }

            // Criar o pacote
            Package::create($validated);

            DB::commit();

            return back()->with('success', 'Pacote criado com sucesso');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao criar pacote: ' . $e->getMessage());
            return back()->with('error', 'Erro ao criar pacote: ' . $e->getMessage());
        }
    }


    public function insert_or_update(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'name' => 'required',
            'title' => 'required',
            'price' => 'required|numeric',
            'validity' => 'required|numeric',
            'commission_with_avg_amount' => 'required|numeric',
        ]);

        if ($validate->fails()) {
            Log::error('[TYPE]:UPDATE PACKAGE -> ' . $validate->errors());
            return response()->json([
                'success' => false,
                'message' => $validate->errors()
            ], 400);
        }

        if ($request->id) {
            $model = Package::findOrFail($request->id);
            $model->status = $request->status;
        } else {
            $model = new Package();
        }

        $path = uploadImage(false, $request, 'photo', 'upload/package/', 200, 200, $model->photo);

        $model->photo = $path ?? $model->photo;
        $model->name = $request->name;
        $model->title = $request->title;
        $model->price = $request->price;
        $model->validity = $request->validity;
        $model->commission_with_avg_amount = $request->commission_with_avg_amount;
        $model->save();
        return redirect()->route($this->route . '.index')->with('success', $request->id ? 'Package Updated Successful.' : 'Package Created Successful.');
    }

    public function delete($id)
    {
        $model = Package::find($id);
        deleteImage($model->photo);
        $model->delete();
        return redirect()->route($this->route . '.index')->with('success', 'Item Deleted Successful.');
    }

    public function comissions()
    {
        // Definindo timezone de São Paulo
        date_default_timezone_set('America/Sao_Paulo');

        $now = Carbon::now();

        // Verifica se é 20:00 horas
        if ($now->hour != 20) {
            return; // Sai da função se não for 20:00
        }

        $logDate = Carbon::now()->format('Y-m-d');
        $logFileName = "commission-updates-{$logDate}.log";

        Log::build([
            'driver' => 'single',
            'path' => storage_path("logs/{$logFileName}"),
        ])->info("Iniciando atualização de comissões em " . now());

        $today = Carbon::now()->startOfDay();

        // Verifica se é fim de semana (sábado = 6, domingo = 0)
        if ($today->dayOfWeek === 0 || $today->dayOfWeek === 6) {
            Log::build([
                'driver' => 'single',
                'path' => storage_path("logs/{$logFileName}"),
            ])->info("Hoje é fim de semana. Pagamentos não serão processados.");
            return;
        }

        // Busca todos os purchases ativos que devem ser pagos hoje
        $purchases = Purchase::where('status', 'active')
            ->whereDate('date', $today)
            ->get();

        foreach ($purchases as $purchase) {
            $user = User::find($purchase->user_id);
            if (!$user) continue;

            $package = Package::find($purchase->package_id);
            if (!$package) continue;

            DB::beginTransaction();
            try {
                // Atualiza o saldo do usuário
                $user->blocked_balance += $purchase->daily_income;
                $user->save();

                // Atualiza a data do próximo pagamento
                // Se hoje for sexta-feira, adiciona 3 dias para pular o fim de semana
                if ($today->dayOfWeek === 5) {
                    $purchase->date = now()->addDays(3)->startOfDay();
                } else {
                    $purchase->date = now()->addDay()->startOfDay();
                }

                $purchase->save();

                $purchaseCycles = $purchase->user->purchaseCycles;

                // Desbloquear saldo
                if (isset($purchaseCycles)) {
                    foreach ($purchaseCycles as $purchaseCycle) {
                        $checkExpire = Carbon::parse($purchaseCycle->completed_date);

                        if ($checkExpire->isPast()) {
                            $user->profit_balance += $purchaseCycle->return_amount;
                            $user->blocked_balance -= $purchaseCycle->return_amount;
                            $user->save();

                            $purchaseCycle->status = 'completed';
                            $user->save();
                        }
                    }
                }

                // Registra o ledger
                $ledger = new UserLedger();
                $ledger->user_id = $user->id;
                $ledger->reason = 'daily_income';
                $ledger->perticulation = $package->name . ' Commission Added';
                $ledger->amount = $purchase->daily_income;
                $ledger->credit = $purchase->daily_income;
                $ledger->status = 'approved';
                $ledger->date = now();
                $ledger->save();

                // Verifica validade
                $checkExpire = Carbon::parse($purchase->validity);
                if ($checkExpire->isPast()) {
                    $purchase->status = 'inactive';
                    $purchase->save();
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $this->logMessage("Erro ao processar purchase {$purchase->id}: {$e->getMessage()}", 'error', $logFileName);
                continue;
            }
        }
    }

    private function logMessage(string $message, string $level, string $logFileName)
    {
        // Log no arquivo específico
        \Log::build([
            'driver' => 'single',
            'path' => storage_path("logs/{$logFileName}"),
        ])->$level($message);

        // Log no console
        if ($level === 'error') {
            $this->error($message);
        } else {
            $this->info($message);
        }

        // Log padrão do Laravel
        \Log::$level($message);
    }
}
