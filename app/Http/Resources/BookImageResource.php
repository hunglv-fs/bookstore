<?php

namespace App\Http\Resources;

use App\Models\BookImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BookImage
 */
class BookImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'image_url' => $this->image_url,
            'is_thumbnail' => $this->is_thumbnail,
            'sort_order' => $this->sort_order,
        ];
    }
}
