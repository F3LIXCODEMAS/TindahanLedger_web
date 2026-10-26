<?php

use App\Models\AssociationRule;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\RuleSet;
use App\Models\Store;
use App\Models\SukiPoint;
use App\Services\AssociationRuleGenerator;
use App\Services\AssociationRuleTrainer;
use App\Services\PurchaseBasketCounter;
use App\Services\RuleSetActivator;
use App\Support\AssociationRuleThresholds;
use App\Support\BasketCounts;
use App\Support\TrainingOutcome;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Thresholds that keep every co-occurring pair, so small fixtures produce
 * rules. `$overrides` use the config keys.
 *
 * @param  array<string, string|null>  $overrides
 */
function trainingThresholds(array $overrides = []): AssociationRuleThresholds
{
    return AssociationRuleThresholds::fromConfig([
        'min_support' => '0',
        'min_confidence' => '0',
        'min_lift' => 'off',
        'min_baskets' => '1',
        'min_antecedent_count' => '1',
        'min_pair_count' => '1',
        ...$overrides,
    ]);
}

function trainer(): AssociationRuleTrainer
{
    return app(AssociationRuleTrainer::class);
}

function ruleFor(RuleSet $ruleSet, Product $antecedent, Product $consequent): ?AssociationRule
{
    return $ruleSet->rules()->where('antecedent_product_id', $antecedent->id)->where('consequent_product_id', $consequent->id)->first();
}

describe('metrics', function () {
    it('saves the hand-calculated metrics and counts for both directions', function () {
        $store = Store::factory()->create();
        [$a, $b, $c] = recordFourBasketFixture($store);

        $outcome = trainer()->trainStore($store, trainingThresholds());

        expect($outcome->status)->toBe(RuleSet::STATUS_ACTIVE);
        expect($outcome->ruleSet)->toMatchArray([
            'eligible_basket_count' => 4,
            'distinct_item_count' => 3,
            'candidate_pair_count' => 3,
            'rule_count' => 6,
            'algorithm' => RuleSet::ALGORITHM,
        ]);
        expect(ruleFor($outcome->ruleSet, $a, $b)->only(['antecedent_count', 'consequent_count', 'pair_count', 'support', 'confidence', 'lift']))->toBe([
            'antecedent_count' => 3,
            'consequent_count' => 3,
            'pair_count' => 2,
            'support' => '0.500000',
            'confidence' => '0.666667',
            'lift' => '0.888889',
        ]);
        expect(ruleFor($outcome->ruleSet, $b, $a)->only(['antecedent_count', 'pair_count', 'confidence']))->toBe(['antecedent_count' => 3, 'pair_count' => 2, 'confidence' => '0.666667']);
        expect(ruleFor($outcome->ruleSet, $a, $c)->confidence)->toBe('0.333333');
        expect(ruleFor($outcome->ruleSet, $c, $a)->confidence)->toBe('0.500000');
        expect($outcome->ruleSet->rules()->whereColumn('antecedent_product_id', 'consequent_product_id')->exists())->toBeFalse();
    });

    it('applies the thresholds before saving rules', function () {
        $store = Store::factory()->create();
        [$a, $b, $c] = recordFourBasketFixture($store);

        // Only A → B and B → A (2/3) reach confidence 0.6; the rules from and to C have 1/3 or 1/2.
        $outcome = trainer()->trainStore($store, trainingThresholds(['min_confidence' => '0.6']));

        expect($outcome->ruleSet->rules()->count())->toBe(2);
        expect(ruleFor($outcome->ruleSet, $a, $b))->not->toBeNull();
        expect(ruleFor($outcome->ruleSet, $b, $a))->not->toBeNull();
        expect(ruleFor($outcome->ruleSet, $c, $a))->toBeNull();
    });
});

describe('basket eligibility', function () {
    it('counts a product once per basket however large its quantity', function () {
        $store = Store::factory()->create();
        [$a, $b] = Product::factory()->count(2)->for($store)->create()->all();
        recordBaskets($store, [[$a, $b], [$a]]);
        PurchaseItem::query()->where('product_id', $a->id)->update(['quantity' => '25.000']);

        $outcome = trainer()->trainStore($store, trainingThresholds());

        expect(ruleFor($outcome->ruleSet, $a, $b)->only(['antecedent_count', 'consequent_count', 'pair_count']))->toBe(['antecedent_count' => 2, 'consequent_count' => 1, 'pair_count' => 1]);
    });

    it('cannot store the same product twice in one basket', function () {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create();
        recordBaskets($store, [[$product]]);

        expect(fn () => PurchaseItem::factory()->create(['purchase_id' => Purchase::query()->value('id'), 'product_id' => $product->id]))
            ->toThrow(QueryException::class);
    });

    it('counts single-item baskets in the total but they form no pair', function () {
        $store = Store::factory()->create();
        recordBaskets($store, [[Product::factory()->for($store)->create()]]);

        $outcome = trainer()->trainStore($store, trainingThresholds());

        expect($outcome->status)->toBe(RuleSet::STATUS_ACTIVE);
        expect($outcome->ruleSet)->toMatchArray(['eligible_basket_count' => 1, 'candidate_pair_count' => 0, 'rule_count' => 0]);
    });

    it('records insufficient data for a store with no purchases', function () {
        $outcome = trainer()->trainStore(Store::factory()->create(), trainingThresholds());

        expect($outcome->status)->toBe(RuleSet::STATUS_INSUFFICIENT_DATA);
        expect($outcome->ruleSet->fresh())->toMatchArray(['eligible_basket_count' => 0, 'rule_count' => null, 'active_scope_key' => null]);
        expect($outcome->reason)->toContain('Only 0 eligible baskets');
    });

    it('excludes purchases with no items, incomplete utang, unknown types, unresolved products, and zero quantities', function () {
        $store = Store::factory()->create();
        [$a, $b] = Product::factory()->count(2)->for($store)->create()->all();
        Purchase::factory()->cash()->create(['store_id' => $store->id]);
        recordBaskets($store, [[$a, $b], [$a, $b], [$a, $b]]);
        $purchases = Purchase::query()->whereHas('items')->orderBy('id')->get();
        $purchases[0]->forceFill(['purchase_type' => Purchase::TYPE_UTANG])->save();
        $purchases[1]->forceFill(['purchase_type' => 'draft'])->save();
        PurchaseItem::query()->where('purchase_id', $purchases[2]->id)->where('product_id', $b->id)->update(['quantity' => 0]);

        // The foreign keys forbid an unresolvable product, so check the query's own guard by
        // deferring SQLite's foreign key check to a commit that the test transaction never reaches.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        $orphan = Purchase::factory()->cash()->create(['store_id' => $store->id]);
        DB::table('purchase_items')->insert([
            'purchase_id' => $orphan->id, 'store_id' => $store->id, 'product_id' => 999_999,
            'product_name_snapshot' => 'Deleted product', 'quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $outcome = trainer()->trainStore($store, trainingThresholds());

        // Only the third basket remains, holding A alone.
        expect($outcome->ruleSet)->toMatchArray(['eligible_basket_count' => 1, 'distinct_item_count' => 1, 'rule_count' => 0]);
    });

    it('includes complete utang credits and never treats payments as baskets', function () {
        $store = Store::factory()->create();
        [$a, $b] = Product::factory()->count(2)->for($store)->create()->all();
        $customer = Customer::factory()->create(['store_id' => $store->id]);
        $credit = LedgerEntry::factory()->create(['customer_id' => $customer->id]);
        $purchase = Purchase::factory()->create(['ledger_entry_id' => $credit->id]);
        PurchaseItem::factory()->create(['purchase_id' => $purchase->id, 'product_id' => $a->id]);
        PurchaseItem::factory()->create(['purchase_id' => $purchase->id, 'product_id' => $b->id]);
        Payment::factory()->count(3)->create(['ledger_entry_id' => $credit->id]);

        $outcome = trainer()->trainStore($store, trainingThresholds());

        expect($outcome->ruleSet)->toMatchArray(['eligible_basket_count' => 1, 'rule_count' => 2]);
    });

    it('reads every count and the cutoff inside one transaction', function () {
        $store = Store::factory()->create();
        recordFourBasketFixture($store);
        $outerLevel = DB::transactionLevel();
        $levels = [];
        DB::listen(function ($query) use (&$levels): void {
            if (str_contains($query->sql, 'purchase')) {
                $levels[] = DB::transactionLevel();
            }
        });

        $snapshot = app(PurchaseBasketCounter::class)->count($store->id, 1);

        expect($levels)->toHaveCount(4)->each->toBe($outerLevel + 1);
        expect($snapshot['cutoff_purchase_id'])->toBe(Purchase::query()->max('id'));
        expect($snapshot['counts']->basketCount)->toBe(4);
    });

    it('reads the recomputed model counts inside one transaction', function () {
        $store = Store::factory()->create();
        recordFourBasketFixture($store);
        $outerLevel = DB::transactionLevel();
        $levels = [];
        DB::listen(function ($query) use (&$levels): void {
            if (str_contains($query->sql, 'purchase_items')) {
                $levels[] = DB::transactionLevel();
            }
        });

        app(PurchaseBasketCounter::class)->countUpTo($store->id, (int) Purchase::query()->max('id'), 1);

        expect($levels)->toHaveCount(3)->each->toBe($outerLevel + 1);
    });

    it('fixes the purchase cutoff when training starts', function () {
        $store = Store::factory()->create();
        recordFourBasketFixture($store);

        $outcome = trainer()->trainStore($store, trainingThresholds());

        expect($outcome->ruleSet->source_cutoff_purchase_id)->toBe(Purchase::query()->where('store_id', $store->id)->max('id'));
    });
});

describe('store isolation', function () {
    it('trains each store only on its own purchases', function () {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        recordFourBasketFixture($storeA);
        recordFourBasketFixture($storeB);
        recordBaskets($storeB, [[Product::factory()->for($storeB)->create()]]);

        $outcomeA = trainer()->trainStore($storeA, trainingThresholds());
        $outcomeB = trainer()->trainStore($storeB, trainingThresholds());

        expect($outcomeA->ruleSet->eligible_basket_count)->toBe(4);
        expect($outcomeB->ruleSet->eligible_basket_count)->toBe(5);
        expect($outcomeA->ruleSet->rules()->pluck('store_id')->unique()->all())->toBe([$storeA->id]);
        expect(AssociationRule::query()->where('rule_set_id', $outcomeA->ruleSet->id)
            ->whereIn('antecedent_product_id', Product::query()->where('store_id', $storeB->id)->select('id'))->exists())->toBeFalse();
        expect(RuleSet::query()->where('status', RuleSet::STATUS_ACTIVE)->count())->toBe(2);
    });

    it('rejects a rule that points at another store\'s product', function () {
        $ruleSet = RuleSet::factory()->create();
        $foreignProduct = Product::factory()->create();

        expect(fn () => AssociationRule::factory()->create(['rule_set_id' => $ruleSet->id, 'antecedent_product_id' => $foreignProduct->id]))
            ->toThrow(QueryException::class);
    });
});

describe('versioning', function () {
    it('supersedes the previous version when a new one is activated', function () {
        $store = Store::factory()->create();
        recordFourBasketFixture($store);
        $first = trainer()->trainStore($store, trainingThresholds())->ruleSet;

        $second = trainer()->trainStore($store, trainingThresholds())->ruleSet;

        expect($first->fresh())->toMatchArray(['status' => RuleSet::STATUS_SUPERSEDED, 'active_scope_key' => null]);
        expect($first->fresh()->superseded_at)->not->toBeNull();
        expect($second)->toMatchArray(['status' => RuleSet::STATUS_ACTIVE, 'active_scope_key' => RuleSet::storeScopeKey($store->id)]);
        expect(RuleSet::query()->activeForStore($store->id)->pluck('id')->all())->toBe([$second->id]);
        expect($first->rules()->count())->toBe(6);
    });

    it('cannot hold two active versions of one scope', function () {
        $store = Store::factory()->create();
        RuleSet::factory()->active()->create(['store_id' => $store->id]);

        expect(fn () => RuleSet::factory()->active()->create(['store_id' => $store->id]))->toThrow(QueryException::class);
    });

    it('does not let an older validated run replace a newer active rule set', function () {
        $store = Store::factory()->create();
        $older = RuleSet::factory()->create(['store_id' => $store->id]);
        $newer = RuleSet::factory()->active()->create(['store_id' => $store->id]);

        expect(fn () => app(RuleSetActivator::class)->activate($older))
            ->toThrow(RuntimeException::class, 'A newer rule set is already active.');
        expect($newer->fresh()->status)->toBe(RuleSet::STATUS_ACTIVE);
        expect($older->fresh()->status)->toBe(RuleSet::STATUS_VALIDATED);
    });

    it('keeps the previous version active when the new run has insufficient data', function () {
        $store = Store::factory()->create();
        recordFourBasketFixture($store);
        $previous = trainer()->trainStore($store, trainingThresholds())->ruleSet;

        $outcome = trainer()->trainStore($store, trainingThresholds(['min_baskets' => '100']));

        expect($outcome->status)->toBe(RuleSet::STATUS_INSUFFICIENT_DATA);
        expect($previous->fresh()->status)->toBe(RuleSet::STATUS_ACTIVE);
        expect($outcome->ruleSet->rules()->exists())->toBeFalse();
    });

    it('keeps the previous version active when generation fails', function () {
        $store = Store::factory()->create();
        recordFourBasketFixture($store);
        $previous = trainer()->trainStore($store, trainingThresholds())->ruleSet;
        $this->mock(AssociationRuleGenerator::class)->shouldReceive('generate')->andThrow(new RuntimeException('boom'));

        $outcome = trainer()->trainStore($store, trainingThresholds());

        expect($outcome->status)->toBe(RuleSet::STATUS_FAILED);
        expect($outcome->reason)->toBe('Training failed (RuntimeException); details are in the application log.');
        expect($previous->fresh()->status)->toBe(RuleSet::STATUS_ACTIVE);
    });

    it('fails validation when a saved rule does not match its counts', function () {
        $store = Store::factory()->create();
        [$a, $b] = recordFourBasketFixture($store);
        $this->mock(AssociationRuleGenerator::class)->shouldReceive('generate')->andReturn([[
            'antecedent' => $a->id, 'consequent' => $b->id, 'antecedent_count' => 3, 'consequent_count' => 3,
            'pair_count' => 2, 'support' => '0.500000', 'confidence' => '0.900000', 'lift' => '0.888889',
        ]]);

        $outcome = trainer()->trainStore($store, trainingThresholds());

        expect($outcome->status)->toBe(RuleSet::STATUS_FAILED);
        expect($outcome->reason)->toContain('metrics that do not match its counts');
        expect(RuleSet::query()->activeForStore($store->id)->exists())->toBeFalse();
    });

    it('keeps the previous version active when activation fails', function () {
        $store = Store::factory()->create();
        recordFourBasketFixture($store);
        $previous = trainer()->trainStore($store, trainingThresholds())->ruleSet;
        $this->mock(RuleSetActivator::class)->shouldReceive('activate')->andThrow(new RuntimeException('database went away'));

        $outcome = trainer()->trainStore($store, trainingThresholds());

        expect($outcome->status)->toBe(RuleSet::STATUS_FAILED);
        expect($outcome->reason)->toContain('the previously active rule set is unchanged');
        expect(RuleSet::query()->activeForStore($store->id)->pluck('id')->all())->toBe([$previous->id]);
    });

    it('rolls back activation of a rule set that is not validated', function () {
        $previous = RuleSet::factory()->active()->create();
        $failed = RuleSet::factory()->create(['store_id' => $previous->store_id, 'status' => RuleSet::STATUS_FAILED]);

        expect(fn () => app(RuleSetActivator::class)->activate($failed))->toThrow(RuntimeException::class);
        expect($previous->fresh()->status)->toBe(RuleSet::STATUS_ACTIVE);
    });

    it('marks runs abandoned by a crashed process as failed', function () {
        $store = Store::factory()->create();
        $abandoned = RuleSet::factory()->create(['store_id' => $store->id, 'status' => RuleSet::STATUS_BUILDING]);

        trainer()->trainStore($store, trainingThresholds());

        expect($abandoned->fresh())->toMatchArray(['status' => RuleSet::STATUS_FAILED, 'failure_reason' => 'Abandoned: the training run stopped before finishing.']);
    });

    it('does not validate or activate a run that a later run marked abandoned', function () {
        $store = Store::factory()->create();
        recordFourBasketFixture($store);
        $previous = trainer()->trainStore($store, trainingThresholds())->ruleSet;
        $generator = new AssociationRuleGenerator;
        $this->mock(AssociationRuleGenerator::class)->shouldReceive('generate')->andReturnUsing(function (BasketCounts $counts, AssociationRuleThresholds $thresholds) use ($generator): array {
            // A later run, started after this run's lock expired, marks it abandoned.
            RuleSet::query()->where('status', RuleSet::STATUS_BUILDING)->update(['status' => RuleSet::STATUS_FAILED]);

            return $generator->generate($counts, $thresholds);
        });

        $outcome = trainer()->trainStore($store, trainingThresholds());

        expect($outcome->status)->toBe(RuleSet::STATUS_FAILED);
        expect($outcome->reason)->toContain('is no longer building');
        expect(RuleSet::query()->activeForStore($store->id)->pluck('id')->all())->toBe([$previous->id]);
    });

    it('does not record insufficient data over a run that a later run marked abandoned', function () {
        $store = Store::factory()->create();
        $this->mock(PurchaseBasketCounter::class)->shouldReceive('count')->andReturnUsing(function (): array {
            RuleSet::query()->where('status', RuleSet::STATUS_BUILDING)->update(['status' => RuleSet::STATUS_FAILED]);

            return ['counts' => new BasketCounts(0, [], []), 'cutoff_purchase_id' => 0];
        });

        $outcome = trainer()->trainStore($store, trainingThresholds(['min_baskets' => '100']));

        expect($outcome->status)->toBe(RuleSet::STATUS_FAILED);
        expect($outcome->ruleSet->fresh()->status)->toBe(RuleSet::STATUS_FAILED);
    });
});

describe('concurrency and dry runs', function () {
    it('skips a run while another run of the same scope holds the lock', function () {
        $store = Store::factory()->create();
        $otherStore = Store::factory()->create();
        $lock = Cache::lock('association-rules:train:'.RuleSet::storeScopeKey($store->id), 60);
        $lock->get();

        $outcome = trainer()->trainStore($store, trainingThresholds());
        $otherOutcome = trainer()->trainStore($otherStore, trainingThresholds());

        expect($outcome->status)->toBe(TrainingOutcome::LOCKED);
        expect($outcome->succeeded())->toBeFalse();
        expect(RuleSet::query()->where('store_id', $store->id)->exists())->toBeFalse();
        expect($otherOutcome->status)->toBe(RuleSet::STATUS_INSUFFICIENT_DATA);
        $lock->release();
    });

    it('calculates a dry run without saving or activating anything', function () {
        $store = Store::factory()->create();
        recordFourBasketFixture($store);

        $outcome = trainer()->trainStore($store, trainingThresholds(), dryRun: true);

        expect($outcome->status)->toBe(TrainingOutcome::DRY_RUN);
        expect($outcome->ruleCount)->toBe(6);
        expect($outcome->previewRules[0])->toMatchArray(['confidence' => '0.666667']);
        expect(RuleSet::query()->exists())->toBeFalse();
        expect(AssociationRule::query()->exists())->toBeFalse();
    });
});

describe('separation from other modules', function () {
    it('writes only to the rule tables during training', function () {
        $store = Store::factory()->create();
        recordFourBasketFixture($store);
        $customer = Customer::factory()->create(['store_id' => $store->id]);
        SukiPoint::factory()->create(['customer_id' => $customer->id]);
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b[^`"]*[`"]?(\w+)/i', $query->sql, $matches)) {
                $writes[] = strtolower($matches[2]);
            }
        });

        trainer()->trainStore($store, trainingThresholds());

        expect(array_values(array_unique($writes)))->toEqualCanonicalizing(['rule_sets', 'association_rules']);
    });

    it('logs counts and identifiers but no customer details', function () {
        $store = Store::factory()->create();
        recordFourBasketFixture($store);
        $customer = Customer::factory()->create(['store_id' => $store->id, 'full_name' => 'Maria Santos', 'email' => 'maria@example.test']);
        Log::spy();

        $outcome = trainer()->trainStore($store, trainingThresholds());

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($outcome, $customer): bool {
            $logged = json_encode($context);

            return $message === 'Association rule training finished.'
                && $context['rule_set_id'] === $outcome->ruleSet->id
                && $context['eligible_baskets'] === 4
                && ! str_contains($logged, $customer->full_name)
                && ! str_contains($logged, $customer->email)
                && ! str_contains($logged, $customer->contact_number);
        })->once();
    });
});

describe('external experiments', function () {
    it('keeps an external experiment apart from store models and never activates it', function () {
        $store = Store::factory()->create();
        recordFourBasketFixture($store);
        $storeRuleSet = trainer()->trainStore($store, trainingThresholds())->ruleSet;
        $path = tempnam(sys_get_temp_dir(), 'baskets');
        file_put_contents($path, "Transaction_ID,itemset\nt1,\"['Milk', 'Bread']\"\nt2,\"['Milk', 'Bread']\"\nt3,\"['Milk']\"\nt4,\"[]\"\n");

        $outcome = trainer()->trainExternal($path, trainingThresholds());
        @unlink($path);

        expect($outcome->status)->toBe(RuleSet::STATUS_VALIDATED);
        expect($outcome->ruleSet->fresh())->toMatchArray([
            'scope_type' => RuleSet::SCOPE_EXTERNAL_EXPERIMENT,
            'store_id' => null,
            'active_scope_key' => null,
            'source_type' => RuleSet::SOURCE_EXTERNAL_DATASET,
            'eligible_basket_count' => 3,
        ]);
        expect($outcome->ruleSet->source_details)->toMatchArray(['rows_accepted' => 3, 'rows_rejected' => 1]);
        expect($outcome->ruleSet->rules()->orderBy('antecedent_label')->get(['antecedent_label', 'consequent_label', 'confidence', 'store_id', 'antecedent_product_id'])->toArray())->toBe([
            ['antecedent_label' => 'bread', 'consequent_label' => 'milk', 'confidence' => '1.000000', 'store_id' => null, 'antecedent_product_id' => null],
            ['antecedent_label' => 'milk', 'consequent_label' => 'bread', 'confidence' => '0.666667', 'store_id' => null, 'antecedent_product_id' => null],
        ]);
        expect($storeRuleSet->fresh()->status)->toBe(RuleSet::STATUS_ACTIVE);
        expect(Product::query()->count())->toBe(3);
        expect(Purchase::query()->count())->toBe(4);
    });

    it('refuses to activate an external experiment', function () {
        $experiment = RuleSet::factory()->create([
            'scope_type' => RuleSet::SCOPE_EXTERNAL_EXPERIMENT,
            'store_id' => null,
            'scope_key' => RuleSet::externalScopeKey(str_repeat('a', 64)),
        ]);

        expect(fn () => app(RuleSetActivator::class)->activate($experiment))->toThrow(RuntimeException::class, 'never activated');
        expect($experiment->fresh()->status)->toBe(RuleSet::STATUS_VALIDATED);
    });

    it('reports an unreadable external dataset without creating a rule set', function () {
        $outcome = trainer()->trainExternal(sys_get_temp_dir().'/missing-baskets.csv', trainingThresholds());

        expect($outcome->status)->toBe(RuleSet::STATUS_FAILED);
        expect($outcome->ruleSet)->toBeNull();
        expect(RuleSet::query()->exists())->toBeFalse();
    });
});
