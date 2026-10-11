<?php

namespace App\Http\Requests;

use App\OrderServiceLine;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
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
            'customer_id' => ['prohibited'],
            'order_date' => ['prohibited'],
            'status' => ['prohibited'],
            'order_type' => ['required', 'string', 'max:50'],
            'service_line' => ['required', Rule::enum(OrderServiceLine::class)],
            'description' => ['nullable', 'string'],
            'design_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
