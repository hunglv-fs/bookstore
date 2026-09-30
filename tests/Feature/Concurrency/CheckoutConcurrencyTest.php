<?php

namespace Tests\Feature\Concurrency;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Models\Book;
use App\Models\Cart;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Concerns\BuildsShoppingData;
use Tests\Concerns\RunsConcurrentWorkers;
use Tests\TestCase;

/**
 * End-to-end races through CheckoutService / OrderService, each request in
 * its own process and transaction.
 */
class CheckoutConcurrencyTest extends TestCase
{
    use BuildsShoppingData, DatabaseTruncation, RunsConcurrentWorkers;

    protected $exceptTables = ['roles'];

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    private function lockInventoryOf(Book $book): callable
    {
        return fn () => Inventory::where('book_id', $book->id)->lockForUpdate()->first();
    }

    /**
     * @return list<string> worker args for a checkout by $user
     */
    private function checkoutArgs(User $user): array
    {
        return ['checkout', (string) $user->id, (string) $this->addressFor($user)->id];
    }

    public function test_two_customers_checking_out_the_last_copy_only_one_order_is_created(): void
    {
        $book = $this->bookWithStock(1);
        [$alice, $bob] = [$this->customerUser(), $this->customerUser()];
        $this->fillCart($alice, [$book->id => 1]);
        $this->fillCart($bob, [$book->id => 1]);

        // Both checkouts get past cart + book locks, insert their order, then queue on the inventory row.
        $outcomes = $this->raceWorkers($this->lockInventoryOf($book), [$this->checkoutArgs($alice), $this->checkoutArgs($bob)]);

        sort($outcomes);
        $this->assertSame(['insufficient', 'ok'], $outcomes);

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count(), 'the rolled-back order left no payment');
        $this->assertSame(1, Inventory::where('book_id', $book->id)->value('reserved_quantity'));
        $this->assertSame(1, InventoryTransaction::where('transaction_type', 'reservation')->count());

        // The loser's order was rolled back and their cart is untouched.
        $loser = Order::first()->customer_id === $alice->customer->id ? $bob : $alice;
        $loserCart = Cart::where('customer_id', $loser->customer->id)->first();
        $this->assertSame(CartStatus::Active, $loserCart->status);
        $this->assertSame(1, $loserCart->items()->count());
    }

    public function test_many_customers_never_oversell(): void
    {
        $book = $this->bookWithStock(3);
        $workers = [];
        foreach (range(1, 8) as $i) {
            $user = $this->customerUser();
            $this->fillCart($user, [$book->id => 1]);
            $workers[] = $this->checkoutArgs($user);
        }

        $outcomes = array_count_values($this->raceWorkers($this->lockInventoryOf($book), $workers));

        $this->assertSame(3, $outcomes['ok'] ?? 0, json_encode($outcomes));
        $this->assertSame(5, $outcomes['insufficient'] ?? 0, json_encode($outcomes));
        $this->assertSame(3, Order::count());
        $this->assertSame(3, Inventory::where('book_id', $book->id)->value('reserved_quantity'));
    }

    public function test_double_submitted_checkout_creates_one_order(): void
    {
        $book = $this->bookWithStock(10);
        $user = $this->customerUser();
        $this->fillCart($user, [$book->id => 2]);
        $cart = Cart::where('customer_id', $user->customer->id)->first();

        $outcomes = $this->raceWorkers(
            fn () => Cart::whereKey($cart->id)->lockForUpdate()->first(),
            [$this->checkoutArgs($user), $this->checkoutArgs($user)],
        );

        sort($outcomes);
        $this->assertSame(['invalid:cart', 'ok'], $outcomes);
        $this->assertSame(1, Order::count());
        $this->assertSame(2, Inventory::where('book_id', $book->id)->value('reserved_quantity'));
    }

    public function test_concurrent_cancel_requests_release_stock_once(): void
    {
        $book = $this->bookWithStock(10);
        $user = $this->customerUser();
        $this->fillCart($user, [$book->id => 4]);
        $order = app(CheckoutService::class)->checkout($user->customer, $this->addressFor($user)->id, null, $user);

        $args = ['status', (string) $order->id, (string) $user->id, 'cancelled'];
        $outcomes = $this->raceWorkers(fn () => Order::whereKey($order->id)->lockForUpdate()->first(), [$args, $args, $args]);

        sort($outcomes);
        $this->assertSame(['invalid:status', 'invalid:status', 'ok'], $outcomes);
        $this->assertSame(0, Inventory::where('book_id', $book->id)->value('reserved_quantity'));
        $this->assertSame(1, InventoryTransaction::where('transaction_type', 'release')->count());
        $this->assertSame(2, OrderStatusHistory::where('order_id', $order->id)->count());
        $this->assertSame(['failed'], Payment::where('order_id', $order->id)->pluck('status')->map->value->all());
    }

    public function test_concurrent_delivered_requests_sell_stock_once(): void
    {
        $book = $this->bookWithStock(10);
        $user = $this->customerUser();
        $admin = User::factory()->admin()->create();
        $this->fillCart($user, [$book->id => 3]);
        $order = app(CheckoutService::class)->checkout($user->customer, $this->addressFor($user)->id, null, $user);
        foreach ([OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Shipped] as $status) {
            app(OrderService::class)->changeStatus($order, $status, $admin);
        }

        $args = ['status', (string) $order->id, (string) $admin->id, 'delivered'];
        $outcomes = $this->raceWorkers(fn () => Order::whereKey($order->id)->lockForUpdate()->first(), [$args, $args, $args]);

        sort($outcomes);
        $this->assertSame(['invalid:status', 'invalid:status', 'ok'], $outcomes);

        $stock = Inventory::where('book_id', $book->id)->first();
        $this->assertSame(7, $stock->quantity);
        $this->assertSame(0, $stock->reserved_quantity);
        $this->assertSame(1, InventoryTransaction::where('transaction_type', 'sale')->count());
        $this->assertSame(['paid'], Payment::where('order_id', $order->id)->pluck('status')->map->value->all());
    }
}
