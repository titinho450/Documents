<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWithdrawalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'           => ['required', 'string', 'max:255'],
            'cpf'            => ['required', 'string', 'min:11', 'max:11'],
            'pix_type'       => ['required', 'in:RANDOM,CPF,EMAIL'],
            'pix_key'        => ['required', 'string'],
            'method_name'    => ['nullable', 'string', 'max:255'],
            'oid'            => ['nullable', 'string', 'max:255'],
            'address'        => ['nullable', 'string', 'max:255'],
            'amount'         => ['required', 'numeric', 'min:0.01'],
            'charge'         => ['required', 'numeric', 'min:0'],
            'final_amount'   => ['required', 'numeric', 'min:0'],
            'ip'             => ['required', 'ip'],
            'status'         => ['required', 'in:pending,approved,rejected,process'],
        ];
    }
}
