<?php

namespace Tests\Concerns;

use App\Models\Book;
use App\Models\CustomerAddress;
use App\Models\User;
use App\Services\CartService;
use App\Services\InventoryService;

trait BuildsShoppingData
{
    protected function customerUser(): User
    {
        return User::factory()->withCustomer()->create();
    }

    /**
     * The customer's default address, created on first use (a customer may
     * have only one default address).
     */
    protected function addressFor(User $user): CustomerAddress
    {
        return $user->customer->addresses()->where('is_default', true)->first()
            ?? CustomerAddress::factory()->default()->create(['customer_id' => $user->customer->id]);
    }

    protected function bookWithStock(int $stock, string|int|float $price = 100000): Book
    {
        $book = Book::factory()->create(['price' => $price]);
        if ($stock > 0) {
            app(InventoryService::class)->receive($book->id, $stock);
        }

        return $book;
    }

    /**
     * @param  array<int, int>  $quantities  book_id => quantity
     */
    protected function fillCart(User $user, array $quantities): void
    {
        foreach ($quantities as $bookId => $quantity) {
            app(CartService::class)->addItem($user->customer, $bookId, $quantity);
        }
    }
}
