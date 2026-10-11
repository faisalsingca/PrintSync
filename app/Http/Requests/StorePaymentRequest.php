<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'order_id' => ['required', 'exists:orders,order_id'],
            'cashier_id' => ['prohibited'],
            'payment_method' => ['required', 'string', 'in:cash,gcash,bank_transfer'],
            'received' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'payment_date' => ['required', 'date'],
        ];
    }
}
