<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SettingsWithdrawnRequest extends FormRequest
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
            'withdraw_charge' => ['required', 'numeric'],          // float
            'withdraw_start_time' => ['nullable', 'date_format:H:i'], // pode ser "14:00"
            'withdraw_end_time' => ['nullable', 'date_format:H:i'],
            'minimum_withdraw' => ['required', 'numeric'],
            'maximum_withdraw' => ['required', 'numeric'],
            'w_time_status' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'withdraw_charge.required' => 'A taxa de saque é obrigatória.',
            'withdraw_charge.numeric' => 'A taxa de saque deve ser um número válido.',

            'withdraw_start_time.date_format' => 'O horário de início deve estar no formato HH:mm.',

            'withdraw_end_time.date_format' => 'O horário de fim deve estar no formato HH:mm.',

            'minimum_withdraw.required' => 'O valor mínimo é obrigatório.',
            'minimum_withdraw.numeric' => 'O valor mínimo deve ser um número válido.',

            'maximum_withdraw.required' => 'O valor máximo é obrigatório.',
            'maximum_withdraw.numeric' => 'O valor máximo deve ser um número válido.',

            'w_time_status.required' => 'O status do horário de saque é obrigatório.',
            'w_time_status.boolean' => 'O status do horário de saque deve ser active ou inactive.',
        ];
    }
}
