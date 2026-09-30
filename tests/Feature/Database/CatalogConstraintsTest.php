<?php

namespace Tests\Feature\Database;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookImage;
use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The database itself must reject invalid catalog data, independent of
 * application validation.
 */
class CatalogConstraintsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * PostgreSQL aborts the whole transaction after an error, so each statement
     * expected to fail runs inside its own savepoint (nested DB::transaction).
     *
     * @param  class-string<QueryException>  $exception
     */
    private function assertRejected(callable $statement, string $exception = QueryException::class, ?string $constraint = null): void
    {
        try {
            DB::transaction($statement);
        } catch (QueryException $e) {
            $this->assertInstanceOf($exception, $e);
            if ($constraint) {
                $this->assertStringContainsString($constraint, $e->getMessage());
            }

            return;
        }

        $this->fail('The database accepted a statement that should violate '.($constraint ?? 'a constraint').'.');
    }

    public function test_book_price_cannot_be_negative(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('books_price_non_negative');

        Book::factory()->create(['price' => -1]);
    }

    public function test_book_status_must_be_known(): void
    {
        $book = Book::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('books_status_valid');

        DB::table('books')->where('id', $book->id)->update(['status' => 'deleted']);
    }

    public function test_isbn_is_unique_and_well_formed(): void
    {
        Book::factory()->create(['isbn' => '9780132350884']);

        $this->assertRejected(fn () => Book::factory()->create(['isbn' => '9780132350884']), UniqueConstraintViolationException::class);
        $this->assertRejected(fn () => Book::factory()->create(['isbn' => 'ABC-123']), constraint: 'books_isbn_format');
        $this->assertRejected(fn () => Book::factory()->create(['isbn' => '97801323508']), constraint: 'books_isbn_format');

        Book::factory()->create(['isbn' => '080442957X']);
        $this->assertSame(2, Book::count());
    }

    public function test_book_author_pair_is_unique(): void
    {
        $book = Book::factory()->create();
        $author = Author::factory()->create();
        $book->authors()->attach($author);

        $this->expectException(UniqueConstraintViolationException::class);
        $book->authors()->attach($author);
    }

    public function test_book_category_pair_is_unique(): void
    {
        $book = Book::factory()->create();
        $category = Category::factory()->create();
        $book->categories()->attach($category);

        $this->expectException(UniqueConstraintViolationException::class);
        $book->categories()->attach($category);
    }

    public function test_only_one_thumbnail_per_book(): void
    {
        $book = Book::factory()->create();
        BookImage::factory()->thumbnail()->for($book)->create();
        BookImage::factory()->for($book)->create();

        $this->expectException(UniqueConstraintViolationException::class);
        BookImage::factory()->thumbnail()->for($book)->create();
    }

    public function test_category_cannot_be_its_own_parent_and_siblings_have_unique_names(): void
    {
        $root = Category::factory()->create(['name' => 'Programming']);

        $this->assertRejected(fn () => Category::factory()->create(['name' => 'Programming']), UniqueConstraintViolationException::class);

        // Same name under a different parent is fine.
        Category::factory()->childOf(Category::factory()->create())->create(['name' => 'Programming']);

        $this->assertRejected(fn () => $root->update(['parent_id' => $root->id]), constraint: 'categories_parent_not_self');
    }

    public function test_publisher_with_books_cannot_be_deleted(): void
    {
        $book = Book::factory()->create();

        $this->expectException(QueryException::class);
        $book->publisher->delete();
    }
}
