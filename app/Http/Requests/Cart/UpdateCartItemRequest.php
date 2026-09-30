<?php

namespace App\Http\Requests\Cart;

use App\Services\CartService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Quantity must stay positive; removing a line is DELETE /api/cart/items/{id}.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CartService::MAX_QUANTITY_PER_ITEM],
        ];
    }
}
