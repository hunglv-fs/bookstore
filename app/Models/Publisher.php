<?php

namespace App\Models;

use Database\Factories\PublisherFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Publisher extends Model
{
    /** @use HasFactory<PublisherFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'address',
        'website',
    ];

    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }
}
