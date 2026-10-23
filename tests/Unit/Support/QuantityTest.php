<?php

use App\Support\Quantity;

it('converts valid quantities to whole thousandths', function (string $quantity, int $expectedThousandths) {
    expect(Quantity::toThousandths($quantity))->toBe($expectedThousandths);
})->with([
    'one' => ['1', 1000],
    'two' => ['2', 2000],
    'one and a half' => ['1.5', 1500],
    'half' => ['0.5', 500],
    'quarter' => ['0.25', 250],
    'two and three quarters' => ['2.75', 2750],
    'smallest step' => ['0.001', 1],
    'three decimal places' => ['0.125', 125],
    'surrounding spaces' => [' 1.5 ', 1500],
    'maximum' => ['1000', 1000000],
    'maximum with decimals' => ['1000.000', 1000000],
]);

it('rejects malformed, non-positive, and over-precise quantities without rounding', function (string $quantity) {
    expect(fn () => Quantity::toThousandths($quantity))
        ->toThrow(InvalidArgumentException::class, Quantity::FORMAT_MESSAGE);
})->with([
    'zero' => ['0'],
    'zero with decimals' => ['0.000'],
    'negative' => ['-1'],
    'double decimal point' => ['1..5'],
    'letters' => ['abc'],
    'infinity' => ['INF'],
    'not a number' => ['NAN'],
    'exponent' => ['1e3'],
    'four decimal places' => ['0.1255'],
    'empty' => [''],
    'trailing point' => ['1.'],
    'leading point' => ['.5'],
    'five whole digits' => ['10000'],
]);

it('rejects quantities above the maximum', function () {
    expect(fn () => Quantity::toThousandths('1000.001'))
        ->toThrow(InvalidArgumentException::class, Quantity::MAXIMUM_MESSAGE);
});

it('formats thousandths for storage and display', function (int $thousandths, string $stored, string $displayed) {
    expect(Quantity::fromThousandths($thousandths))->toBe($stored);
    expect(Quantity::forDisplay($stored))->toBe($displayed);
})->with([
    'whole' => [2000, '2.000', '2'],
    'quarter' => [250, '0.250', '0.25'],
    'smallest step' => [1, '0.001', '0.001'],
    'maximum' => [1000000, '1000.000', '1000'],
]);
