<?php

use App\Support\Money;

it('multiplies a unit price by a decimal quantity and rounds half up to the centavo', function (int $unitPriceInCents, int $quantityInThousandths, int $expectedCents) {
    expect(Money::lineTotalCents($unitPriceInCents, $quantityInThousandths))->toBe($expectedCents);
})->with([
    'quarter kilo at 60.00' => [6000, 250, 1500],
    'two at 35.00' => [3500, 2000, 7000],
    'exact half centavo rounds up' => [1, 500, 1],
    'just below half centavo rounds down' => [1, 499, 0],
    'third of 10.00 rounds down' => [1000, 333, 333],
    'eighth of 0.99 rounds down from 12.375 centavos' => [99, 125, 12],
    'three eighths of 0.04 rounds up from 1.5 centavos' => [4, 375, 2],
    'zero price' => [0, 1500, 0],
    'largest price and quantity stay exact' => [999999999999, 1000000, 999999999999000],
]);
