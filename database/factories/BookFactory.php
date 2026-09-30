<?php

namespace Database\Factories;

use App\Enums\BookStatus;
use App\Models\Book;
use App\Models\Publisher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Book>
 */
class BookFactory extends Factory
{
    public function definition(): array
    {
        return [
            'isbn' => fake()->unique()->isbn13(),
            'title' => rtrim(fake()->sentence(3), '.'),
            'description' => fake()->paragraphs(2, true),
            // VND prices, rounded to 1.000 like real shelf prices.
            'price' => fake()->numberBetween(50, 500) * 1000,
            'publisher_id' => Publisher::factory(),
            'published_at' => fake()->dateTimeBetween('-10 years', 'now')->format('Y-m-d'),
            'status' => BookStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookStatus::Inactive,
        ]);
    }
}
