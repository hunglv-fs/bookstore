<?php

namespace App\Http\Resources;

use App\Models\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerAddress
 */
class AddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receiver_name' => $this->receiver_name,
            'phone' => $this->phone,
            'address' => $this->address,
            'ward' => $this->ward,
            'district' => $this->district,
            'city' => $this->city,
            'is_default' => $this->is_default,
        ];
    }
}
