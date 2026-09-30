<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\Inventory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only: writes counters directly, without ledger entries.
 * Use InventoryService::receive() when the ledger must reconcile.
 *
 * @extends Factory<Inventory>
 */
class InventoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'book_id' => Book::factory(),
            'quantity' => 10,
            'reserved_quantity' => 0,
        ];
    }
}
