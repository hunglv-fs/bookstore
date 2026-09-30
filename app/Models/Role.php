<?php

namespace App\Models;

use App\Enums\RoleName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'name' => RoleName::class,
        ];
    }

    public static function idFor(RoleName $name): int
    {
        return static::query()->where('name', $name->value)->valueOrFail('id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
