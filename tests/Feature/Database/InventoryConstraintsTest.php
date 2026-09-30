<?php

namespace Tests\Feature\Database;

use App\Models\Book;
use App\Models\Inventory;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryConstraintsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * PostgreSQL aborts the transaction after an error: run each rejected
     * statement in its own savepoint.
     */
    private function assertRejected(callable $statement, string $constraint): void
    {
        try {
            DB::transaction($statement);
            $this->fail("Expected {$constraint} to reject the statement.");
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());
        }
    }

    private function txn(array $overrides): array
    {
        return $overrides + [
            'book_id' => Book::factory()->create()->id,
            'transaction_type' => 'purchase',
            'quantity_change' => 1,
            'reference_type' => null,
            'reference_id' => null,
        ];
    }

    public function test_inventory_counters_are_guarded(): void
    {
        $inventory = Inventory::factory()->create(['quantity' => 5, 'reserved_quantity' => 2]);
        $update = fn (array $values) => fn () => DB::table('inventory')->where('id', $inventory->id)->update($values);

        $this->assertRejected($update(['quantity' => -1]), 'inventory_quantity_non_negative');
        $this->assertRejected($update(['reserved_quantity' => -1]), 'inventory_reserved_non_negative');
        $this->assertRejected($update(['reserved_quantity' => 6]), 'inventory_reserved_within_quantity');
        $this->assertRejected($update(['quantity' => 1]), 'inventory_reserved_within_quantity');
    }

    public function test_one_inventory_row_per_book(): void
    {
        $inventory = Inventory::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        Inventory::factory()->create(['book_id' => $inventory->book_id]);
    }

    public function test_transaction_type_and_sign_are_guarded(): void
    {
        $insert = fn (array $row) => fn () => DB::table('inventory_transactions')->insert($this->txn($row));

        // An unknown type violates both the type and the sign CHECKs; PostgreSQL
        // reports whichever it evaluates first, so only assert a check violation.
        $this->assertRejected($insert(['transaction_type' => 'gift']), 'SQLSTATE[23514]');
        $this->assertRejected($insert(['transaction_type' => 'purchase', 'quantity_change' => -5]), 'inventory_transactions_sign_matches_type');
        $this->assertRejected($insert(['transaction_type' => 'sale', 'quantity_change' => 2, 'reference_type' => 'order', 'reference_id' => 1]), 'inventory_transactions_sign_matches_type');
        $this->assertRejected($insert(['transaction_type' => 'adjustment', 'quantity_change' => 0]), 'inventory_transactions_sign_matches_type');
        $this->assertRejected($insert(['transaction_type' => 'reservation', 'quantity_change' => 1]), 'inventory_transactions_order_movements_referenced');
        $this->assertRejected($insert(['reference_type' => 'order']), 'inventory_transactions_reference_pair');
    }

    public function test_an_order_moves_the_stock_of_a_book_only_once_per_type(): void
    {
        $row = $this->txn(['transaction_type' => 'release', 'quantity_change' => -1, 'reference_type' => 'order', 'reference_id' => 9]);
        DB::table('inventory_transactions')->insert($row);

        $this->assertRejected(fn () => DB::table('inventory_transactions')->insert($row), 'inventory_transactions_once_per_reference');

        // Purchases are not limited: the same delivery note may bring stock twice.
        $purchase = $this->txn(['reference_type' => 'purchase_order', 'reference_id' => 1]);
        DB::table('inventory_transactions')->insert([$purchase, $purchase]);
        $this->assertSame(2, DB::table('inventory_transactions')->where('transaction_type', 'purchase')->count());
    }
}
