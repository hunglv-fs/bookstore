<?php

namespace App\Models;

use App\Enums\BookStatus;
use Database\Factories\BookFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class Book extends Model
{
    /** @use HasFactory<BookFactory> */
    use HasFactory;

    /**
     * Allowed values of the "sort" filter => ORDER BY clauses.
     * Every option ends with id so pagination is stable when values tie.
     */
    public const SORTS = [
        'newest' => ['published_at DESC NULLS LAST', 'id DESC'],
        'oldest' => ['published_at ASC NULLS LAST', 'id ASC'],
        'price_asc' => ['price ASC', 'id ASC'],
        'price_desc' => ['price DESC', 'id DESC'],
        'title_asc' => ['title ASC', 'id ASC'],
    ];

    public const DEFAULT_SORT = 'newest';

    protected $fillable = [
        'isbn',
        'title',
        'description',
        'price',
        'publisher_id',
        'published_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'published_at' => 'date',
            'status' => BookStatus::class,
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class, 'book_authors');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'book_categories');
    }

    public function images(): HasMany
    {
        return $this->hasMany(BookImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class);
    }

    public function availableQuantity(): int
    {
        return $this->inventory?->available() ?? 0;
    }

    public function thumbnail(): HasOne
    {
        return $this->hasOne(BookImage::class)->where('is_thumbnail', true);
    }

    public function isActive(): bool
    {
        return $this->status === BookStatus::Active;
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('books.status', BookStatus::Active->value);
    }

    /**
     * Apply catalog filters. Everything is translated to SQL so filtering
     * happens in PostgreSQL, never on a collection in PHP.
     *
     * @param  array{keyword?: string, category_id?: int, author_id?: int, publisher_id?: int, min_price?: numeric, max_price?: numeric}  $filters
     */
    #[Scope]
    protected function filter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['keyword'] ?? null, function (Builder $q, string $keyword) {
                $pattern = '%'.addcslashes($keyword, '\\%_').'%';
                $q->where(fn (Builder $w) => $w
                    ->where('books.title', 'ILIKE', $pattern)
                    ->orWhere('books.isbn', $keyword));
            })
            // A parent category also matches books filed under any of its sub-categories.
            ->when($filters['category_id'] ?? null, fn (Builder $q, $categoryId) => $q
                ->whereExists(fn (QueryBuilder $sub) => $sub
                    ->select(DB::raw(1))
                    ->from('book_categories')
                    ->whereColumn('book_categories.book_id', 'books.id')
                    ->whereRaw('book_categories.category_id IN ('.Category::SUBTREE_IDS_SQL.')', [$categoryId])))
            ->when($filters['author_id'] ?? null, fn (Builder $q, $authorId) => $q
                ->whereExists(fn (QueryBuilder $sub) => $sub
                    ->select(DB::raw(1))
                    ->from('book_authors')
                    ->whereColumn('book_authors.book_id', 'books.id')
                    ->where('book_authors.author_id', $authorId)))
            ->when($filters['publisher_id'] ?? null, fn (Builder $q, $publisherId) => $q->where('books.publisher_id', $publisherId))
            ->when(isset($filters['min_price']), fn (Builder $q) => $q->where('books.price', '>=', $filters['min_price']))
            ->when(isset($filters['max_price']), fn (Builder $q) => $q->where('books.price', '<=', $filters['max_price']));
    }

    #[Scope]
    protected function sortBy(Builder $query, ?string $sort): void
    {
        foreach (self::SORTS[$sort] ?? self::SORTS[self::DEFAULT_SORT] as $clause) {
            $query->orderByRaw('books.'.$clause);
        }
    }
}
