<?php

namespace App\Services;

use App\Enums\CartStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InsufficientStockException;
use App\Models\Book;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns the customer's active cart into an order.
 *
 * Everything runs in ONE database transaction; any failure rolls back all of
 * it, so an order never exists without its reservation and a reservation
 * never exists without its order.
 *
 * Locks, in this order (same order everywhere, so no deadlock cycle):
 *   1. carts row          FOR UPDATE  - one checkout per cart; waits for / blocks cart edits
 *   2. books rows         FOR SHARE   - status and price stay as validated until commit
 *   3. inventory rows     FOR UPDATE  - taken inside InventoryService::reserve, sorted by book_id
 * All locks are released at COMMIT / ROLLBACK.
 */
class CheckoutService
{
    /** Advanced shipping is out of MVP scope: shipping is free. */
    public const SHIPPING_FEE = '0.00';

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @throws ValidationException empty cart or unavailable books (422)
     * @throws InsufficientStockException (422)
     */
    public function checkout(
        Customer $customer,
        int $addressId,
        ?string $note,
        User $actor,
        PaymentMethod $paymentMethod = PaymentMethod::Cod,
    ): Order {
        return DB::transaction(function () use ($customer, $addressId, $note, $actor, $paymentMethod) {
            // 1. Lock the cart. A second, concurrent checkout of the same cart
            //    waits here; once we commit it re-evaluates status = 'active',
            //    finds no active cart and fails with "cart is empty".
            $cart = Cart::query()->active()->where('customer_id', $customer->id)->lockForUpdate()->first();
            $items = $cart?->items()->get() ?? collect();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['cart' => ['Your cart is empty.']]);
            }

            // 2. Validate books on share-locked rows and take their current price.
            $books = Book::query()
                ->whereIn('id', $items->pluck('book_id'))
                ->orderBy('id')
                ->sharedLock()
                ->get()
                ->keyBy('id');

            $this->ensureBooksAreAvailable($items, $books);

            // 3. Price snapshot and amounts (bcmath: exact decimal arithmetic).
            $lines = $items->map(fn (CartItem $item) => [
                'book_id' => $item->book_id,
                'quantity' => $item->quantity,
                'unit_price' => $books[$item->book_id]->price,
                'subtotal' => bcmul($books[$item->book_id]->price, (string) $item->quantity, 2),
            ]);
            $subtotal = $lines->reduce(fn (string $sum, array $line) => bcadd($sum, $line['subtotal'], 2), '0.00');

            // 4-5. Order and its items.
            $order = $this->createOrder([
                'customer_id' => $customer->id,
                'address_id' => $addressId,
                'status' => OrderStatus::Pending,
                'payment_status' => OrderPaymentStatus::Unpaid,
                'shipping_fee' => self::SHIPPING_FEE,
                'subtotal' => $subtotal,
                'total_amount' => bcadd($subtotal, self::SHIPPING_FEE, 2),
                'note' => $note,
                'ordered_at' => now(),
            ]);
            $order->items()->createMany($lines->all());

            // 6. Check stock and reserve it in one locked step. It comes after the
            //    order insert because the reservation references the order id;
            //    if stock is short the exception rolls the order back too.
            $this->inventory->reserve(
                $lines->pluck('quantity', 'book_id')->all(),
                Order::INVENTORY_REFERENCE,
                $order->id,
            );

            // 7. Payment record for the full amount (COD: pending until delivery).
            $this->payments->createForOrder($order, $paymentMethod);

            $this->orders->recordHistory($order, null, OrderStatus::Pending, $actor, 'Order placed');

            // 8. The cart is done: mark it converted and clear its items.
            $cart->items()->delete();
            $cart->update(['status' => CartStatus::Converted]);

            return $order;
        });
    }

    /**
     * @param  Collection<int, CartItem>  $items
     * @param  Collection<int, Book>  $books
     */
    private function ensureBooksAreAvailable(Collection $items, Collection $books): void
    {
        $messages = $items
            ->reject(fn (CartItem $item) => $books->get($item->book_id)?->isActive())
            ->map(fn (CartItem $item) => "\"{$books[$item->book_id]->title}\" (book {$item->book_id}) is no longer available.")
            ->values()
            ->all();

        if ($messages) {
            throw ValidationException::withMessages(['items' => $messages]);
        }
    }

    /**
     * Insert the order with a random, human-friendly order number. A clash on
     * the unique order_no (astronomically unlikely) is retried inside a
     * savepoint so the surrounding transaction survives.
     */
    private function createOrder(array $attributes): Order
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => Order::query()->create($attributes + ['order_no' => $this->newOrderNo()]));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
    }

    /**
     * e.g. BK260930-7KQ2M9XA: date for humans, 8 random chars against guessing.
     */
    private function newOrderNo(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // no 0/O/1/I confusion
        $random = implode('', array_map(fn () => $alphabet[random_int(0, 31)], range(1, 8)));

        return 'BK'.now()->format('ymd').'-'.$random;
    }
}
