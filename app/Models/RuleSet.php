<?php

namespace App\Models;

use Database\Factories\RuleSetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One training run of the single-item association rule engine (DUMS-39).
 *
 * Lifecycle: building → validated → active → superseded, or building →
 * failed / insufficient_data. Only one rule set per scope can be active:
 * `active_scope_key` is set only while active and is unique. External
 * experiments stop at validated and are never activated.
 *
 * Created and updated only by AssociationRuleTrainer and RuleSetActivator.
 */
class RuleSet extends Model
{
    /** @use HasFactory<RuleSetFactory> */
    use HasFactory;

    public const SCOPE_STORE = 'store';

    public const SCOPE_EXTERNAL_EXPERIMENT = 'external_experiment';

    public const SOURCE_INTERNAL_PURCHASES = 'internal_purchases';

    public const SOURCE_EXTERNAL_DATASET = 'external_dataset';

    public const ALGORITHM = 'single_item_pair_counting';

    public const ALGORITHM_VERSION = '1.0';

    public const STATUS_BUILDING = 'building';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_INSUFFICIENT_DATA = 'insufficient_data';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'source_cutoff_purchase_id' => 'integer',
            'eligible_basket_count' => 'integer',
            'distinct_item_count' => 'integer',
            'candidate_pair_count' => 'integer',
            'rule_count' => 'integer',
            'duration_ms' => 'integer',
            'source_details' => 'array',
            'parameters' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'activated_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public static function storeScopeKey(int $storeId): string
    {
        return self::SCOPE_STORE.':'.$storeId.':'.self::SOURCE_INTERNAL_PURCHASES;
    }

    public static function externalScopeKey(string $sha256): string
    {
        return self::SCOPE_EXTERNAL_EXPERIMENT.':'.$sha256;
    }

    /**
     * The active rule set of one store's internal purchase model.
     *
     * @param  Builder<RuleSet>  $query
     */
    public function scopeActiveForStore(Builder $query, int $storeId): void
    {
        $query->where('active_scope_key', self::storeScopeKey($storeId))
            ->where('scope_type', self::SCOPE_STORE)
            ->where('store_id', $storeId)
            ->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(AssociationRule::class);
    }
}
