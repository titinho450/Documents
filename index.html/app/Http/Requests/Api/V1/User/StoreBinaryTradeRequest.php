<?php

namespace App\Http\Requests\Api\V1\User;

use Illuminate\Foundation\Http\FormRequest;

class StoreBinaryTradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->balance >= $this->input('amount_cents');
    }

    public function rules(): array
    {
        return [
            'amount_cents' => ['required', 'integer', 'min:100'], // Mínimo R$ 1,00
            'direction' => ['required', 'string', 'in:up,down'],
        ];
    }
}
