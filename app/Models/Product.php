<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A product in one store's catalog (DUMS-35). Products are deactivated
 * rather than deleted so later purchase records can keep referencing them.
 *
 * @property string $normalized_name Trimmed, lowercased name used for duplicate detection and search.
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * The store and status are set explicitly by server-side code, never
     * through mass assignment. `normalized_name` follows `name`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'unit_price',
        'description',
    ];

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
        ];
    }

    /**
     * Names are stored with surrounding and repeated spaces removed, and
     * `normalized_name` is kept in step so "Coke" and " coke " collide.
     */
    public static function normalizeName(string $name): string
    {
        return Str::lower(Str::squish($name));
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): array => [
                'name' => Str::squish($value),
                'normalized_name' => self::normalizeName($value),
            ],
        );
    }

    /**
     * Case-insensitive "name contains" search. Percent and underscore are
     * matched literally; `!` is the escape character because MySQL, MariaDB,
     * and SQLite all accept it.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeWhereNameContains(Builder $query, string $search): void
    {
        $escapedSearch = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], self::normalizeName($search));

        $query->whereRaw("normalized_name like ? escape '!'", ['%'.$escapedSearch.'%']);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
