<?php

namespace App\Http\Controllers\admin\api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PackageController extends Controller
{
    public function list()
    {
        $packages = Package::all();

        return response()->json([
            'success' => true,
            'message' => 'Pacotes listados com sucesso!',
            'packages' => $packages
        ]);
    }

    public function update(Package $package, Request $request): JsonResponse
    {
        // Validação dos dados
        $validator = $this->validatePackageData($request->all());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        $validatedData = $validator->validated();

        // Processamento de dados adicionais
        $packageData = $this->preparePackageData($validatedData);

        $updatedPackage = DB::transaction(function () use ($package, $packageData) {
            $package->update($packageData);
            return $package;
        });

        Log::info('Status do pacote alterado com sucesso', [
            'package_id' => $package->id,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pacote atualizado com sucesso!',
            'data' => $updatedPackage
        ]);
    }

    public function updateStatus(Package $package, Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:draft,active,inactive', // ou os status válidos no seu app
        ]);

        $package->status = $request->status;
        $package->save();

        Log::info('Status do pacote alterado com sucesso', [
            'package_id' => $package->id,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Status do pacote alterado com sucesso!',
            'data' => $package
        ]);
    }

    public function view(Package $package): JsonResponse
    {

        return response()->json([
            'success' => true,
            'data' => $package
        ]);
    }
    public function delete(Package $package): JsonResponse
    {

        $package->delete();

        Log::info('Pacote excluido com sucesso', [
            'package_id' => $package->id,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pacote excluido!'
        ]);
    }
    public function toogleFeatured(Package $package): JsonResponse
    {
        $package->featured = !$package->featured;
        $package->save();

        Log::info('Pacote removido dos destaques com sucesso', [
            'package_id' => $package->id,
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pacote removido dos destaques!',
            'data' => $package
        ]);
    }

    /**
     * Cria um novo pacote
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Validação dos dados
            $validator = $this->validatePackageData($request->all());

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $validatedData = $validator->validated();

            // Processamento de dados adicionais
            $packageData = $this->preparePackageData($validatedData);

            // Criar o pacote usando transação
            $package = DB::transaction(function () use ($packageData) {
                return Package::create($packageData);
            });

            // Log da criação
            Log::info('Pacote criado com sucesso', [
                'package_id' => $package->id,
                'name' => $package->name,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pacote criado com sucesso',
                'data' => $this->formatPackageResponse($package)
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro de validação',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao criar pacote', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor',
                'error' => app()->environment('local') ? $e->getMessage() : 'Erro interno'
            ], 500);
        }
    }

    /**
     * Valida os dados do pacote
     *
     * @param array $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    private function validatePackageData(array $data): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, [
            'name' => [
                'required',
                'string',
                'min:1',
                'max:255'
            ],
            'title' => [
                'required',
                'string',
                'min:1',
                'max:255'
            ],
            'description' => [
                'required',
                'string',
                'min:10',
                'max:5000'
            ],
            'photo' => [
                'nullable',
                'string',
                'max:5555000'
            ],
            'featured' => [
                'boolean'
            ],
            'status' => [
                'required',
                'string',
                'in:active,inactive,draft'
            ],
            'total_duration' => [
                'required',
                'numeric',
                'min:1',
                'max:9999'
            ],
            'frequency_unit' => [
                'required',
                'string',
                'in:hour,day,week,month'
            ],
            'commission_percentage' => [
                'required',
                'numeric',
                'min:0',
                'max:100'
            ],
            'total_investment' => [
                'required',
                'numeric',
                'min:0'
            ],
            'return_amount' => [
                'required',
                'numeric',
                'min:0'
            ]
        ], [
            // Mensagens personalizadas
            'name.required' => 'O nome é obrigatório',
            'title.required' => 'O título é obrigatório',
            'description.required' => 'A descrição é obrigatória',
            'description.min' => 'A descrição deve ter pelo menos 10 caracteres',
            'photo.url' => 'A URL da foto deve ser válida',
            'status.in' => 'Status deve ser: active, inactive ou draft',
            'total_duration.required' => 'A duração total é obrigatória',
            'total_duration.min' => 'A duração deve ser maior que 0',
            'frequency_unit.in' => 'Unidade de frequência deve ser: hour, day, week ou month',
            'commission_percentage.required' => 'A porcentagem de comissão é obrigatória',
            'commission_percentage.max' => 'A comissão deve estar entre 0 e 100%',
            'total_investment.required' => 'O investimento total é obrigatório',
            'total_investment.min' => 'O investimento deve ser maior que 0',
            'return_amount.required' => 'O valor de retorno é obrigatório',
            'return_amount.min' => 'O retorno deve ser maior que 0'
        ]);
    }

    /**
     * Prepara os dados do pacote para criação
     *
     * @param array $validatedData
     * @return array
     */
    private function preparePackageData(array $validatedData): array
    {
        $imagePath = null;

        if (!empty($validatedData['photo']) && str_starts_with($validatedData['photo'], 'data:image')) {
            $imagePath = $this->saveBase64Image($validatedData['photo']);
        }

        $returnData = [
            'name' => $validatedData['name'],
            'title' => $validatedData['title'],
            'description' => $validatedData['description'],
            'featured' => $validatedData['featured'] ?? false,
            'status' => $validatedData['status'] ?? 'draft',
            'total_duration' => $validatedData['total_duration'],
            'frequency_unit' => $validatedData['frequency_unit'] ?? 'month',
            'commission_percentage' => $validatedData['commission_percentage'],
            'total_investment' => $validatedData['total_investment'],
            'return_amount' => $validatedData['return_amount'],
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now()
        ];

        if ($imagePath) {
            $returnData['photo'] = $imagePath;
        }
        return $returnData;
    }

    /**
     * Salva uma imagem base64 no diretório público e retorna o caminho salvo
     *
     * @param string $base64Image
     * @return string|null
     */
    private function saveBase64Image(string $base64Image): ?string
    {
        try {
            preg_match('/data:image\/(\w+);base64,/', $base64Image, $matches);

            $extension = $matches[1] ?? 'png';
            $image = preg_replace('/^data:image\/\w+;base64,/', '', $base64Image);
            $image = str_replace(' ', '+', $image);
            $imageData = base64_decode($image);

            if ($imageData === false) {
                throw new \Exception("Falha ao decodificar imagem base64.");
            }

            $fileName = uniqid('package_') . '.' . $extension;
            $filePath = 'uploads/packages/' . $fileName;

            // Salvar a imagem no diretório public
            \Storage::disk('public')->put($filePath, $imageData);

            return 'storage/' . $filePath; // Caminho acessível publicamente
        } catch (\Exception $e) {
            \Log::error("Erro ao salvar imagem base64: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Formata a resposta do pacote
     *
     * @param Package $package
     * @return array
     */
    private function formatPackageResponse(Package $package): array
    {
        return [
            'id' => $package->id,
            'name' => $package->name,
            'title' => $package->title,
            'description' => $package->description,
            'photo' => $package->photo,
            'featured' => $package->featured,
            'status' => $package->status,
            'total_duration' => $package->total_duration,
            'frequency_unit' => $package->frequency_unit,
            'commission_percentage' => $package->commission_percentage,
            'total_investment' => $package->total_investment,
            'return_amount' => $package->return_amount,
            'created_at' => $package->created_at,
            'updated_at' => $package->updated_at
        ];
    }

    /**
     * Valida se o usuário tem permissão para criar pacotes
     *
     * @return bool
     */
    private function canCreatePackage(): bool
    {
        // Implemente sua lógica de autorização aqui
        // Por exemplo, verificar se o usuário tem o papel necessário
        return auth()->check();
    }
}
