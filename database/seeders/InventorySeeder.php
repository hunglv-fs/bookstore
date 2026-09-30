<?php

namespace Database\Seeders;

use App\Models\Book;
use App\Models\Inventory;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    /**
     * One inventory row per book. Stock goes through InventoryService so each
     * unit is backed by a purchase entry and the ledger reconciles. About one
     * book in ten is out of stock, to exercise the insufficient-stock paths.
     */
    public function run(InventoryService $inventory): void
    {
        Book::query()->orderBy('id')->pluck('id')->each(function (int $bookId, int $i) use ($inventory) {
            if ($i % 10 === 9) {
                Inventory::query()->create(['book_id' => $bookId]);

                return;
            }

            $inventory->receive($bookId, fake()->numberBetween(5, 50));
        });
    }
}
