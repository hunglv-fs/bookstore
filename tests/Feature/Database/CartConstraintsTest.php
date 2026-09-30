<?php

namespace Tests\Feature\Database;

use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CartConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_item_quantity_must_be_positive(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('cart_items_quantity_positive');

        CartItem::factory()->create(['quantity' => 0]);
    }

    public function test_book_appears_once_per_cart(): void
    {
        $item = CartItem::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        CartItem::factory()->create(['cart_id' => $item->cart_id, 'book_id' => $item->book_id]);
    }

    public function test_customer_has_at_most_one_active_cart_but_many_converted(): void
    {
        $cart = Cart::factory()->create();
        Cart::factory()->converted()->count(2)->create(['customer_id' => $cart->customer_id]);

        $this->expectException(UniqueConstraintViolationException::class);
        Cart::factory()->create(['customer_id' => $cart->customer_id]);
    }

    public function test_cart_status_must_be_known(): void
    {
        $cart = Cart::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('carts_status_valid');
        DB::table('carts')->where('id', $cart->id)->update(['status' => 'abandoned']);
    }
}
