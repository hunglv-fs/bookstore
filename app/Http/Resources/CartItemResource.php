<?php

namespace App\Http\Resources;

use App\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CartItem
 */
class CartItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => $this->quantity,
            // Current price; the price actually charged is fixed at checkout.
            'unit_price' => $this->book->price,
            'line_total' => $this->lineTotal(),
            // False when the book was deactivated after being added; checkout will reject it.
            'is_available' => $this->book->isActive(),
            'book' => [
                'id' => $this->book->id,
                'isbn' => $this->book->isbn,
                'title' => $this->book->title,
                'thumbnail' => new BookImageResource($this->book->thumbnail),
            ],
        ];
    }
}
