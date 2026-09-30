<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\BookImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookImage>
 */
class BookImageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'book_id' => Book::factory(),
            'image_url' => 'https://picsum.photos/seed/'.fake()->uuid().'/400/600',
            'is_thumbnail' => false,
            'sort_order' => 0,
        ];
    }

    public function thumbnail(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_thumbnail' => true,
        ]);
    }
}
