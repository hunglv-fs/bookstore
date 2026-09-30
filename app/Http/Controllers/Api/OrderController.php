<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CancelOrderRequest;
use App\Http\Requests\Order\CheckoutRequest;
use App\Http\Requests\Order\ListOrdersRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly OrderService $orders,
    ) {}

    public function index(ListOrdersRequest $request): AnonymousResourceCollection
    {
        $orders = $request->user()->customerOrFail()->orders()
            ->with(['items.book', 'address'])
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->paginate((int) ($request->validated('per_page') ?? 15))
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    public function store(CheckoutRequest $request): JsonResponse
    {
        $order = $this->checkout->checkout(
            $request->user()->customerOrFail(),
            (int) $request->validated('address_id'),
            $request->validated('note'),
            $request->user(),
            PaymentMethod::from($request->validated('payment_method') ?? PaymentMethod::Cod->value),
        );

        return (new OrderResource($this->withDetails($order)))->response()->setStatusCode(201);
    }

    public function show(Order $order): OrderResource
    {
        return new OrderResource($this->withDetails($order));
    }

    public function cancel(CancelOrderRequest $request, Order $order): OrderResource
    {
        $order = $this->orders->cancel($order, $request->user(), $request->validated('note'));

        return new OrderResource($this->withDetails($order));
    }

    public function payments(Order $order): AnonymousResourceCollection
    {
        return PaymentResource::collection($order->payments()->get());
    }

    private function withDetails(Order $order): Order
    {
        return $order->load(['items.book', 'address', 'statusHistories', 'payments']);
    }
}
