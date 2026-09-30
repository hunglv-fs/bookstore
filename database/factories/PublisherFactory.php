<?php

namespace Database\Factories;

use App\Models\Publisher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Publisher>
 */
class PublisherFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Publishing',
            'address' => fake()->address(),
            'website' => fake()->url(),
        ];
    }
}
