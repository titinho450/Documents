<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DepositUpdateRequest extends FormRequest
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
            'user_id'           => ['required', 'numeric'],
            'method_name'       => ['required', 'string', 'max:255'],
            'address'           => ['required', 'string', 'max:255'],
            'transaction_id'    => ['required', 'string', 'max:255'],
            'order_id'          => ['required', 'string', 'max:255'],
            'amount'            => ['required', 'numeric'],
            'date'              => ['nullable', 'string', 'max:255'],
            'status'            => ['required', 'string', 'in:pending,rejected,approved']
        ];
    }
}
