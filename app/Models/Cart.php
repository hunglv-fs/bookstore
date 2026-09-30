<?php

namespace App\Models;

use App\Enums\CartStatus;
use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => CartStatus::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }

    public function isActive(): bool
    {
        return $this->status === CartStatus::Active;
    }

    /**
     * Sum of line totals at current book prices. Informational only: the
     * order total is recalculated from locked book rows at checkout.
     */
    public function subtotal(): string
    {
        return $this->items->reduce(
            fn (string $sum, CartItem $item) => bcadd($sum, $item->lineTotal(), 2),
            '0.00'
        );
    }

    public function totalQuantity(): int
    {
        return (int) $this->items->sum('quantity');
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('carts.status', CartStatus::Active->value);
    }
}
