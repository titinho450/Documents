<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsWithdrawnRequest;
use App\Models\Deposit;
use App\Models\Rebate;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

class SettingController extends Controller
{
    public $route = 'admin.setting';
    public function index()
    {
        $setting = Setting::first();
        return view('admin.pages.setting.index', compact('setting'));
    }

    /**
     * Busca o valor atual do dólar e o valor com IOF
     * @return array{
     *  dolar_value: float,
     *  dollar_with_iof: float
     * }
     * @throws \Exception
     */
    public function getDolarValue()
    {
        // Buscar o valor atual do dólar
        try {
            // Usando a API do Banco Central do Brasil (Awesomeapi)
            // Esta API é gratuita e não requer autenticação
            $response = Http::get('https://economia.awesomeapi.com.br/json/last/USD-BRL');
            $data = $response->json();

            if (!empty($data) && isset($data['USDBRL'])) {
                // Valor do dólar comercial
                $dollarValue = (float) $data['USDBRL']['bid'];

                // Calcular o valor com IOF (geralmente 6.38% para compras internacionais)
                $iofRate = 0.0638;
                $dollarWithIof = $dollarValue * (1 + $iofRate);

                return [
                    'dollar_value' => $dollarValue,
                    'dollar_with_iof' => $dollarWithIof,
                ];
            } else {
                // Caso a API não retorne dados esperados
                return [
                    'dollar_value' => $dollarValue,
                    'dollar_with_iof' => $dollarWithIof,
                ];
            }
        } catch (\Exception $e) {
            // Em caso de erro na requisição
            $filteredSettings->dollar_value = null;
            $filteredSettings->dollar_with_iof = null;
            \Log::error('Erro ao buscar valor do dólar: ' . $e->getMessage());
        }
    }

    public function updateWithdrawSettings(SettingsWithdrawnRequest $request)
    {

        // Pega o registro de configuração único (ajuste se for multi registros)
        $setting = Setting::first();

        if (!$setting) {
            $setting = new Setting();
        }

        $data = $request->validated();

        // Ajuste: o input do React manda "HH:mm", mas no banco precisa "HH:mm:ss"
        $data['withdraw_start_time'] = $data['withdraw_start_time']
            ? Carbon::createFromFormat('H:i', $data['withdraw_start_time'], 'America/Sao_Paulo')
            : null;

        $data['withdraw_end_time'] = $data['withdraw_end_time']
            ? Carbon::createFromFormat('H:i', $data['withdraw_end_time'], 'America/Sao_Paulo')
            : null;

        $setting->withdraw_charge = $data['withdraw_charge'];
        $setting->withdraw_start_time = $data['withdraw_start_time'];
        $setting->withdraw_end_time = $data['withdraw_end_time'];
        $setting->minimum_withdraw = $data['minimum_withdraw'];
        $setting->maximum_withdraw = $data['maximum_withdraw'];
        $setting->w_time_status = $data['w_time_status'];

        $setting->save();

        $setting->withdraw_start_time = $setting->withdraw_start_time
            ? Carbon::parse($setting->withdraw_start_time)->format('H:i')
            : null;
        $setting->withdraw_end_time = $setting->withdraw_end_time
            ? Carbon::parse($setting->withdraw_end_time)->format('H:i')
            : null;

        return response()->json([
            'status' => true,
            'message' => 'Configurações de saque atualizadas com sucesso',
            'data' => $setting,
        ]);
    }

    public function getSettings()
    {
        $settings = Setting::first();

        $filteredSettings = $settings->makeHidden([
            'created_at',
            'updated_at',
            'total_member_register_reword',
            'gateway_token',
            'token_expire_at',
            'total_member_register_reword_amount',
            'token_created_at',
            'registration_bonus',
            // adicione outros campos que deseja ocultar
        ]);

        // **Ajuste:** Formate os horários para "HH:mm" antes de enviar
        $filteredSettings->withdraw_start_time = $settings->withdraw_start_time
            ? Carbon::parse($settings->withdraw_start_time)->format('H:i')
            : null;

        $filteredSettings->withdraw_end_time = $settings->withdraw_end_time
            ? Carbon::parse($settings->withdraw_end_time)->format('H:i')
            : null;

        $rebate = Rebate::first();

        $filteredSettings->site_name = env('APP_NAME');
        $filteredSettings->comission_first_level = $rebate->interest_commission1;
        $filteredSettings->comission_second_level = $rebate->interest_commission2;
        $filteredSettings->comission_thirty_level = $rebate->interest_commission3;

        // Buscar o valor atual do dólar
        try {
            // Usando a API do Banco Central do Brasil (Awesomeapi)
            // Esta API é gratuita e não requer autenticação
            $response = Http::get('https://economia.awesomeapi.com.br/json/last/USD-BRL');
            $data = $response->json();

            if (!empty($data) && isset($data['USDBRL'])) {
                // Valor do dólar comercial
                $dollarValue = (float) $data['USDBRL']['bid'];

                // Calcular o valor com IOF (geralmente 6.38% para compras internacionais)
                $iofRate = 0.0638;
                $dollarWithIof = $dollarValue * (1 + $iofRate);

                // Adicionar ao objeto de resposta
                $filteredSettings->dollar_value = $dollarValue;
                $filteredSettings->dollar_with_iof = $dollarWithIof;
            } else {
                // Caso a API não retorne dados esperados
                $filteredSettings->dollar_value = null;
                $filteredSettings->dollar_with_iof = null;
            }
        } catch (\Exception $e) {
            // Em caso de erro na requisição
            $filteredSettings->dollar_value = null;
            $filteredSettings->dollar_with_iof = null;
            \Log::error('Erro ao buscar valor do dólar: ' . $e->getMessage());
        }

        // Obter todas as estatísticas de uma vez
        $statistics = Deposit::getDepositStatistics();

        // Ou obter individualmente
        $totalApproved = Deposit::getTotalApprovedDeposits();
        $totalLastMonth = Deposit::getTotalApprovedDepositsLastMonth();
        $percentageDifference = Deposit::getPercentageDifferenceLast30Days();

        $filteredSettings->statistics = $statistics;

        // return response()->json($filteredSettings, 200);

        return response()->json($filteredSettings, 200);
    }

    public function insert_or_update(Request $request)
    {
        $model = Setting::findOrFail(1);
        $model->withdraw_charge = $request->withdraw_charge;
        $model->minimum_withdraw = $request->minimum_withdraw;
        $model->maximum_withdraw = $request->maximum_withdraw;
        $model->minimum_deposit = $request->minimum_deposit;
        $model->maximum_deposit = $request->maximum_deposit;
        $model->w_time_status = $request->w_time_status;
        $model->checkin = $request->checkin;
        $model->registration_bonus = $request->registration_bonus;
        $model->total_member_register_reword_amount = $request->total_member_register_reword_amount;
        $model->total_member_register_reword = $request->total_member_register_reword;
        $model->withdraw_limiter = $request->has('withdraw_limiter') ? 1 : 0;
        $model->telegram_link = $request->telegram_link;
        $model->whatsapp_link = $request->whatsapp_link;

        // Upload do logo do site se um novo arquivo for enviado
        if ($request->hasFile('site_logo')) {
            // Validar se é uma imagem válida ANTES de processar
            $request->validate([
                'site_logo' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            ]);

            $file = $request->file('site_logo');

            // Verificar se o arquivo é válido
            if (!$file->isValid()) {
                return back()->with('error', 'Arquivo de logo inválido.');
            }

            try {
                // Gerar nome único para o arquivo
                $fileName = time() . '_' . $file->getClientOriginalName();

                // Salvar o arquivo
                $path = $file->storeAs('logos', $fileName, 'public');

                if (!$path) {
                    return back()->with('error', 'Falha ao salvar o logo.');
                }

                // Verificar se o arquivo foi realmente salvo
                $fullPath = storage_path('app/public/' . $path);
                if (!file_exists($fullPath)) {
                    return back()->with('error', 'Falha ao verificar o arquivo salvo.');
                }

                // Remover logo antigo se existir (após confirmar que o novo foi salvo)
                if ($model->site_logo && Storage::disk('public')->exists($model->site_logo)) {
                    Storage::disk('public')->delete($model->site_logo);
                }

                $model->site_logo = $path;
            } catch (\Exception $e) {
                \Log::error('Erro ao fazer upload do logo: ' . $e->getMessage());
                return back()->with('error', 'Erro ao processar o logo: ' . $e->getMessage());
            }
        }

        $model->update();
        return redirect()->route($this->route . '.index')->with('success', 'Settings Updated Successfully.');
    }
}
