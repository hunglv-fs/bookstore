<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\InventoryService;
use Illuminate\Console\Command;

/**
 * Stock intake until an admin inventory API exists (deferred past the MVP).
 */
class ReceiveStock extends Command
{
    protected $signature = 'inventory:receive {book_id : Book id} {quantity : Units received (> 0)}';

    protected $description = 'Receive stock for a book (records a purchase inventory transaction)';

    public function handle(InventoryService $inventory): int
    {
        $book = Book::find($this->argument('book_id'));
        $quantity = (int) $this->argument('quantity');

        if (! $book || $quantity <= 0) {
            $this->error('Unknown book or non-positive quantity.');

            return self::FAILURE;
        }

        $row = $inventory->receive($book->id, $quantity);
        $this->info("\"{$book->title}\": quantity {$row->quantity}, reserved {$row->reserved_quantity}, available {$row->available()}.");

        return self::SUCCESS;
    }
}
