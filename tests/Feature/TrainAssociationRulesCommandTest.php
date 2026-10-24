<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\RuleSet;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;

/**
 * Options that keep every co-occurring pair of a small fixture.
 *
 * @return array<string, string>
 */
function openTrainingOptions(): array
{
    return ['--min-support' => '0', '--min-confidence' => '0', '--min-lift' => 'off', '--min-baskets' => '1', '--min-antecedent' => '1', '--min-pair' => '1'];
}

it('trains one store, activates the rule set, and reports the counts', function () {
    $store = Store::factory()->create();
    recordFourBasketFixture($store);

    $this->artisan('apriori:train', ['--store' => $store->id, ...openTrainingOptions()])
        ->expectsOutputToContain(RuleSet::storeScopeKey($store->id))
        ->expectsOutputToContain('active')
        ->assertSuccessful();

    expect(RuleSet::query()->activeForStore($store->id)->value('rule_count'))->toBe(6);
});

it('prints the rules of a dry run without saving anything', function () {
    $store = Store::factory()->create();
    [$a] = recordFourBasketFixture($store);

    $this->artisan('apriori:train', ['--store' => $store->id, '--dry-run' => true, ...openTrainingOptions()])
        ->expectsOutputToContain($a->name)
        ->expectsOutputToContain('0.666667')
        ->expectsOutputToContain('Dry run: metrics were calculated, but nothing was saved or activated.')
        ->assertSuccessful();

    expect(RuleSet::query()->exists())->toBeFalse();
});

it('uses the configured defaults and records insufficient data for a small store', function () {
    $store = Store::factory()->create();
    recordFourBasketFixture($store);

    $this->artisan('apriori:train', ['--store' => $store->id])
        ->expectsOutputToContain('Only 4 eligible baskets; at least 100 are required.')
        ->assertSuccessful();

    expect(RuleSet::query()->sole())->toMatchArray(['status' => RuleSet::STATUS_INSUFFICIENT_DATA]);
    expect(RuleSet::query()->sole()->parameters)->toMatchArray([
        'min_support' => '0.010000',
        'min_confidence' => '0.300000',
        'min_lift' => '1.000000',
        'min_baskets' => 100,
        'min_antecedent_count' => 5,
        'min_pair_count' => 3,
    ]);
});

it('trains every active store separately', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $suspended = Store::factory()->suspended()->create();
    recordFourBasketFixture($storeA);
    recordFourBasketFixture($storeB);

    $this->artisan('apriori:train', ['--all-stores' => true, ...openTrainingOptions()])->assertSuccessful();

    expect(RuleSet::query()->orderBy('store_id')->pluck('store_id')->all())->toBe([$storeA->id, $storeB->id]);
    expect(RuleSet::query()->where('store_id', $suspended->id)->exists())->toBeFalse();
    expect(RuleSet::query()->pluck('eligible_basket_count')->all())->toBe([4, 4]);
});

it('rejects ambiguous or missing scopes', function (array $options, string $message) {
    Store::factory()->create();

    $this->artisan('apriori:train', $options)
        ->expectsOutputToContain($message)
        ->assertExitCode(2);

    expect(RuleSet::query()->exists())->toBeFalse();
})->with([
    'no scope' => [[], 'Choose exactly one scope'],
    'both scopes' => [['--store' => '1', '--all-stores' => true], 'Choose exactly one scope'],
    'unknown source' => [['--source' => 'everything', '--all-stores' => true], '--source must be internal or external.'],
    'external without dataset' => [['--source' => 'external'], 'An external experiment needs --dataset=PATH'],
    'external with a store' => [['--source' => 'external', '--dataset' => 'x.csv', '--store' => '1'], 'cannot be combined with --store'],
    'dataset for internal' => [['--store' => '1', '--dataset' => 'x.csv'], '--dataset is only used with --source=external.'],
    'missing store' => [['--store' => '999'], 'Store 999 does not exist.'],
    'non-numeric store' => [['--store' => 'abc'], '--store must be a store ID.'],
]);

it('rejects invalid thresholds', function (array $thresholds, string $message) {
    $store = Store::factory()->create();

    $this->artisan('apriori:train', ['--store' => $store->id, ...$thresholds])
        ->expectsOutputToContain($message)
        ->assertExitCode(2);
})->with([
    'negative support' => [['--min-support' => '-0.1'], 'min_support must be a decimal number'],
    'support above 1' => [['--min-support' => '1.01'], 'min_support must be between 0 and 1.000000.'],
    'confidence above 1' => [['--min-confidence' => '2'], 'min_confidence must be between 0 and 1.000000.'],
    'too many decimals' => [['--min-confidence' => '0.1234567'], 'at most six decimal places'],
    'lift above 1000' => [['--min-lift' => '1000.5'], 'min_lift must be between 0 and 1000.000000.'],
    'zero baskets' => [['--min-baskets' => '0'], 'min_baskets must be a whole number from 1'],
    'fractional pair count' => [['--min-pair' => '2.5'], 'min_pair_count must be a whole number from 1'],
]);

it('accepts the inclusive boundary values', function () {
    $store = Store::factory()->create();
    recordFourBasketFixture($store);

    $this->artisan('apriori:train', ['--store' => $store->id, '--min-support' => '1', '--min-confidence' => '1', '--min-lift' => '1000', '--min-baskets' => '4', '--min-antecedent' => '1', '--min-pair' => '1'])
        ->assertSuccessful();

    expect(RuleSet::query()->sole())->toMatchArray(['status' => RuleSet::STATUS_ACTIVE, 'rule_count' => 0]);
});

it('fails while another run of the same store holds the lock', function () {
    $store = Store::factory()->create();
    $lock = Cache::lock('association-rules:train:'.RuleSet::storeScopeKey($store->id), 60);
    $lock->get();

    $this->artisan('apriori:train', ['--store' => $store->id])
        ->expectsOutputToContain('Another training run for this scope is in progress.')
        ->assertFailed();

    $lock->release();
});

it('runs an external experiment that is never activated', function () {
    $path = tempnam(sys_get_temp_dir(), 'baskets');
    file_put_contents($path, "Transaction_ID,itemset\nt1,\"['Milk', 'Bread']\"\nt2,\"['Milk', 'Bread']\"\n");

    $this->artisan('apriori:train', ['--source' => 'external', '--dataset' => $path, ...openTrainingOptions()])
        ->expectsOutputToContain('not Philippine sari-sari buying patterns')
        ->expectsOutputToContain('validated')
        ->assertSuccessful();
    @unlink($path);

    expect(RuleSet::query()->sole())->toMatchArray(['scope_type' => RuleSet::SCOPE_EXTERNAL_EXPERIMENT, 'status' => RuleSet::STATUS_VALIDATED]);
});

it('fails for an unreadable external dataset', function () {
    $this->artisan('apriori:train', ['--source' => 'external', '--dataset' => sys_get_temp_dir().'/missing-baskets.csv'])
        ->expectsOutputToContain('The file does not exist or cannot be read.')
        ->assertFailed();
});

it('never prints customer details', function () {
    $store = Store::factory()->create();
    recordFourBasketFixture($store);
    $customer = Customer::factory()->create(['store_id' => $store->id, 'full_name' => 'Maria Santos', 'email' => 'maria@example.test']);

    $this->artisan('apriori:train', ['--store' => $store->id, '--dry-run' => true, ...openTrainingOptions()])
        ->doesntExpectOutputToContain($customer->full_name)
        ->doesntExpectOutputToContain($customer->email)
        ->doesntExpectOutputToContain($customer->contact_number)
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(3);
});
