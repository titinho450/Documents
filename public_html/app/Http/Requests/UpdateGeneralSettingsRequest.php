<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGeneralSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'telegram_link' => 'nullable|url',
            'whatsapp_link' => 'nullable|url',
            'bonus_expiration_days' => 'nullable|integer|min:0',
            'registration_bonus' => 'nullable|numeric|min:0',
            'total_member_register_reword' => 'nullable|integer|min:0',
            'total_member_register_reword_amount' => 'nullable|numeric|min:0',
            'enabled_gateways' => 'nullable|array',
            'enabled_gateways.*' => 'string',
            'deposit_days_allowed' => 'nullable|array',
            'deposit_days_allowed.*' => 'string',
        ];
    }
}
