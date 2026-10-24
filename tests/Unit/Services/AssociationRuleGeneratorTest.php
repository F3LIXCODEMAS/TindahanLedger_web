<?php

use App\Services\AssociationRuleGenerator;
use App\Support\AssociationRuleThresholds;
use App\Support\BasketCounts;

const PRODUCT_A = 1;
const PRODUCT_B = 2;
const PRODUCT_C = 3;

/**
 * Thresholds that keep every co-occurring pair; `$overrides` use the
 * constructor's parameter names.
 *
 * @param  array<string, int|null>  $overrides
 */
function openThresholds(array $overrides = []): AssociationRuleThresholds
{
    return new AssociationRuleThresholds(...[
        'minSupportUnits' => 0,
        'minConfidenceUnits' => 0,
        'minLiftUnits' => null,
        'minBaskets' => 1,
        'minAntecedentCount' => 1,
        'minPairCount' => 1,
        ...$overrides,
    ]);
}

function fourBasketFixture(): BasketCounts
{
    return BasketCounts::fromBaskets([
        [PRODUCT_A, PRODUCT_B],
        [PRODUCT_A, PRODUCT_B],
        [PRODUCT_A, PRODUCT_C],
        [PRODUCT_B, PRODUCT_C],
    ]);
}

/**
 * @param  list<array<string, mixed>>  $rules
 * @return array<string, mixed>|null
 */
function findRule(array $rules, int $antecedent, int $consequent): ?array
{
    return collect($rules)->first(fn (array $rule): bool => $rule['antecedent'] === $antecedent && $rule['consequent'] === $consequent);
}

it('evaluates both directions of every pair with the hand-calculated metrics', function () {
    $rules = (new AssociationRuleGenerator)->generate(fourBasketFixture(), openThresholds());

    expect($rules)->toHaveCount(6);
    expect(findRule($rules, PRODUCT_A, PRODUCT_B))->toBe([
        'antecedent' => PRODUCT_A,
        'consequent' => PRODUCT_B,
        'antecedent_count' => 3,
        'consequent_count' => 3,
        'pair_count' => 2,
        'support' => '0.500000',
        'confidence' => '0.666667',
        'lift' => '0.888889',
    ]);
    expect(findRule($rules, PRODUCT_B, PRODUCT_A))->toMatchArray(['antecedent_count' => 3, 'consequent_count' => 3, 'pair_count' => 2, 'confidence' => '0.666667']);
    expect(findRule($rules, PRODUCT_A, PRODUCT_C))->toMatchArray(['confidence' => '0.333333', 'lift' => '0.666667']);
    expect(findRule($rules, PRODUCT_C, PRODUCT_A))->toMatchArray(['antecedent_count' => 2, 'confidence' => '0.500000', 'lift' => '0.666667']);
});

it('never generates a self-rule', function () {
    $rules = (new AssociationRuleGenerator)->generate(BasketCounts::fromBaskets([[PRODUCT_A, PRODUCT_A], [PRODUCT_A, PRODUCT_B]]), openThresholds());

    expect(collect($rules)->filter(fn (array $rule): bool => $rule['antecedent'] === $rule['consequent']))->toBeEmpty();
    expect($rules)->toHaveCount(2);
});

it('counts a repeated item once per basket and counts single-item baskets in N', function () {
    $counts = BasketCounts::fromBaskets([[PRODUCT_A, PRODUCT_A, PRODUCT_B], [PRODUCT_A], [], [PRODUCT_C]]);

    expect($counts->basketCount)->toBe(3);
    expect($counts->itemCounts)->toBe([PRODUCT_A => 2, PRODUCT_B => 1, PRODUCT_C => 1]);
    expect($counts->pairCounts)->toBe([[PRODUCT_A, PRODUCT_B, 1]]);
});

it('keeps a rule exactly at each threshold and drops it just below', function (array $atBoundary, array $aboveBoundary) {
    $generator = new AssociationRuleGenerator;

    expect(findRule($generator->generate(fourBasketFixture(), openThresholds($atBoundary)), PRODUCT_C, PRODUCT_A))->not->toBeNull();
    expect(findRule($generator->generate(fourBasketFixture(), openThresholds($aboveBoundary)), PRODUCT_C, PRODUCT_A))->toBeNull();
})->with([
    // C → A: count(A,C) = 1, count(C) = 2, N = 4: support 0.25, confidence 0.5, lift 0.666667.
    'support' => [['minSupportUnits' => 250_000], ['minSupportUnits' => 250_001]],
    'confidence' => [['minConfidenceUnits' => 500_000], ['minConfidenceUnits' => 500_001]],
    'lift' => [['minLiftUnits' => 666_666], ['minLiftUnits' => 666_667]],
    'co-occurrence count' => [['minPairCount' => 1], ['minPairCount' => 2]],
    'antecedent count' => [['minAntecedentCount' => 2], ['minAntecedentCount' => 3]],
]);

it('applies the lift filter only when it is enabled', function () {
    $generator = new AssociationRuleGenerator;

    expect($generator->generate(fourBasketFixture(), openThresholds(['minLiftUnits' => 1_000_000])))->toBeEmpty();
    expect($generator->generate(fourBasketFixture(), openThresholds(['minLiftUnits' => null])))->toHaveCount(6);
});

it('rejects counts that cannot come from real baskets', function () {
    $impossible = new BasketCounts(4, [PRODUCT_A => 2, PRODUCT_B => 3], [[PRODUCT_A, PRODUCT_B, 3]]);

    expect(fn () => (new AssociationRuleGenerator)->generate($impossible, openThresholds()))->toThrow(InvalidArgumentException::class);
});
