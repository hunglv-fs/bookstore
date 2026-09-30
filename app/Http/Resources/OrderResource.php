<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_no' => $this->order_no,
            'status' => $this->status->value,
            'payment_status' => $this->payment_status->value,
            'can_cancel' => $this->status->isCancellable(),
            'subtotal' => $this->subtotal,
            'shipping_fee' => $this->shipping_fee,
            'total_amount' => $this->total_amount,
            'note' => $this->note,
            'ordered_at' => $this->ordered_at->toIso8601String(),
            'address' => new AddressResource($this->whenLoaded('address')),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'status_histories' => OrderStatusHistoryResource::collection($this->whenLoaded('statusHistories')),
        ];
    }
}
