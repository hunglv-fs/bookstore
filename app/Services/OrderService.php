<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PaymentService $payments,
    ) {}

    public function cancel(Order $order, User $actor, ?string $note = null): Order
    {
        return $this->changeStatus($order, OrderStatus::Cancelled, $actor, $note);
    }

    /**
     * Move an order to its next status, applying the side effects:
     * cancelled -> release the reservation, pending COD payment fails;
     * delivered -> reservation becomes a sale, COD payment is collected (paid).
     *
     * The order row is locked first (SELECT ... FOR UPDATE) and the transition
     * is validated on the locked row, so repeated or concurrent calls (double
     * click, retried request) are serialized: the second one sees the new
     * status, the transition is no longer allowed, and stock is not touched
     * again. InventoryService's ledger check and unique index back this up.
     *
     * @throws ValidationException when the transition is not allowed
     */
    public function changeStatus(Order $order, OrderStatus $to, ?User $actor, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $to, $actor, $note) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages(['status' => [$this->rejectionMessage($from, $to)]]);
            }

            if ($to === OrderStatus::Cancelled) {
                $this->inventory->release(Order::INVENTORY_REFERENCE, $locked->id);
                $this->payments->markFailed($locked);
            }

            if ($to === OrderStatus::Delivered) {
                $this->inventory->completeSale(Order::INVENTORY_REFERENCE, $locked->id);
                $this->payments->markPaid($locked);
            }

            $locked->update(['status' => $to]);
            $this->recordHistory($locked, $from, $to, $actor, $note);

            return $locked;
        });
    }

    public function recordHistory(Order $order, ?OrderStatus $from, OrderStatus $to, ?User $actor, ?string $note = null): void
    {
        $order->statusHistories()->create([
            'old_status' => $from,
            'new_status' => $to,
            'changed_by' => $actor?->id,
            'note' => $note,
        ]);
    }

    private function rejectionMessage(OrderStatus $from, OrderStatus $to): string
    {
        return match (true) {
            $from === $to => "The order is already {$from->value}.",
            $to === OrderStatus::Cancelled => "The order can no longer be cancelled because it is {$from->value}.",
            default => "The order status cannot change from {$from->value} to {$to->value}.",
        };
    }
}
