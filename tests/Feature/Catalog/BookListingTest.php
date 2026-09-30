<?php

namespace Tests\Feature\Catalog;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookImage;
use App\Models\Category;
use App\Models\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookListingTest extends TestCase
{
    use RefreshDatabase;

    private function ids(string $query = ''): array
    {
        return array_column($this->getJson('/api/books'.$query)->assertOk()->json('data'), 'id');
    }

    public function test_lists_active_books_with_pagination_and_relations(): void
    {
        $book = Book::factory()->create(['price' => 250000]);
        $book->authors()->attach(Author::factory()->create());
        $book->categories()->attach(Category::factory()->create());
        BookImage::factory()->thumbnail()->for($book)->create();
        Book::factory()->inactive()->create();

        $this->getJson('/api/books')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $book->id)
            ->assertJsonPath('data.0.price', '250000.00')
            ->assertJsonStructure([
                'data' => [['id', 'isbn', 'title', 'price', 'status', 'publisher' => ['id', 'name'], 'authors' => [['id', 'name']], 'categories' => [['id', 'name']], 'thumbnail' => ['image_url']]],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ])
            ->assertJsonPath('meta.total', 1);
    }

    public function test_per_page_and_page(): void
    {
        Book::factory()->count(5)->create();

        $this->getJson('/api/books?per_page=2&page=3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_keyword_matches_title_case_insensitively_or_exact_isbn(): void
    {
        $laravel = Book::factory()->create(['title' => 'Laravel in Action']);
        $isbnHit = Book::factory()->create(['title' => 'Something Else', 'isbn' => '9781234567897']);
        Book::factory()->create(['title' => 'Vue.js Essentials']);

        $this->assertSame([$laravel->id], $this->ids('?keyword=LARAVEL'));
        $this->assertSame([$isbnHit->id], $this->ids('?keyword=9781234567897'));
    }

    public function test_keyword_wildcards_are_treated_literally(): void
    {
        Book::factory()->create(['title' => 'Plain title']);
        $percent = Book::factory()->create(['title' => '100% Clean Code']);

        $this->assertSame([], $this->ids('?keyword=_lain'));
        $this->assertSame([$percent->id], $this->ids('?keyword='.urlencode('0%')));
    }

    public function test_filter_by_category_includes_sub_categories(): void
    {
        $programming = Category::factory()->create(['name' => 'Programming']);
        $backend = Category::factory()->childOf($programming)->create(['name' => 'Backend']);
        $novels = Category::factory()->create(['name' => 'Novels']);

        $inRoot = Book::factory()->create();
        $inRoot->categories()->attach($programming);
        $inChild = Book::factory()->create();
        $inChild->categories()->attach($backend);
        Book::factory()->create()->categories()->attach($novels);

        $this->assertEqualsCanonicalizing([$inRoot->id, $inChild->id], $this->ids("?category_id={$programming->id}"));
        $this->assertSame([$inChild->id], $this->ids("?category_id={$backend->id}"));
    }

    public function test_filter_by_author_and_publisher(): void
    {
        $author = Author::factory()->create();
        $publisher = Publisher::factory()->create();

        $byAuthor = Book::factory()->create();
        $byAuthor->authors()->attach([$author->id, Author::factory()->create()->id]);
        $byPublisher = Book::factory()->for($publisher)->create();
        Book::factory()->create();

        $this->assertSame([$byAuthor->id], $this->ids("?author_id={$author->id}"));
        $this->assertSame([$byPublisher->id], $this->ids("?publisher_id={$publisher->id}"));
    }

    public function test_filter_by_price_range_is_inclusive(): void
    {
        $cheap = Book::factory()->create(['price' => 50000]);
        $mid = Book::factory()->create(['price' => 100000]);
        $expensive = Book::factory()->create(['price' => 300000]);

        $this->assertEqualsCanonicalizing([$mid->id, $expensive->id], $this->ids('?min_price=100000'));
        $this->assertEqualsCanonicalizing([$cheap->id, $mid->id], $this->ids('?max_price=100000'));
        $this->assertSame([$mid->id], $this->ids('?min_price=100000&max_price=100000'));
    }

    public function test_sorting(): void
    {
        $b = Book::factory()->create(['price' => 200000, 'title' => 'B', 'published_at' => '2020-01-01']);
        $a = Book::factory()->create(['price' => 300000, 'title' => 'A', 'published_at' => '2024-01-01']);
        $c = Book::factory()->create(['price' => 100000, 'title' => 'C', 'published_at' => null]);

        $this->assertSame([$c->id, $b->id, $a->id], $this->ids('?sort=price_asc'));
        $this->assertSame([$a->id, $b->id, $c->id], $this->ids('?sort=price_desc'));
        $this->assertSame([$a->id, $b->id, $c->id], $this->ids('?sort=title_asc'));
        // Default: newest first, books without a publish date last.
        $this->assertSame([$a->id, $b->id, $c->id], $this->ids());
    }

    public function test_filters_combine(): void
    {
        $category = Category::factory()->create();
        $match = Book::factory()->create(['title' => 'Laravel Deep Dive', 'price' => 200000]);
        $match->categories()->attach($category);
        $tooExpensive = Book::factory()->create(['title' => 'Laravel Cookbook', 'price' => 900000]);
        $tooExpensive->categories()->attach($category);

        $this->assertSame([$match->id], $this->ids("?keyword=laravel&category_id={$category->id}&max_price=500000&sort=price_asc"));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->getJson('/api/books?sort=random&per_page=1000&min_price=-1&category_id=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort', 'per_page', 'min_price', 'category_id']);

        $this->getJson('/api/books?min_price=200&max_price=100')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['max_price']);
    }

    public function test_listing_query_count_does_not_grow_with_page_size(): void
    {
        $authors = Author::factory()->count(3)->create();
        $category = Category::factory()->create();

        Book::factory()->count(20)->create()->each(function (Book $book) use ($authors, $category) {
            $book->authors()->attach($authors->random(2));
            $book->categories()->attach($category);
            BookImage::factory()->thumbnail()->for($book)->create();
        });

        DB::enableQueryLog();
        $this->getJson('/api/books?per_page=20')->assertOk()->assertJsonCount(20, 'data');

        // count + page + publisher + authors + categories + thumbnail + inventory, regardless of row count.
        $this->assertLessThanOrEqual(7, count(DB::getQueryLog()));
    }
}
