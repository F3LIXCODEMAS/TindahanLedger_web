<?php

namespace App\Models;

use Database\Factories\AssociationRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One single-item rule A → B within a rule set, with the counts behind its
 * metrics so every value can be audited. Store rules reference catalog
 * products; external experiment rules use normalized labels and no store.
 * Rules are written in bulk by AssociationRuleTrainer and never edited.
 */
class AssociationRule extends Model
{
    /** @use HasFactory<AssociationRuleFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'antecedent_product_id' => 'integer',
            'consequent_product_id' => 'integer',
            'antecedent_count' => 'integer',
            'consequent_count' => 'integer',
            'pair_count' => 'integer',
            'support' => 'decimal:6',
            'confidence' => 'decimal:6',
            'lift' => 'decimal:6',
        ];
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class);
    }

    public function antecedentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'antecedent_product_id');
    }

    public function consequentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'consequent_product_id');
    }
}
