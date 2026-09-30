<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * Recursive CTE returning the given category id plus all of its descendants.
     * UNION (not UNION ALL) de-duplicates rows, so a corrupted cycle cannot loop forever.
     */
    public const SUBTREE_IDS_SQL = <<<'SQL'
        WITH RECURSIVE subtree(id) AS (
            SELECT id FROM categories WHERE id = ?
            UNION
            SELECT c.id FROM categories c JOIN subtree s ON c.parent_id = s.id
        )
        SELECT id FROM subtree
        SQL;

    protected $fillable = [
        'name',
        'description',
        'parent_id',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('name');
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_categories');
    }
}
