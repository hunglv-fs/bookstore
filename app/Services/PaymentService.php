<?php

namespace App\Services;

use App\Enums\OrderPaymentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;

/**
 * Payment records of an order. Every method here is called by CheckoutService
 * or OrderService inside their transaction, while the order (or the cart it is
 * created from) is locked, so payment changes of one order are serialized.
 *
 * Only cash on delivery exists in the MVP: the payment is created as pending
 * at checkout, becomes paid when the order is delivered (cash collected) and
 * failed when the order is cancelled (it will never be collected). A gateway
 * method would add an "authorize/capture" step here without changing callers.
 */
class PaymentService
{
    public function createForOrder(Order $order, PaymentMethod $method): Payment
    {
        return $order->payments()->create([
            'method' => $method,
            'amount' => $order->total_amount,
            'status' => PaymentStatus::Pending,
        ]);
    }

    /**
     * COD collected on delivery.
     */
    public function markPaid(Order $order): void
    {
        $this->livePayment($order)?->update(['status' => PaymentStatus::Paid, 'paid_at' => now()]);
        $order->update(['payment_status' => OrderPaymentStatus::Paid]);
    }

    /**
     * Order cancelled before the money was collected.
     */
    public function markFailed(Order $order): void
    {
        $this->livePayment($order, PaymentStatus::Pending)?->update(['status' => PaymentStatus::Failed]);
    }

    private function livePayment(Order $order, PaymentStatus ...$statuses): ?Payment
    {
        return $order->payments()
            ->whereIn('status', array_map(fn (PaymentStatus $s) => $s->value, $statuses ?: [PaymentStatus::Pending]))
            ->first();
    }
}
