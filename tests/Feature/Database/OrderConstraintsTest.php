<?php

namespace Tests\Feature\Database;

use App\Models\Book;
use App\Models\CustomerAddress;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private function assertRejected(callable $statement, string $constraint): void
    {
        try {
            DB::transaction($statement);
            $this->fail("Expected {$constraint} to reject the statement.");
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());
        }
    }

    private function insertOrder(array $overrides = []): int
    {
        $address = CustomerAddress::factory()->create();

        return DB::table('orders')->insertGetId($overrides + [
            'order_no' => 'BK'.fake()->unique()->numerify('######'),
            'customer_id' => $address->customer_id,
            'address_id' => $address->id,
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'shipping_fee' => 0,
            'subtotal' => 100,
            'total_amount' => 100,
            'ordered_at' => now(),
        ]);
    }

    public function test_order_values_are_guarded(): void
    {
        $this->assertRejected(fn () => $this->insertOrder(['status' => 'lost']), 'orders_status_valid');
        $this->assertRejected(fn () => $this->insertOrder(['payment_status' => 'refunded']), 'orders_payment_status_valid');
        $this->assertRejected(fn () => $this->insertOrder(['subtotal' => -1, 'total_amount' => -1]), 'orders_amounts_non_negative');
        $this->assertRejected(fn () => $this->insertOrder(['total_amount' => 99]), 'orders_total_matches');
        $this->assertRejected(fn () => $this->insertOrder(['order_no' => 'DUP']) && $this->insertOrder(['order_no' => 'DUP']), 'orders_order_no_unique');
    }

    public function test_order_item_values_are_guarded(): void
    {
        $orderId = $this->insertOrder();
        $item = fn (array $o) => fn () => DB::table('order_items')->insert($o + [
            'order_id' => $orderId, 'book_id' => Book::factory()->create()->id,
            'quantity' => 2, 'unit_price' => 50, 'subtotal' => 100,
        ]);

        $this->assertRejected($item(['quantity' => 0, 'subtotal' => 0]), 'order_items_quantity_positive');
        $this->assertRejected($item(['unit_price' => -50, 'subtotal' => -100]), 'order_items_unit_price_non_negative');
        $this->assertRejected($item(['subtotal' => 99]), 'order_items_subtotal_matches');
    }

    public function test_orders_cannot_be_deleted_while_they_have_items_or_history(): void
    {
        $orderId = $this->insertOrder();
        DB::table('order_status_histories')->insert(['order_id' => $orderId, 'old_status' => null, 'new_status' => 'pending']);

        $this->assertRejected(fn () => DB::table('orders')->where('id', $orderId)->delete(), 'order_status_histories_order_id_foreign');
        $this->assertRejected(fn () => DB::table('order_status_histories')->insert(['order_id' => $orderId, 'old_status' => 'pending', 'new_status' => 'pending']), 'order_status_histories_status_changes');
    }

    public function test_address_used_by_an_order_cannot_be_deleted(): void
    {
        $orderId = $this->insertOrder();
        $addressId = DB::table('orders')->where('id', $orderId)->value('address_id');

        $this->assertRejected(fn () => DB::table('customer_addresses')->where('id', $addressId)->delete(), 'orders_address_id_foreign');
    }
}
