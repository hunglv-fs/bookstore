<?php

namespace App\Models;

use App\Enums\InventoryTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only stock ledger entry.
 */
class InventoryTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'book_id',
        'transaction_type',
        'quantity_change',
        'reference_type',
        'reference_id',
    ];

    protected function casts(): array
    {
        return [
            'transaction_type' => InventoryTransactionType::class,
            'quantity_change' => 'integer',
            'reference_id' => 'integer',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
