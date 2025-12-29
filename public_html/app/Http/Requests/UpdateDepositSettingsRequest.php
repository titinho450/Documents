<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDepositSettingsRequest extends FormRequest
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
            'minimum_deposit' => ['required', 'numeric', 'min:0'],
            'maximum_deposit' => ['required', 'numeric', 'min:0', 'gte:minimum_deposit'],
            'deposit_fee_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'deposit_bonus_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bonus_expiration_days' => ['nullable', 'integer', 'min:0'],
            'auto_approve_deposits' => ['boolean'],
            'deposit_confirmation_time' => ['nullable', 'integer', 'min:0'],
            'max_pending_time' => ['nullable', 'integer', 'min:0'],
            'max_deposits_per_day' => ['nullable', 'integer', 'min:0'],
            'require_kyc_for_deposit' => ['boolean'],
            'deposit_limiter' => ['boolean'],
            'deposit_days_allowed' => ['nullable', 'array'],
            'deposit_days_allowed.*' => ['string', 'in:sunday,monday,tuesday,wednesday,thursday,friday,saturday'],
            'enabled_gateways' => ['nullable', 'array'],
            'enabled_gateways.*' => ['string'],
            'deposit_terms_url' => ['nullable', 'url'],
            'deposit_alert_text' => ['nullable', 'string'],
            'deposit_support_link' => ['nullable', 'url'],
        ];
    }

    public function messages(): array
    {
        return [
            'paid_comissions_on_deposit.required' => 'Informe se as comissões sobre depósitos estão ativadas.',
            'paid_comissions_on_deposit.boolean' => 'O valor das comissões deve ser verdadeiro ou falso.',

            'registration_bonus.numeric' => 'O bônus de registro deve ser um valor numérico.',
            'registration_bonus.min' => 'O bônus de registro não pode ser negativo.',

            'total_member_register_reword_amount.numeric' => 'O valor da recompensa por indicação deve ser numérico.',
            'total_member_register_reword_amount.min' => 'O valor da recompensa não pode ser negativo.',

            'total_member_register_reword.integer' => 'A quantidade de membros para recompensa deve ser um número inteiro.',
            'total_member_register_reword.min' => 'O número de membros não pode ser negativo.',

            'minimum_deposit.required' => 'O valor mínimo de depósito é obrigatório.',
            'minimum_deposit.numeric' => 'O valor mínimo de depósito deve ser numérico.',
            'minimum_deposit.min' => 'O valor mínimo de depósito não pode ser negativo.',

            'maximum_deposit.required' => 'O valor máximo de depósito é obrigatório.',
            'maximum_deposit.numeric' => 'O valor máximo de depósito deve ser numérico.',
            'maximum_deposit.min' => 'O valor máximo de depósito não pode ser negativo.',
            'maximum_deposit.gte' => 'O valor máximo deve ser maior ou igual ao valor mínimo de depósito.',
        ];
    }
}
