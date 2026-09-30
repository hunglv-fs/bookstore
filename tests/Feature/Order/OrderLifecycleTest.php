<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Models\Book;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShoppingData;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use BuildsShoppingData, RefreshDatabase;

    private User $customer;

    private User $admin;

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->customerUser();
        $this->admin = User::factory()->admin()->create();
        $this->book = $this->bookWithStock(10, 80000);
    }

    private function placeOrder(User $user, int $quantity = 2): Order
    {
        $this->fillCart($user, [$this->book->id => $quantity]);
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/orders', ['address_id' => $this->addressFor($user)->id])->assertCreated()->json('data.id');

        return Order::findOrFail($id);
    }

    private function stock(): Inventory
    {
        return Inventory::where('book_id', $this->book->id)->firstOrFail();
    }

    private function adminSetsStatus(Order $order, string $status)
    {
        Sanctum::actingAs($this->admin);

        return $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => $status]);
    }

    private function advanceTo(Order $order, OrderStatus $target): void
    {
        foreach ([OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered] as $status) {
            app(OrderService::class)->changeStatus($order, $status, $this->admin);
            if ($status === $target) {
                return;
            }
        }
    }

    // --- Listing and detail -------------------------------------------------

    public function test_customer_lists_own_orders_newest_first(): void
    {
        $first = $this->placeOrder($this->customer, 1);
        $this->travel(1)->minutes();
        $second = $this->placeOrder($this->customer, 1);
        $this->placeOrder($this->customerUser(), 1);

        Sanctum::actingAs($this->customer);
        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.0.items.0.book.title', $this->book->title)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_customer_gets_own_order_detail(): void
    {
        $order = $this->placeOrder($this->customer);

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.order_no', $order->order_no)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.total_amount', '160000.00')
            ->assertJsonCount(1, 'data.status_histories');
    }

    public function test_customer_cannot_access_another_customers_order(): void
    {
        $order = $this->placeOrder($this->customerUser());

        Sanctum::actingAs($this->customer);
        $this->getJson("/api/orders/{$order->id}")->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
        $this->postJson("/api/orders/{$order->id}/cancel")->assertNotFound();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    // --- Customer cancel ----------------------------------------------------

    public function test_cancel_order_releases_stock_and_records_history(): void
    {
        $order = $this->placeOrder($this->customer, 3);
        $this->assertSame(3, $this->stock()->reserved_quantity);

        $this->postJson("/api/orders/{$order->id}/cancel", ['note' => 'Đặt nhầm'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonPath('data.status_histories.1.old_status', 'pending')
            ->assertJsonPath('data.status_histories.1.new_status', 'cancelled')
            ->assertJsonPath('data.status_histories.1.note', 'Đặt nhầm')
            ->assertJsonPath('data.status_histories.1.changed_by', $this->customer->id);

        $this->assertSame(0, $this->stock()->reserved_quantity);
        $this->assertSame(10, $this->stock()->quantity);
        $this->assertDatabaseHas('inventory_transactions', ['transaction_type' => 'release', 'quantity_change' => -3, 'reference_id' => $order->id]);
    }

    public function test_cancelling_twice_does_not_release_twice(): void
    {
        $order = $this->placeOrder($this->customer, 2);
        $this->placeOrder($other = $this->customerUser(), 3);
        Sanctum::actingAs($this->customer);

        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();
        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'The order is already cancelled.');

        // The other customer's reservation is untouched.
        $this->assertSame(3, $this->stock()->reserved_quantity);
        $this->assertSame(1, InventoryTransaction::where('transaction_type', 'release')->count());
        $this->assertSame(2, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_confirmed_and_processing_orders_can_still_be_cancelled(): void
    {
        $order = $this->placeOrder($this->customer);
        $this->advanceTo($order, OrderStatus::Processing);

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(0, $this->stock()->reserved_quantity);
    }

    public function test_cannot_cancel_shipped_order(): void
    {
        $order = $this->placeOrder($this->customer);
        $this->advanceTo($order, OrderStatus::Shipped);

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'The order can no longer be cancelled because it is shipped.');

        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
        $this->assertSame(2, $this->stock()->reserved_quantity);
    }

    public function test_cannot_cancel_delivered_order(): void
    {
        $order = $this->placeOrder($this->customer);
        $this->advanceTo($order, OrderStatus::Delivered);

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/orders/{$order->id}/cancel")->assertUnprocessable();
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    // --- Admin status changes -----------------------------------------------

    public function test_admin_moves_order_to_delivered_and_stock_is_sold_once(): void
    {
        $order = $this->placeOrder($this->customer, 2);

        foreach (['confirmed', 'processing', 'shipped'] as $status) {
            $this->adminSetsStatus($order, $status)->assertOk()->assertJsonPath('data.status', $status);
            $this->assertSame(10, $this->stock()->quantity, "no stock movement at {$status}");
            $this->assertSame(2, $this->stock()->reserved_quantity);
        }

        $this->adminSetsStatus($order, 'delivered')
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonCount(5, 'data.status_histories')
            ->assertJsonPath('data.status_histories.4.changed_by', $this->admin->id);

        $this->assertSame(8, $this->stock()->quantity);
        $this->assertSame(0, $this->stock()->reserved_quantity);
        $this->assertDatabaseHas('inventory_transactions', ['transaction_type' => 'sale', 'quantity_change' => -2, 'reference_id' => $order->id]);

        // Repeating the call must not sell the stock again.
        $this->adminSetsStatus($order, 'delivered')
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'The order is already delivered.');

        $this->assertSame(8, $this->stock()->quantity);
        $this->assertSame(1, InventoryTransaction::where('transaction_type', 'sale')->count());
        $this->assertSame(
            ['pending', 'confirmed', 'processing', 'shipped', 'delivered'],
            OrderStatusHistory::where('order_id', $order->id)->orderBy('id')->pluck('new_status')->map->value->all(),
        );
    }

    public function test_admin_cannot_skip_steps(): void
    {
        $order = $this->placeOrder($this->customer);

        $this->adminSetsStatus($order, 'delivered')
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'The order status cannot change from pending to delivered.');

        $this->assertSame(10, $this->stock()->quantity);
        $this->assertSame(2, $this->stock()->reserved_quantity);
    }

    public function test_admin_can_cancel_and_stock_is_released(): void
    {
        $order = $this->placeOrder($this->customer);
        $this->adminSetsStatus($order, 'confirmed');

        $this->adminSetsStatus($order, 'cancelled')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(0, $this->stock()->reserved_quantity);

        $this->adminSetsStatus($order, 'confirmed')->assertUnprocessable();
    }

    public function test_status_endpoint_validates_and_is_admin_only(): void
    {
        $order = $this->placeOrder($this->customer);

        Sanctum::actingAs($this->customer);
        $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'confirmed'])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'teleported'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'pending'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->patchJson('/api/admin/orders/999999/status', ['status' => 'confirmed'])->assertNotFound();
    }

    public function test_admin_cannot_use_customer_order_endpoints(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/orders')->assertForbidden();
    }
}
