<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryTransactionType as Type;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InventoryException;
use App\Models\Book;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventory = app(InventoryService::class);
    }

    private function stock(Book $book): Inventory
    {
        return Inventory::where('book_id', $book->id)->firstOrFail();
    }

    private function bookWithStock(int $quantity): Book
    {
        $book = Book::factory()->create();
        $this->inventory->receive($book->id, $quantity);

        return $book;
    }

    /**
     * quantity and reserved_quantity must always be reproducible from the ledger.
     */
    private function assertLedgerReconciles(Book $book): void
    {
        $sum = fn (array $types) => (int) InventoryTransaction::where('book_id', $book->id)
            ->whereIn('transaction_type', array_map(fn (Type $t) => $t->value, $types))
            ->sum('quantity_change');

        $stock = $this->stock($book);
        $this->assertSame($sum([Type::Purchase, Type::Adjustment, Type::Sale]), $stock->quantity, 'quantity vs ledger');
        $this->assertSame($sum([Type::Reservation, Type::Release, Type::Sale]), $stock->reserved_quantity, 'reserved vs ledger');
    }

    public function test_receive_creates_inventory_and_records_purchase(): void
    {
        $book = Book::factory()->create();

        $this->inventory->receive($book->id, 100);
        $this->inventory->receive($book->id, 20);

        $this->assertSame(120, $this->stock($book)->quantity);
        $this->assertSame(1, Inventory::count());
        $this->assertSame([100, 20], InventoryTransaction::where('transaction_type', 'purchase')->orderBy('id')->pluck('quantity_change')->all());
        $this->assertLedgerReconciles($book);
    }

    public function test_reserve_stock(): void
    {
        $book = $this->bookWithStock(10);

        $this->inventory->reserve([$book->id => 2], 'order', 1);

        $stock = $this->stock($book);
        $this->assertSame(10, $stock->quantity);
        $this->assertSame(2, $stock->reserved_quantity);
        $this->assertSame(8, $stock->available());
        $this->assertDatabaseHas('inventory_transactions', [
            'book_id' => $book->id, 'transaction_type' => 'reservation', 'quantity_change' => 2,
            'reference_type' => 'order', 'reference_id' => 1,
        ]);
        $this->assertLedgerReconciles($book);
    }

    public function test_reserve_is_all_or_nothing_and_reports_every_shortage(): void
    {
        $plenty = $this->bookWithStock(10);
        $short = $this->bookWithStock(1);
        $noInventoryRow = Book::factory()->create();

        try {
            $this->inventory->reserve([$plenty->id => 3, $short->id => 2, $noInventoryRow->id => 1], 'order', 1);
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException $e) {
            $this->assertEqualsCanonicalizing([
                ['book_id' => $short->id, 'requested' => 2, 'available' => 1],
                ['book_id' => $noInventoryRow->id, 'requested' => 1, 'available' => 0],
            ], $e->shortages);
        }

        $this->assertSame(0, $this->stock($plenty)->reserved_quantity);
        $this->assertSame(0, InventoryTransaction::where('transaction_type', 'reservation')->count());
    }

    public function test_reserved_units_are_not_available_to_others(): void
    {
        $book = $this->bookWithStock(3);
        $this->inventory->reserve([$book->id => 2], 'order', 1);

        $this->expectException(InsufficientStockException::class);
        $this->inventory->reserve([$book->id => 2], 'order', 2);
    }

    public function test_release_stock(): void
    {
        $book = $this->bookWithStock(10);
        $this->inventory->reserve([$book->id => 2], 'order', 1);

        $this->inventory->release('order', 1);

        $stock = $this->stock($book);
        $this->assertSame(10, $stock->quantity);
        $this->assertSame(0, $stock->reserved_quantity);
        $this->assertDatabaseHas('inventory_transactions', ['transaction_type' => 'release', 'quantity_change' => -2, 'reference_id' => 1]);
        $this->assertLedgerReconciles($book);
    }

    public function test_complete_sale(): void
    {
        $book = $this->bookWithStock(10);
        $other = $this->bookWithStock(5);
        $this->inventory->reserve([$book->id => 2, $other->id => 1], 'order', 1);
        $this->inventory->reserve([$book->id => 3], 'order', 2);

        $this->inventory->completeSale('order', 1);

        // Only order 1's reservation is settled; order 2 is still reserved.
        $stock = $this->stock($book);
        $this->assertSame(8, $stock->quantity);
        $this->assertSame(3, $stock->reserved_quantity);
        $this->assertSame(5, $stock->available());
        $this->assertSame(4, $this->stock($other)->quantity);
        $this->assertSame(0, $this->stock($other)->reserved_quantity);
        $this->assertDatabaseHas('inventory_transactions', ['book_id' => $book->id, 'transaction_type' => 'sale', 'quantity_change' => -2]);
        $this->assertLedgerReconciles($book);
        $this->assertLedgerReconciles($other);
    }

    public function test_sale_cannot_be_completed_twice(): void
    {
        $book = $this->bookWithStock(10);
        $this->inventory->reserve([$book->id => 2], 'order', 1);
        $this->inventory->completeSale('order', 1);

        try {
            $this->inventory->completeSale('order', 1);
            $this->fail('Second completeSale must be rejected.');
        } catch (InventoryException) {
        }

        $this->assertSame(8, $this->stock($book)->quantity);
        $this->assertLedgerReconciles($book);
    }

    public function test_release_cannot_happen_twice_or_after_sale(): void
    {
        $book = $this->bookWithStock(10);
        $this->inventory->reserve([$book->id => 2], 'order', 1);
        $this->inventory->release('order', 1);

        try {
            $this->inventory->release('order', 1);
            $this->fail('Second release must be rejected.');
        } catch (InventoryException) {
        }

        $this->inventory->reserve([$book->id => 1], 'order', 2);
        $this->inventory->completeSale('order', 2);

        $this->expectException(InventoryException::class);
        $this->inventory->release('order', 2);
    }

    public function test_cannot_settle_what_was_never_reserved(): void
    {
        $this->expectException(InventoryException::class);
        $this->inventory->completeSale('order', 404);
    }

    public function test_same_reference_cannot_reserve_twice(): void
    {
        $book = $this->bookWithStock(10);
        $this->inventory->reserve([$book->id => 1], 'order', 1);

        $this->expectException(InventoryException::class);
        $this->inventory->reserve([$book->id => 1], 'order', 1);
    }

    public function test_adjustment_cannot_go_below_reserved_stock(): void
    {
        $book = $this->bookWithStock(10);
        $this->inventory->reserve([$book->id => 4], 'order', 1);

        $this->inventory->adjust($book->id, -6);
        $this->assertSame(4, $this->stock($book)->quantity);

        try {
            $this->inventory->adjust($book->id, -1);
            $this->fail('Adjustment below reserved stock must be rejected.');
        } catch (InventoryException) {
        }

        $this->assertSame(4, $this->stock($book)->quantity);
        $this->assertLedgerReconciles($book);
    }

    public function test_non_positive_quantities_are_rejected(): void
    {
        $book = $this->bookWithStock(10);

        foreach ([fn () => $this->inventory->receive($book->id, 0), fn () => $this->inventory->reserve([$book->id => 0], 'order', 1), fn () => $this->inventory->reserve([], 'order', 1), fn () => $this->inventory->adjust($book->id, 0)] as $call) {
            try {
                $call();
                $this->fail('Expected InventoryException');
            } catch (InventoryException) {
            }
        }

        $this->assertLedgerReconciles($book);
    }
}
