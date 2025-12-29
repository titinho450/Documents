<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\BinaryTrade;
use Illuminate\Foundation\Http\FormRequest;

class SettleBinaryTradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'result' => ['required', 'string', 'in:won,lost,draw'],
        ];
    }
}
