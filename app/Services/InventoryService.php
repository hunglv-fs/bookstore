<?php

namespace App\Services;

use App\Enums\InventoryTransactionType as Type;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InventoryException;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only place that changes inventory counters.
 *
 * Locking rules, shared by every method:
 * - Inventory rows are locked with SELECT ... FOR UPDATE *before* they are
 *   read for a decision, so the check ("is enough available?") and the write
 *   ("reserved_quantity += n") happen while no other transaction can change
 *   the same row. Locks are held until the surrounding transaction commits.
 * - Rows are always locked in ascending book_id order. Two checkouts touching
 *   books {A, B} and {B, A} therefore queue on A instead of deadlocking.
 * - Methods open DB::transaction(); when called inside a caller's transaction
 *   (checkout, cancel) this becomes a savepoint and the locks live until the
 *   caller commits, so stock and the order are committed or rolled back together.
 *
 * Reservation / release / sale are tied to a reference (e.g. an order).
 * Release and sale do not take quantities from the caller: they replay the
 * reference's own reservation entries, so they can only undo or settle
 * exactly what was reserved, and only once.
 */
class InventoryService
{
    /**
     * Receive stock into the warehouse (quantity += n). Creates the inventory row if needed.
     */
    public function receive(int $bookId, int $quantity, ?string $referenceType = null, ?int $referenceId = null): Inventory
    {
        if ($quantity <= 0) {
            throw new InventoryException('Received quantity must be positive.');
        }

        return DB::transaction(function () use ($bookId, $quantity, $referenceType, $referenceId) {
            Inventory::query()->firstOrCreate(['book_id' => $bookId]);
            $inventory = $this->lockRows([$bookId])->first();

            $inventory->increment('quantity', $quantity);
            $this->record($bookId, Type::Purchase, $quantity, $referenceType, $referenceId);

            return $inventory;
        });
    }

    /**
     * Manual correction of on-hand stock (quantity += delta, delta may be negative).
     */
    public function adjust(int $bookId, int $delta): Inventory
    {
        if ($delta === 0) {
            throw new InventoryException('Adjustment cannot be zero.');
        }

        return DB::transaction(function () use ($bookId, $delta) {
            $inventory = $this->lockRows([$bookId])->first()
                ?? throw new InventoryException("Book {$bookId} has no inventory record.");

            if ($inventory->quantity + $delta < $inventory->reserved_quantity) {
                throw new InventoryException(
                    "Cannot reduce stock of book {$bookId} below the {$inventory->reserved_quantity} units already reserved."
                );
            }

            $inventory->increment('quantity', $delta);
            $this->record($bookId, Type::Adjustment, $delta);

            return $inventory;
        });
    }

    /**
     * Reserve stock for a reference, all or nothing.
     *
     * @param  array<int, int>  $quantities  book_id => quantity
     *
     * @throws InsufficientStockException listing every book that is short
     */
    public function reserve(array $quantities, string $referenceType, int $referenceId): void
    {
        if ($quantities === [] || min($quantities) <= 0) {
            throw new InventoryException('Reserved quantities must be positive.');
        }

        DB::transaction(function () use ($quantities, $referenceType, $referenceId) {
            $this->ensureNotRecorded($referenceType, $referenceId, Type::Reservation);

            // 1. Lock the rows. 2. Check availability on the locked values.
            $rows = $this->lockRows(array_keys($quantities))->keyBy('book_id');

            $shortages = [];
            foreach ($quantities as $bookId => $quantity) {
                $available = $rows->get($bookId)?->available() ?? 0;
                if ($quantity > $available) {
                    $shortages[] = ['book_id' => $bookId, 'requested' => $quantity, 'available' => max($available, 0)];
                }
            }

            if ($shortages) {
                throw new InsufficientStockException($shortages);
            }

            // 3. Only now, still holding the locks, increase reserved_quantity.
            foreach ($quantities as $bookId => $quantity) {
                $rows[$bookId]->increment('reserved_quantity', $quantity);
                $this->record($bookId, Type::Reservation, $quantity, $referenceType, $referenceId);
            }
        });
    }

    /**
     * Give back everything reserved for the reference (order cancelled).
     */
    public function release(string $referenceType, int $referenceId): void
    {
        $this->settle($referenceType, $referenceId, Type::Release);
    }

    /**
     * Turn the reference's reservations into a sale (order delivered):
     * quantity -= n and reserved_quantity -= n.
     */
    public function completeSale(string $referenceType, int $referenceId): void
    {
        $this->settle($referenceType, $referenceId, Type::Sale);
    }

    private function settle(string $referenceType, int $referenceId, Type $type): void
    {
        DB::transaction(function () use ($referenceType, $referenceId, $type) {
            $reserved = InventoryTransaction::query()
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->where('transaction_type', Type::Reservation->value)
                ->pluck('quantity_change', 'book_id');

            if ($reserved->isEmpty()) {
                throw new InventoryException("Nothing is reserved for {$referenceType} {$referenceId}.");
            }

            $rows = $this->lockRows($reserved->keys()->all())->keyBy('book_id');

            // Checked after taking the locks: a concurrent release/sale of the same
            // reference has either committed (and is seen here) or is still waiting.
            $this->ensureNotRecorded($referenceType, $referenceId, Type::Release, Type::Sale);

            foreach ($reserved as $bookId => $quantity) {
                $inventory = $rows[$bookId];
                $inventory->reserved_quantity -= $quantity;
                if ($type === Type::Sale) {
                    $inventory->quantity -= $quantity;
                }
                $inventory->save();

                $this->record($bookId, $type, -$quantity, $referenceType, $referenceId);
            }
        });
    }

    /**
     * SELECT ... FOR UPDATE on the inventory rows of the given books, in
     * ascending book_id order (see class doc).
     *
     * @param  list<int>  $bookIds
     * @return Collection<int, Inventory>
     */
    private function lockRows(array $bookIds): Collection
    {
        return Inventory::query()
            ->whereIn('book_id', $bookIds)
            ->orderBy('book_id')
            ->lockForUpdate()
            ->get();
    }

    private function ensureNotRecorded(string $referenceType, int $referenceId, Type ...$types): void
    {
        $exists = InventoryTransaction::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->whereIn('transaction_type', array_map(fn (Type $t) => $t->value, $types))
            ->exists();

        if ($exists) {
            $names = implode('/', array_map(fn (Type $t) => $t->value, $types));
            throw new InventoryException("Stock for {$referenceType} {$referenceId} was already processed ({$names}).");
        }
    }

    private function record(int $bookId, Type $type, int $change, ?string $referenceType = null, ?int $referenceId = null): void
    {
        InventoryTransaction::query()->create([
            'book_id' => $bookId,
            'transaction_type' => $type,
            'quantity_change' => $change,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);
    }
}
