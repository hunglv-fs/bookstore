<?php

namespace Tests\Feature\Inventory;

use App\Exceptions\InsufficientStockException;
use App\Models\Book;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StockInCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_books_show_available_stock(): void
    {
        $inStock = Book::factory()->create();
        app(InventoryService::class)->receive($inStock->id, 5);
        app(InventoryService::class)->reserve([$inStock->id => 2], 'order', 1);
        $noInventory = Book::factory()->create();

        $this->getJson("/api/books/{$inStock->id}")
            ->assertJsonPath('data.available_quantity', 3)
            ->assertJsonPath('data.in_stock', true);

        $this->getJson("/api/books/{$noInventory->id}")
            ->assertJsonPath('data.available_quantity', 0)
            ->assertJsonPath('data.in_stock', false);

        $this->getJson('/api/books?sort=oldest')->assertJsonPath('data.0.available_quantity', fn ($v) => is_int($v));
    }

    public function test_insufficient_stock_renders_as_422_with_details(): void
    {
        Route::get('/api/_test/short', fn () => throw new InsufficientStockException([
            ['book_id' => 7, 'requested' => 3, 'available' => 1],
        ]));

        $this->getJson('/api/_test/short')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Insufficient stock')
            ->assertJsonPath('errors.book_id.0', 'The requested quantity is not available (book 7: requested 3, available 1).')
            ->assertJsonPath('shortages.0', ['book_id' => 7, 'requested' => 3, 'available' => 1]);
    }

    public function test_receive_command(): void
    {
        $book = Book::factory()->create(['title' => 'Clean Code']);

        $this->artisan('inventory:receive', ['book_id' => $book->id, 'quantity' => 12])
            ->expectsOutputToContain('"Clean Code": quantity 12, reserved 0, available 12.')
            ->assertSuccessful();

        $this->artisan('inventory:receive', ['book_id' => $book->id, 'quantity' => 0])->assertFailed();
    }
}
