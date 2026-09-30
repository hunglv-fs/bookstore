<?php

namespace Database\Factories;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'status' => CartStatus::Active,
        ];
    }

    public function converted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CartStatus::Converted,
        ]);
    }
}
