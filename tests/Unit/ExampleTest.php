<?php

use App\Support\Money;

test('that true is true', function () {
    expect(true)->toBeTrue();
});

it('formats negative cents with the sign before the amount', function () {
    expect(Money::fromCents(-1))->toBe('-0.01');
    expect(Money::fromCents(-105))->toBe('-1.05');
    expect(Money::fromCents(105))->toBe('1.05');
});
