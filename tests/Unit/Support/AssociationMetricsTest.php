<?php

use App\Support\AssociationMetrics;

it('matches the hand-calculated metrics for the four-basket fixture', function () {
    // Baskets: {A,B}, {A,B}, {A,C}, {B,C}. N = 4, count(A) = count(B) = 3, count(A,B) = 2.
    expect(AssociationMetrics::support(2, 4))->toBe('0.500000');
    expect(AssociationMetrics::confidence(2, 3))->toBe('0.666667');
    expect(AssociationMetrics::lift(2, 4, 3, 3))->toBe('0.888889');

    // A → C and C → A share support and lift but differ in confidence: count(C) = 2, count(A,C) = 1.
    expect(AssociationMetrics::confidence(1, 3))->toBe('0.333333');
    expect(AssociationMetrics::confidence(1, 2))->toBe('0.500000');
    expect(AssociationMetrics::lift(1, 4, 3, 2))->toBe('0.666667');
    expect(AssociationMetrics::lift(1, 4, 2, 3))->toBe('0.666667');
});

it('rounds half up to six decimal places', function (int $numerator, int $denominator, string $expected) {
    expect(AssociationMetrics::ratio($numerator, $denominator))->toBe($expected);
})->with([
    'exact' => [1, 8, '0.125000'],
    'half rounds up' => [1, 2_000_000, '0.000001'],
    'below half rounds down' => [1, 3, '0.333333'],
    'above half rounds up' => [2, 3, '0.666667'],
    'whole number' => [3, 3, '1.000000'],
]);

it('compares thresholds exactly and inclusively', function () {
    expect(AssociationMetrics::ratioMeets(3, 10, 300_000))->toBeTrue();
    expect(AssociationMetrics::ratioMeets(3, 10, 300_001))->toBeFalse();
    expect(AssociationMetrics::ratioMeets(1, 3, 333_333))->toBeTrue();
    expect(AssociationMetrics::ratioMeets(1, 3, 333_334))->toBeFalse();

    // Lift exactly 1: N = 4, count(A) = count(B) = 2, count(A,B) = 1.
    expect(AssociationMetrics::liftMeets(1, 4, 2, 2, 1_000_000))->toBeTrue();
    expect(AssociationMetrics::liftMeets(1, 4, 2, 2, 1_000_001))->toBeFalse();
});

it('stays exact at the largest supported basket count', function () {
    $max = AssociationMetrics::MAX_BASKETS;

    expect(AssociationMetrics::lift($max, $max, $max, $max))->toBe('1.000000');
    expect(AssociationMetrics::lift(1, $max, 1, 1))->toBe('1000000.000000');
    expect(AssociationMetrics::liftMeets(1, $max, 1, 1, 1000 * AssociationMetrics::UNITS))->toBeTrue();
});

it('rejects zero denominators and inconsistent counts', function (Closure $calculate) {
    expect($calculate)->toThrow(InvalidArgumentException::class);
})->with([
    'no baskets' => fn () => AssociationMetrics::support(0, 0),
    'no antecedent baskets' => fn () => AssociationMetrics::confidence(1, 0),
    'pair more frequent than a product' => fn () => AssociationMetrics::lift(4, 10, 3, 5),
    'product more frequent than the baskets' => fn () => AssociationMetrics::lift(1, 4, 5, 2),
    'too many baskets' => fn () => AssociationMetrics::lift(1, AssociationMetrics::MAX_BASKETS + 1, 1, 1),
]);
