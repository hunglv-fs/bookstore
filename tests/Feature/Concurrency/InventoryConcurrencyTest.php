<?php

namespace Tests\Feature\Concurrency;

use App\Enums\InventoryTransactionType;
use App\Models\Book;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RunsConcurrentWorkers;
use Tests\TestCase;

/**
 * Overselling must be impossible when several checkouts reserve the same
 * stock at the same time. Each worker is a separate process/connection.
 */
class InventoryConcurrencyTest extends TestCase
{
    use DatabaseTruncation, RunsConcurrentWorkers;

    /** Roles are reference data inserted by their migration. */
    protected $exceptTables = ['roles'];

    protected function tearDown(): void
    {
        // DatabaseTruncation only cleans up before a test; this test commits
        // data, so clean up afterwards too for the transactional tests that follow.
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    private function lockInventoryOf(Book ...$books): callable
    {
        return fn () => Inventory::query()
            ->whereIn('book_id', array_map(fn (Book $b) => $b->id, $books))
            ->lockForUpdate()
            ->get();
    }

    public function test_two_customers_racing_for_the_last_copy_only_one_wins(): void
    {
        $book = Book::factory()->create();
        app(InventoryService::class)->receive($book->id, 1);

        $outcomes = $this->raceWorkers($this->lockInventoryOf($book), [
            ['reserve', json_encode([$book->id => 1]), '1'],
            ['reserve', json_encode([$book->id => 1]), '2'],
        ]);

        sort($outcomes);
        $this->assertSame(['insufficient', 'ok'], $outcomes);

        $inventory = Inventory::where('book_id', $book->id)->first();
        $this->assertSame(1, $inventory->quantity);
        $this->assertSame(1, $inventory->reserved_quantity);
        $this->assertSame(1, InventoryTransaction::where('transaction_type', InventoryTransactionType::Reservation)->count());
    }

    public function test_many_concurrent_reservations_never_exceed_stock(): void
    {
        $book = Book::factory()->create();
        app(InventoryService::class)->receive($book->id, 5);

        $workers = array_map(fn (int $i) => ['reserve', json_encode([$book->id => 1]), (string) $i], range(1, 12));
        $outcomes = array_count_values($this->raceWorkers($this->lockInventoryOf($book), $workers));
        ksort($outcomes); // key order depends on which worker finished first

        $this->assertSame(['insufficient' => 7, 'ok' => 5], $outcomes);

        $inventory = Inventory::where('book_id', $book->id)->first();
        $this->assertSame(5, $inventory->reserved_quantity);
        $this->assertSame(0, $inventory->available());
        $this->assertSame(5, (int) InventoryTransaction::where('transaction_type', 'reservation')->sum('quantity_change'));
    }

    public function test_opposite_lock_order_does_not_deadlock(): void
    {
        [$a, $b] = Book::factory()->count(2)->create();
        app(InventoryService::class)->receive($a->id, 100);
        app(InventoryService::class)->receive($b->id, 100);

        // Half the workers ask for (A, B), the other half for (B, A).
        $workers = array_map(fn (int $i) => [
            'reserve',
            json_encode($i % 2 ? [$a->id => 1, $b->id => 1] : [$b->id => 1, $a->id => 1]),
            (string) $i,
        ], range(1, 8));

        $outcomes = $this->raceWorkers($this->lockInventoryOf($a, $b), $workers);

        $this->assertSame(array_fill(0, 8, 'ok'), $outcomes, 'Outcomes: '.implode(' | ', $outcomes));
        $this->assertSame([8, 8], DB::table('inventory')->orderBy('book_id')->pluck('reserved_quantity')->all());
    }
}
