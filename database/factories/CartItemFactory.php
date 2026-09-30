<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'book_id' => Book::factory(),
            'quantity' => fake()->numberBetween(1, 3),
        ];
    }
}
