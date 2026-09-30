<?php

namespace App\Http\Requests\Cart;

use App\Enums\BookStatus;
use App\Services\CartService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCartItemRequest extends FormRequest
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
            'book_id' => [
                'required', 'integer', 'min:1',
                Rule::exists('books', 'id')->where('status', BookStatus::Active->value),
            ],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CartService::MAX_QUANTITY_PER_ITEM],
        ];
    }

    public function messages(): array
    {
        return [
            'book_id.exists' => 'The selected book does not exist or is not available for sale.',
        ];
    }
}
