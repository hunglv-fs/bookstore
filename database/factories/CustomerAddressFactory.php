<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerAddress>
 */
class CustomerAddressFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'receiver_name' => fake()->name(),
            'phone' => fake()->numerify('09########'),
            'address' => fake()->buildingNumber().' '.fake()->streetName(),
            'ward' => 'Ward '.fake()->numberBetween(1, 15),
            'district' => 'District '.fake()->numberBetween(1, 12),
            'city' => fake()->randomElement(['Ho Chi Minh City', 'Hanoi', 'Da Nang', 'Can Tho', 'Hue']),
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => ['is_default' => true]);
    }
}
