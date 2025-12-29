<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvestmentPackageStoreRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'symbol' => 'required|string|max:10|unique:investment_packages,symbol',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'min_return_rate' => 'required|numeric|min:0',
            'max_return_rate' => 'required|numeric|min:0|gte:min_return_rate',
            'minimum_amount' => 'required|numeric|min:0',
            'duration_days' => 'required|integer|min:1',
            'min_withdrawal_days' => 'required|integer|min:1',
            'is_active' => 'required|boolean',
        ];
    }
}
