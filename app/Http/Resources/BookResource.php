<?php

namespace App\Http\Resources;

use App\Models\Book;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Book
 */
class BookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'isbn' => $this->isbn,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'published_at' => $this->published_at?->toDateString(),
            'status' => $this->status->value,
            // when() rather than whenLoaded(): a book without an inventory row must show 0, not null.
            'available_quantity' => $this->when($this->relationLoaded('inventory'), fn () => $this->availableQuantity()),
            'in_stock' => $this->when($this->relationLoaded('inventory'), fn () => $this->availableQuantity() > 0),
            'publisher' => new PublisherResource($this->whenLoaded('publisher')),
            'authors' => AuthorResource::collection($this->whenLoaded('authors')),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'thumbnail' => new BookImageResource($this->whenLoaded('thumbnail')),
            'images' => BookImageResource::collection($this->whenLoaded('images')),
        ];
    }
}
