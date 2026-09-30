<?php

namespace Tests\Feature\Database;

use App\Models\CustomerAddress;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();

        $address = CustomerAddress::factory()->create();
        $this->orderId = DB::table('orders')->insertGetId([
            'order_no' => 'BK000000-TEST0001', 'customer_id' => $address->customer_id, 'address_id' => $address->id,
            'subtotal' => 100, 'total_amount' => 100, 'ordered_at' => now(),
        ]);
    }

    private function assertRejected(callable $statement, string $constraint): void
    {
        try {
            DB::transaction($statement);
            $this->fail("Expected {$constraint} to reject the statement.");
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());
        }
    }

    private function payment(array $overrides = []): callable
    {
        return fn () => DB::table('payments')->insert($overrides + [
            'order_id' => $this->orderId, 'method' => 'cod', 'amount' => 100, 'status' => 'pending',
        ]);
    }

    public function test_payment_values_are_guarded(): void
    {
        $this->assertRejected($this->payment(['method' => 'bitcoin']), 'payments_method_valid');
        $this->assertRejected($this->payment(['status' => 'refunded']), 'payments_status_valid');
        $this->assertRejected($this->payment(['amount' => -1]), 'payments_amount_non_negative');
        $this->assertRejected($this->payment(['status' => 'paid']), 'payments_paid_at_iff_paid');
        $this->assertRejected($this->payment(['status' => 'pending', 'paid_at' => now()]), 'payments_paid_at_iff_paid');
    }

    public function test_one_live_payment_per_order_but_failed_attempts_may_repeat(): void
    {
        ($this->payment(['status' => 'failed']))();
        ($this->payment(['status' => 'failed']))();
        ($this->payment())();

        $this->assertRejected($this->payment(), 'payments_one_live_per_order');
        $this->assertRejected($this->payment(['status' => 'paid', 'paid_at' => now()]), 'payments_one_live_per_order');
    }

    public function test_gateway_transaction_number_is_recorded_once(): void
    {
        ($this->payment(['method' => 'e_wallet', 'status' => 'failed', 'transaction_no' => 'MOMO-1']))();

        $this->assertRejected($this->payment(['method' => 'e_wallet', 'status' => 'failed', 'transaction_no' => 'MOMO-1']), 'payments_method_transaction_no_unique');
    }

    public function test_order_with_payments_cannot_be_deleted(): void
    {
        ($this->payment())();

        $this->assertRejected(fn () => DB::table('orders')->where('id', $this->orderId)->delete(), 'payments_order_id_foreign');
    }
}
