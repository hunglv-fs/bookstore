<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShoppingData;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use BuildsShoppingData, RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->customerUser();
        Sanctum::actingAs($this->customer);
    }

    /**
     * @param  array<int, int>  $quantities
     */
    private function placeOrder(array $quantities, array $payload = []): Order
    {
        $this->fillCart($this->customer, $quantities);
        $id = $this->postJson('/api/orders', $payload + ['address_id' => $this->addressFor($this->customer)->id])
            ->assertCreated()
            ->json('data.id');

        return Order::findOrFail($id);
    }

    public function test_cod_payment_is_created_at_checkout(): void
    {
        $order = $this->placeOrder([$this->bookWithStock(5, 120000)->id => 1]);

        $this->getJson("/api/orders/{$order->id}/payments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.method', 'cod')
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.amount', '120000.00')
            ->assertJsonPath('data.0.transaction_no', null)
            ->assertJsonPath('data.0.paid_at', null);

        $this->getJson("/api/orders/{$order->id}")
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.payments.0.method', 'cod');
    }

    public function test_payment_amount_matches_order_total(): void
    {
        $order = $this->placeOrder([
            $this->bookWithStock(10, 99999.99)->id => 3,
            $this->bookWithStock(10, 0.01)->id => 1,
            $this->bookWithStock(10, 45500)->id => 2,
        ]);

        $payment = Payment::where('order_id', $order->id)->sole();
        $this->assertSame('390999.98', $order->total_amount);
        $this->assertSame($order->total_amount, $payment->amount);
    }

    public function test_only_cod_can_be_chosen(): void
    {
        $book = $this->bookWithStock(5);
        $this->fillCart($this->customer, [$book->id => 1]);

        $this->postJson('/api/orders', ['address_id' => $this->addressFor($this->customer)->id, 'payment_method' => 'credit_card'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_method']);

        $this->placeOrder([], ['payment_method' => 'cod']);
        $this->assertSame(1, Payment::count());
    }

    public function test_failed_checkout_creates_no_payment(): void
    {
        $this->fillCart($this->customer, [$this->bookWithStock(1)->id => 2]);

        $this->postJson('/api/orders', ['address_id' => $this->addressFor($this->customer)->id])->assertUnprocessable();

        $this->assertSame(0, Payment::count());
    }

    public function test_delivery_collects_the_cod_payment(): void
    {
        $order = $this->placeOrder([$this->bookWithStock(5, 80000)->id => 2]);
        $admin = User::factory()->admin()->create();
        foreach ([OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Shipped] as $status) {
            app(OrderService::class)->changeStatus($order, $status, $admin);
            $this->assertSame(PaymentStatus::Pending, Payment::sole()->status, "still pending at {$status->value}");
        }

        $this->travelTo(now()->setTime(15, 30));
        app(OrderService::class)->changeStatus($order, OrderStatus::Delivered, $admin);

        $payment = Payment::sole();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(now()->toDateTimeString(), $payment->paid_at->toDateTimeString());
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);

        $this->getJson("/api/orders/{$order->id}/payments")->assertJsonPath('data.0.status', 'paid');
    }

    public function test_cancellation_fails_the_pending_cod_payment(): void
    {
        $order = $this->placeOrder([$this->bookWithStock(5)->id => 1]);

        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.payments.0.status', 'failed');

        $this->assertNull(Payment::sole()->paid_at);
    }

    public function test_cannot_see_payments_of_another_customers_order(): void
    {
        $order = $this->placeOrder([$this->bookWithStock(5)->id => 1]);

        Sanctum::actingAs($this->customerUser());
        $this->getJson("/api/orders/{$order->id}/payments")->assertNotFound();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson("/api/orders/{$order->id}/payments")->assertForbidden();
    }
}
