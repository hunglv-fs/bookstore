<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;

class OrderStatusController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function update(UpdateOrderStatusRequest $request, Order $order): OrderResource
    {
        $order = $this->orders->changeStatus(
            $order,
            OrderStatus::from($request->validated('status')),
            $request->user(),
            $request->validated('note'),
        );

        return new OrderResource($order->load(['items.book', 'address', 'statusHistories', 'payments']));
    }
}
