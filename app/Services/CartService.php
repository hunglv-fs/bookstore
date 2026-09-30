<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CartService
{
    public const MAX_QUANTITY_PER_ITEM = 100;

    /**
     * The customer's active cart, created on first use.
     *
     * firstOrCreate() inserts inside a savepoint and falls back to a SELECT on
     * a unique violation, so two concurrent first requests cannot create two
     * active carts (carts_one_active_per_customer) or fail with an error.
     */
    public function activeCartFor(Customer $customer): Cart
    {
        return Cart::query()->active()->firstOrCreate(['customer_id' => $customer->id]);
    }

    /**
     * Add a book; if it is already in the cart the quantities are merged into
     * the existing line (UNIQUE(cart_id, book_id)).
     */
    public function addItem(Customer $customer, int $bookId, int $quantity): Cart
    {
        return DB::transaction(function () use ($customer, $bookId, $quantity) {
            $cart = $this->lockActiveCart($customer);

            $item = $cart->items()->where('book_id', $bookId)->first();
            $newQuantity = ($item?->quantity ?? 0) + $quantity;
            $this->ensureWithinLimit($newQuantity);

            if ($item) {
                $item->update(['quantity' => $newQuantity]);
            } else {
                $cart->items()->create(['book_id' => $bookId, 'quantity' => $quantity]);
            }

            return $cart;
        });
    }

    public function updateItem(CartItem $item, int $quantity): Cart
    {
        $this->ensureWithinLimit($quantity);

        return DB::transaction(function () use ($item, $quantity) {
            $cart = $this->lockCartOfItem($item);
            $cart->items()->whereKey($item->id)->firstOrFail()->update(['quantity' => $quantity]);

            return $cart;
        });
    }

    public function removeItem(CartItem $item): Cart
    {
        return DB::transaction(function () use ($item) {
            $cart = $this->lockCartOfItem($item);
            $cart->items()->whereKey($item->id)->delete();

            return $cart;
        });
    }

    /**
     * Every cart mutation locks the cart row first (SELECT ... FOR UPDATE), so
     * mutations of one cart are serialized with each other and with checkout,
     * which locks the same row before converting the cart.
     *
     * Under READ COMMITTED, if checkout converts the cart while we wait for the
     * lock, PostgreSQL re-checks "status = 'active'" on the new row version and
     * returns nothing; a fresh active cart is then created and locked.
     */
    private function lockActiveCart(Customer $customer): Cart
    {
        $lock = fn () => Cart::query()
            ->active()
            ->where('customer_id', $customer->id)
            ->lockForUpdate()
            ->first();

        if ($cart = $lock()) {
            return $cart;
        }

        $this->activeCartFor($customer);

        return $lock() ?? throw new \RuntimeException('Could not lock the active cart.');
    }

    /**
     * Lock the item's cart and make sure it is still active: the policy check
     * ran before the lock, and checkout may have converted the cart since.
     */
    private function lockCartOfItem(CartItem $item): Cart
    {
        return Cart::query()->active()->whereKey($item->cart_id)->lockForUpdate()->first()
            ?? throw new NotFoundHttpException;
    }

    private function ensureWithinLimit(int $quantity): void
    {
        if ($quantity > self::MAX_QUANTITY_PER_ITEM) {
            throw ValidationException::withMessages([
                'quantity' => ['The quantity of a single book cannot exceed '.self::MAX_QUANTITY_PER_ITEM.'.'],
            ]);
        }
    }
}
