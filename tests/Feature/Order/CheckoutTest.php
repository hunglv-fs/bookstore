<?php

namespace Tests\Feature\Order;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShoppingData;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use BuildsShoppingData, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->customerUser();
        Sanctum::actingAs($this->user);
    }

    private function checkout(array $payload = [])
    {
        return $this->postJson('/api/orders', $payload + ['address_id' => $this->addressFor($this->user)->id]);
    }

    private function assertNothingWasCreated(): void
    {
        $this->assertSame(0, Order::count());
        $this->assertSame(0, InventoryTransaction::where('transaction_type', 'reservation')->count());
        $this->assertSame(0, (int) Inventory::sum('reserved_quantity'));
    }

    public function test_successful_checkout(): void
    {
        $cleanCode = $this->bookWithStock(10, 250000);
        $refactoring = $this->bookWithStock(5, 199999.99);
        $this->fillCart($this->user, [$cleanCode->id => 2, $refactoring->id => 1]);
        $address = $this->addressFor($this->user);

        $response = $this->postJson('/api/orders', ['address_id' => $address->id, 'note' => 'Giao giờ hành chính']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.subtotal', '699999.99')
            ->assertJsonPath('data.shipping_fee', '0.00')
            ->assertJsonPath('data.total_amount', '699999.99')
            ->assertJsonPath('data.note', 'Giao giờ hành chính')
            ->assertJsonPath('data.address.id', $address->id)
            ->assertJsonPath('data.items.0.book.id', $cleanCode->id)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_price', '250000.00')
            ->assertJsonPath('data.items.0.subtotal', '500000.00')
            ->assertJsonPath('data.items.1.unit_price', '199999.99')
            ->assertJsonPath('data.status_histories.0.old_status', null)
            ->assertJsonPath('data.status_histories.0.new_status', 'pending')
            ->assertJsonPath('data.status_histories.0.changed_by', $this->user->id);

        $this->assertMatchesRegularExpression('/^BK\d{6}-[2-9A-HJ-NP-Z]{8}$/', $response->json('data.order_no'));

        $order = Order::firstOrFail();
        $this->assertSame($this->user->customer->id, $order->customer_id);

        // Inventory reserved, with ledger entries referencing the order.
        $this->assertSame(2, Inventory::where('book_id', $cleanCode->id)->value('reserved_quantity'));
        $this->assertSame(1, Inventory::where('book_id', $refactoring->id)->value('reserved_quantity'));
        $this->assertSame(10, Inventory::where('book_id', $cleanCode->id)->value('quantity'));
        $this->assertSame(2, InventoryTransaction::where(['transaction_type' => 'reservation', 'reference_type' => 'order', 'reference_id' => $order->id])->count());

        // Cart converted and emptied; the next add starts a new cart.
        $cart = Cart::firstOrFail();
        $this->assertSame(CartStatus::Converted, $cart->status);
        $this->assertSame(0, CartItem::count());
        $this->getJson('/api/cart')->assertJsonPath('data.items', [])->assertJsonMissing(['id' => $cart->id]);
    }

    public function test_order_keeps_the_price_at_purchase_time(): void
    {
        $book = $this->bookWithStock(10, 100000);
        $this->fillCart($this->user, [$book->id => 2]);
        $orderId = $this->checkout()->assertCreated()->json('data.id');

        $book->update(['price' => 150000]);

        $this->getJson("/api/orders/{$orderId}")
            ->assertJsonPath('data.items.0.unit_price', '100000.00')
            ->assertJsonPath('data.items.0.subtotal', '200000.00')
            ->assertJsonPath('data.total_amount', '200000.00');
    }

    public function test_checkout_uses_current_price_not_cart_time_price(): void
    {
        $book = $this->bookWithStock(10, 100000);
        $this->fillCart($this->user, [$book->id => 1]);
        $book->update(['price' => 120000]);

        $this->checkout()->assertCreated()->assertJsonPath('data.items.0.unit_price', '120000.00');
    }

    public function test_empty_cart_is_rejected(): void
    {
        $this->checkout()
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Your cart is empty.')
            ->assertJsonValidationErrors(['cart']);

        // An existing but empty cart behaves the same.
        $this->getJson('/api/cart');
        $this->checkout()->assertUnprocessable()->assertJsonValidationErrors(['cart']);

        $this->assertNothingWasCreated();
    }

    public function test_inactive_book_is_rejected_and_nothing_changes(): void
    {
        $ok = $this->bookWithStock(10);
        $gone = $this->bookWithStock(10);
        $this->fillCart($this->user, [$ok->id => 1, $gone->id => 1]);
        $gone->update(['status' => 'inactive']);

        $this->checkout()
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items'])
            ->assertJsonPath('errors.items.0', "\"{$gone->title}\" (book {$gone->id}) is no longer available.");

        $this->assertNothingWasCreated();
        $this->assertSame(CartStatus::Active, Cart::first()->status);
        $this->assertSame(2, CartItem::count());
    }

    public function test_insufficient_stock_rolls_back_the_whole_checkout(): void
    {
        $plenty = $this->bookWithStock(10);
        $scarce = $this->bookWithStock(1);
        $this->fillCart($this->user, [$plenty->id => 2, $scarce->id => 3]);

        $this->checkout()
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Insufficient stock')
            ->assertJsonPath('shortages.0', ['book_id' => $scarce->id, 'requested' => 3, 'available' => 1]);

        // The order row inserted before the reservation step was rolled back too.
        $this->assertNothingWasCreated();
        $this->assertSame(0, OrderStatusHistory::count());
        $this->assertSame(CartStatus::Active, Cart::first()->status);
        $this->assertSame(2, CartItem::count());
    }

    public function test_out_of_stock_book_without_inventory_row_is_rejected(): void
    {
        $book = $this->bookWithStock(0);
        $this->fillCart($this->user, [$book->id => 1]);

        $this->checkout()->assertUnprocessable()->assertJsonPath('shortages.0.available', 0);
        $this->assertNothingWasCreated();
    }

    public function test_address_must_belong_to_the_customer(): void
    {
        $book = $this->bookWithStock(10);
        $this->fillCart($this->user, [$book->id => 1]);
        $foreignAddress = $this->addressFor($this->customerUser());

        $this->postJson('/api/orders', ['address_id' => $foreignAddress->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['address_id']);

        $this->postJson('/api/orders', [])->assertUnprocessable()->assertJsonValidationErrors(['address_id']);
        $this->assertNothingWasCreated();
    }

    public function test_second_checkout_of_the_same_cart_finds_it_empty(): void
    {
        $book = $this->bookWithStock(10);
        $this->fillCart($this->user, [$book->id => 1]);

        $this->checkout()->assertCreated();
        $this->checkout()->assertUnprocessable()->assertJsonValidationErrors(['cart']);

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Inventory::where('book_id', $book->id)->value('reserved_quantity'));
    }

    public function test_admin_cannot_checkout(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/orders', ['address_id' => 1])->assertForbidden();
    }
}
