<?php

use App\Services\BasketDatasetValidator;

/**
 * Write CSV text to a temporary file that is removed when the run ends.
 */
function basketCsv(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'baskets');
    file_put_contents($path, $contents);
    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

it('accepts a well-formed file and reports its fingerprint, baskets, and labels', function () {
    $path = basketCsv("Transaction_ID,itemset\nt1,\"['Milk', 'Bread']\"\nt2,\"['milk', '  Eggs ']\"\n");

    $report = (new BasketDatasetValidator)->validate($path);

    expect($report['file_errors'])->toBe([]);
    expect($report['file']['sha256'])->toBe(hash_file('sha256', $path));
    expect($report['rows'])->toBe(['read' => 2, 'accepted' => 2, 'rejected' => 0]);
    expect($report['baskets']['item_occurrences'])->toBe(4);
    expect($report['labels'][0])->toBe(['label' => 'milk', 'baskets' => 2, 'original_spellings' => ['Milk', 'milk']]);
    expect(array_column($report['labels'], 'label'))->toContain('eggs');
});

it('stops with a file error for an unreadable, empty, or unexpected file', function (?string $contents, string $expectedError) {
    $path = $contents === null ? sys_get_temp_dir().'/missing-'.uniqid().'.csv' : basketCsv($contents);

    $report = (new BasketDatasetValidator)->validate($path);

    expect($report['file_errors'])->toBe([$expectedError]);
    expect($report)->not->toHaveKey('rows');
})->with([
    'missing file' => [null, 'The file does not exist or cannot be read.'],
    'empty file' => ['', 'The file is empty.'],
    'unexpected header' => ["InvoiceNo,StockCode,Quantity\n536365,85123A,6\n", 'Unexpected header. Expected exactly: Transaction_ID,itemset.'],
]);

it('skips a byte order mark before the header', function () {
    $report = (new BasketDatasetValidator)->validate(basketCsv("\xEF\xBB\xBFTransaction_ID,itemset\nt1,\"['Milk']\"\n"));

    expect($report['file']['byte_order_mark'])->toBeTrue();
    expect($report['rows']['accepted'])->toBe(1);
});

it('rejects a bad row with its line and reason while keeping valid rows', function (string $badRow, string $expectedReason) {
    $report = (new BasketDatasetValidator)->validate(basketCsv("Transaction_ID,itemset\nt1,\"['Milk']\"\n{$badRow}\n"));

    expect($report['rows'])->toBe(['read' => 2, 'accepted' => 1, 'rejected' => 1]);
    expect($report['rejections'][0])->toMatchArray(['line' => 3, 'reason' => $expectedReason]);
    expect($report['rejections_by_reason'])->toBe([$expectedReason => 1]);
})->with([
    'wrong field count' => ['t2,"[\'Milk\']",extra', 'wrong_field_count'],
    'missing transaction id' => [',"[\'Milk\']"', 'missing_transaction_id'],
    'repeated transaction id' => ['t1,"[\'Bread\']"', 'duplicate_transaction_id'],
    'unquoted labels' => ['t2,"[Milk, Bread]"', 'malformed_itemset'],
    'not a list' => ['t2,Milk', 'malformed_itemset'],
    'empty basket' => ['t2,"[]"', 'empty_basket'],
    'blank label' => ['t2,"[\'Milk\', \'  \']"', 'blank_label'],
    'label too long' => ['t2,"[\''.str_repeat('a', 121).'\']"', 'label_too_long'],
    'invalid UTF-8' => ["t2,\"['Mil\xC3\x28k']\"", 'invalid_encoding'],
]);

it('keeps the first occurrence of a repeated transaction id', function () {
    $report = (new BasketDatasetValidator)->validate(basketCsv("Transaction_ID,itemset\nt1,\"['Milk']\"\nt1,\"['Bread']\"\n"));

    expect(array_column($report['labels'], 'label'))->toBe(['milk']);
    expect($report['rejections'][0]['message'])->toBe('Transaction ID already used on line 2; the first occurrence is kept.');
});

it('keeps a repeated label once and reports it as a warning', function () {
    $report = (new BasketDatasetValidator)->validate(basketCsv("Transaction_ID,itemset\nt1,\"['Milk', 'MILK', 'Bread']\"\n"));

    expect($report['rows']['accepted'])->toBe(1);
    expect($report['baskets']['item_occurrences'])->toBe(2);
    expect($report['warnings'])->toBe([['line' => 2, 'transaction_id' => 't1', 'reason' => 'repeated_label', 'label' => 'MILK']]);
});

it('reports blank lines as warnings rather than rows', function () {
    $report = (new BasketDatasetValidator)->validate(basketCsv("Transaction_ID,itemset\nt1,\"['Milk']\"\n\nt2,\"['Bread']\"\n"));

    expect($report['rows'])->toBe(['read' => 2, 'accepted' => 2, 'rejected' => 0]);
    expect($report['warnings'][0]['reason'])->toBe('blank_line');
});

it('counts identical baskets under different ids without rejecting them', function () {
    $report = (new BasketDatasetValidator)->validate(basketCsv("Transaction_ID,itemset\nt1,\"['Milk', 'Bread']\"\nt2,\"['Bread', 'milk']\"\n"));

    expect($report['rows']['accepted'])->toBe(2);
    expect($report['baskets']['repeated_item_sets'])->toBe(1);
});

it('measures lift for pairs that appear together often enough', function () {
    $rows = array_merge(
        array_map(fn (int $index): string => "a{$index},\"['Rice', 'Sardines']\"", range(1, 20)),
        array_map(fn (int $index): string => "b{$index},\"['Soap']\"", range(1, 20)),
    );

    $signals = (new BasketDatasetValidator)->validate(basketCsv("Transaction_ID,itemset\n".implode("\n", $rows)."\n"))['suitability_signals'];

    expect($signals['pairs_measured'])->toBe(1);
    expect($signals['top_pairs_by_lift'][0])->toBe(['pair' => ['rice', 'sardines'], 'baskets' => 20, 'lift' => 2.0]);
});

it('parses Python-style lists of quoted labels', function (string $itemset, ?array $expectedLabels) {
    expect((new BasketDatasetValidator)->parseItemset($itemset))->toBe($expectedLabels);
})->with([
    'empty list' => ['[]', []],
    'single quotes' => ["['Milk', 'Bread']", ['Milk', 'Bread']],
    'double quotes around an apostrophe' => ["['Milk', \"Lay's Chips\"]", ['Milk', "Lay's Chips"]],
    'escaped quote' => ["['It\\'s']", ["It's"]],
    'curly apostrophe' => ["['Lay’s India’s Magic Masala']", ['Lay’s India’s Magic Masala']],
    'trailing comma' => ["['Milk',]", ['Milk']],
    'unquoted labels' => ['[Milk, Bread]', null],
    'missing comma' => ["['Milk' 'Bread']", null],
    'unterminated quote' => ["['Milk]", null],
    'numbers' => ['[1, 2]', null],
    'nested list' => ["[['Milk']]", null],
    'double comma' => ["['Milk',, 'Bread']", null],
    'not a list' => ["'Milk'", null],
]);
