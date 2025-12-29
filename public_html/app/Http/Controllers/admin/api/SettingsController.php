<?php

namespace App\Http\Controllers\admin\api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateGeneralSettingsRequest;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    public function update(UpdateGeneralSettingsRequest $request)
    {
        $settings = Setting::first(); // assumindo 1 linha única

        if (!$settings) {
            $settings = new Setting();
        }

        $data = $request->validated();

        // Upload do logo
        if ($request->hasFile('site_logo')) {
            if ($settings->site_logo && Storage::disk('public')->exists($settings->site_logo)) {
                Storage::disk('public')->delete($settings->site_logo);
            }

            $file = $request->file('site_logo');
            $path = $file->store('logos', 'public');
            $data['site_logo'] = $path;
        }

        // Serializar arrays para json
        if (isset($data['enabled_gateways'])) {
            $data['enabled_gateways'] = json_encode($data['enabled_gateways']);
        }

        if (isset($data['deposit_days_allowed'])) {
            $data['deposit_days_allowed'] = json_encode($data['deposit_days_allowed']);
        }

        $settings->fill($data)->save();

        return response()->json([
            'success' => true,
            'message' => 'Configurações atualizadas com sucesso',
            'data' => $settings,
        ]);
    }
}
