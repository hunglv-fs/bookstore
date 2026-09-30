<?php

namespace App\Http\Requests\Order;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Only one of the customer's own addresses (a foreign id looks like a missing one).
            'address_id' => [
                'required', 'integer', 'min:1',
                Rule::exists('customer_addresses', 'id')->where('customer_id', $this->user()?->customer?->id),
            ],
            'note' => ['nullable', 'string', 'max:1000'],
            // Defaults to COD, the only method available in the MVP.
            'payment_method' => ['nullable', 'string', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::available()))],
        ];
    }
}
