<?php

namespace Tests\Feature\Catalog;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookImage;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_book_with_all_images_in_order(): void
    {
        $book = Book::factory()->create(['price' => 125000.5]);
        $book->authors()->attach(Author::factory()->create(['name' => 'Robert Martin']));
        $book->categories()->attach(Category::factory()->create());
        $second = BookImage::factory()->for($book)->create(['sort_order' => 2]);
        $first = BookImage::factory()->thumbnail()->for($book)->create(['sort_order' => 1]);

        $this->getJson("/api/books/{$book->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $book->id)
            ->assertJsonPath('data.price', '125000.50')
            ->assertJsonPath('data.authors.0.name', 'Robert Martin')
            ->assertJsonPath('data.thumbnail.id', $first->id)
            ->assertJsonPath('data.images.0.id', $first->id)
            ->assertJsonPath('data.images.1.id', $second->id)
            ->assertJsonPath('data.publisher.id', $book->publisher_id);
    }

    public function test_inactive_book_is_not_found(): void
    {
        $book = Book::factory()->inactive()->create();

        $this->getJson("/api/books/{$book->id}")
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    public function test_missing_or_malformed_id_is_not_found(): void
    {
        $this->getJson('/api/books/999999')->assertNotFound();
        $this->getJson('/api/books/abc')->assertNotFound();
        $this->getJson('/api/books/99999999999999999999')->assertNotFound();
    }
}
