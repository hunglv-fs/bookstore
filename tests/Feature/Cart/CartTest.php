<?php

namespace Tests\Feature\Cart;

use App\Enums\CartStatus;
use App\Models\Book;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withCustomer()->create();
        Sanctum::actingAs($this->user);
    }

    private function addToCart(Book $book, int $quantity = 1)
    {
        return $this->postJson('/api/cart/items', ['book_id' => $book->id, 'quantity' => $quantity]);
    }

    public function test_get_cart_returns_an_empty_active_cart_and_reuses_it(): void
    {
        $first = $this->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.total_quantity', 0)
            ->assertJsonPath('data.subtotal', '0.00')
            ->json('data.id');

        $this->assertSame($first, $this->getJson('/api/cart')->json('data.id'));
        $this->assertSame(1, Cart::count());
    }

    public function test_add_item(): void
    {
        $book = Book::factory()->create(['price' => 120000]);

        $this->addToCart($book, 2)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.book.id', $book->id)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_price', '120000.00')
            ->assertJsonPath('data.items.0.line_total', '240000.00')
            ->assertJsonPath('data.items.0.is_available', true)
            ->assertJsonPath('data.total_quantity', 2)
            ->assertJsonPath('data.subtotal', '240000.00');

        $this->assertDatabaseHas('cart_items', ['book_id' => $book->id, 'quantity' => 2]);
    }

    public function test_adding_the_same_book_twice_merges_into_one_line(): void
    {
        $book = Book::factory()->create();

        $this->addToCart($book, 2)->assertOk();
        $this->addToCart($book, 3)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 5);

        $this->assertSame(1, CartItem::count());
    }

    public function test_merged_quantity_cannot_exceed_the_limit(): void
    {
        $book = Book::factory()->create();
        $this->addToCart($book, 60)->assertOk();

        $this->addToCart($book, 41)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);

        $this->assertSame(60, CartItem::first()->quantity);
    }

    public function test_subtotal_sums_several_books_with_decimal_precision(): void
    {
        $this->addToCart(Book::factory()->create(['price' => 99999.99]), 3);
        $this->addToCart(Book::factory()->create(['price' => 0.01]), 1)
            ->assertJsonPath('data.subtotal', '299999.98')
            ->assertJsonPath('data.total_quantity', 4);
    }

    public function test_cannot_add_inactive_or_missing_book(): void
    {
        $this->addToCart(Book::factory()->inactive()->create())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['book_id']);

        $this->postJson('/api/cart/items', ['book_id' => 999999, 'quantity' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['book_id']);

        $this->assertSame(0, CartItem::count());
    }

    public function test_quantity_must_be_a_positive_integer(): void
    {
        $book = Book::factory()->create();

        foreach ([0, -1, 1.5, 'two', null, 101] as $quantity) {
            $this->postJson('/api/cart/items', ['book_id' => $book->id, 'quantity' => $quantity])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['quantity']);
        }
    }

    public function test_update_quantity(): void
    {
        $book = Book::factory()->create(['price' => 50000]);
        $itemId = $this->addToCart($book, 1)->json('data.items.0.id');

        $this->patchJson("/api/cart/items/{$itemId}", ['quantity' => 4])
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 4)
            ->assertJsonPath('data.subtotal', '200000.00');

        $this->patchJson("/api/cart/items/{$itemId}", ['quantity' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);
    }

    public function test_remove_item(): void
    {
        $keep = Book::factory()->create();
        $remove = Book::factory()->create();
        $this->addToCart($keep);
        $itemId = $this->addToCart($remove)->json('data.items.1.id');

        $this->deleteJson("/api/cart/items/{$itemId}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.book.id', $keep->id);

        $this->assertDatabaseMissing('cart_items', ['id' => $itemId]);
    }

    public function test_cannot_touch_another_customers_cart_item(): void
    {
        $otherItem = CartItem::factory()->create(['quantity' => 1]);

        $this->patchJson("/api/cart/items/{$otherItem->id}", ['quantity' => 5])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.']);
        $this->deleteJson("/api/cart/items/{$otherItem->id}")->assertNotFound();

        $this->assertSame(1, $otherItem->fresh()->quantity);
    }

    public function test_items_of_a_converted_cart_cannot_be_changed(): void
    {
        $cart = Cart::factory()->converted()->create(['customer_id' => $this->user->customer->id]);
        $item = CartItem::factory()->for($cart)->create(['quantity' => 1]);

        $this->patchJson("/api/cart/items/{$item->id}", ['quantity' => 2])->assertNotFound();
        $this->deleteJson("/api/cart/items/{$item->id}")->assertNotFound();
    }

    public function test_adding_after_checkout_goes_to_a_new_active_cart(): void
    {
        $old = Cart::factory()->converted()->create(['customer_id' => $this->user->customer->id]);

        $cartId = $this->addToCart(Book::factory()->create())->assertOk()->json('data.id');

        $this->assertNotSame($old->id, $cartId);
        $this->assertSame(CartStatus::Active, Cart::find($cartId)->status);
    }

    public function test_cart_flags_books_deactivated_after_being_added(): void
    {
        $book = Book::factory()->create();
        $this->addToCart($book);
        $book->update(['status' => 'inactive']);

        $this->getJson('/api/cart')->assertJsonPath('data.items.0.is_available', false);
    }
}
