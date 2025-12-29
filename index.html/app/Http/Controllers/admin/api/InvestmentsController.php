<?php

namespace App\Http\Controllers\admin\api;

use App\Enums\TransactionTypes;
use App\Http\Controllers\Controller;
use App\Http\Requests\InvestmentPackageStoreRequest;
use App\Models\Purchase;
use App\Models\UserLedger;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class InvestmentsController extends Controller
{
    /**
     * List all investments.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(): JsonResponse
    {

        $investments = Purchase::with(['package', 'user'])
            ->orderBy('created_at', 'desc')
            ->paginate(30);

        $purchasesCount = Purchase::count();
        $purchasesValueAmount = Purchase::sum('amount');

        $purchasesPaidAmount = UserLedger::sum('amount');

        $statistics = [
            'investiments_count' => $purchasesCount,
            'investiments_amount' => $purchasesValueAmount,
            'investiments_paid' => $purchasesPaidAmount,
        ];
        return response()->json([
            'success' => true,
            'message' => 'Investment index method called',
            'data' => [
                'investments' => $investments,
                'statistics' => $statistics
            ]
        ]);
    }

    /**
     * Buscar purchases por valor, id, transaction_id, user.name, user.phone, user.email, user.ref_id, user.withdrawAccount.cpf, user.withdrawAccount.pix_key e CPF da conta de saque
     */
    public function searchPurchases(Request $request): JsonResponse
    {
        $query = $request->input('query'); // termo de busca

        $purchases = Purchase::with(['user', 'package', 'user.withdrawAccount'])
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
            // Busca nos campos da relação package
            ->orWhereHas('package', function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('title', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%")
                    ->orWhere('total_investment', 'like', "%{$query}%");
            })
            ->paginate(10);


        return response()->json([
            'success' => true,
            'investments' => $purchases
        ], 200);
    }

    public function store(InvestmentPackageStoreRequest $request): JsonResponse
    {
        // Create a new investment package
        $data = $request->validated();

        // save the image if it exists
        if ($request->hasFile('image')) {
            try {
                $image = $request->file('image');
                $imageName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $image->getClientOriginalName());

                // Salva no disco 'public' dentro de 'investment_packages' com o nome definido
                $path = $image->storeAs('investment_packages', $imageName, 'public');

                if (!$path || !Storage::disk('public')->exists($path)) {
                    throw new \Exception('Falha ao salvar o arquivo de imagem.');
                }

                $data['image'] = $path;
            } catch (Exception $e) {
                Log::error('[INVESTMENT PACKAGE UPLOAD ERROR] ' . $e->getMessage());

                return response()->json([
                    'success' => false,
                    'message' => 'Erro ao fazer upload da imagem: ' . $e->getMessage(),
                ], 500);
            }
        }

        try {
            $investmentPackage = InvestmentPackage::create($data);
            // Logic to store a new investment
            return response()->json([
                'success' => true,
                'message' => 'Investment stored successfully',
                'data' => $investmentPackage
            ], 201);
        } catch (Exception $e) {
            Log::error('[INVESTMENT PACKAGE STORE ERROR] ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar o pacote de investimento: ' . $e->getMessage(),
            ], 500);
        }
    }
}
