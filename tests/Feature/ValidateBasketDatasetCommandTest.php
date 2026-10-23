<?php

use App\Console\Commands\ValidateBasketDataset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Write CSV text to a temporary file that is removed after the test.
 */
function datasetFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'dataset');
    file_put_contents($path, $contents);
    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

it('validates a dataset, prints a summary, and saves the report with its source details', function () {
    Storage::fake('local');
    $path = datasetFile("Transaction_ID,itemset\nt1,\"['Milk', 'Bread']\"\nt2,\"['Rice']\"\nt3,\"[]\"\n");
    $sha256 = hash_file('sha256', $path);

    $this->artisan('dataset:validate-baskets', [
        'path' => $path,
        '--source-url' => 'https://example.test/baskets',
        '--license' => 'CC BY 4.0',
    ])
        ->expectsOutputToContain('accepted baskets')
        ->expectsOutputToContain($sha256)
        ->expectsOutputToContain('Validation only: nothing was imported.')
        ->doesntExpectOutputToContain('cannot be approved for import')
        ->assertSuccessful();

    $report = json_decode(Storage::disk('local')->get(ValidateBasketDataset::REPORT_DIRECTORY."/{$sha256}.json"), true);
    expect($report)->toMatchArray(['mode' => 'validation_only', 'import_status' => 'not_imported']);
    expect($report['source'])->toMatchArray(['url' => 'https://example.test/baskets', 'license' => 'CC BY 4.0', 'title' => null]);
    expect($report['rows'])->toBe(['read' => 3, 'accepted' => 2, 'rejected' => 1]);
    expect($report['rejections'][0])->toMatchArray(['line' => 4, 'transaction_id' => 't3', 'reason' => 'empty_basket']);
});

it('warns that a dataset without a source or license cannot be approved for import', function () {
    Storage::fake('local');

    $this->artisan('dataset:validate-baskets', ['path' => datasetFile("Transaction_ID,itemset\nt1,\"['Milk']\"\n")])
        ->expectsOutputToContain('Source URL or license not provided.')
        ->assertSuccessful();
});

it('never reads or writes the database', function () {
    Storage::fake('local');
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->artisan('dataset:validate-baskets', ['path' => datasetFile("Transaction_ID,itemset\nt1,\"['Milk', 'Bread']\"\n")])
        ->assertSuccessful();

    expect($queries)->toBe([]);
});

it('fails and still saves a report when the file cannot be validated', function (?string $contents, string $expectedError) {
    Storage::fake('local');
    $path = $contents === null ? sys_get_temp_dir().'/missing-'.uniqid().'.csv' : datasetFile($contents);

    $this->artisan('dataset:validate-baskets', ['path' => $path])
        ->expectsOutputToContain($expectedError)
        ->assertFailed();

    expect(Storage::disk('local')->files(ValidateBasketDataset::REPORT_DIRECTORY))->toHaveCount(1);
})->with([
    'missing file' => [null, 'The file does not exist or cannot be read.'],
    'unexpected header' => ["InvoiceNo,Description\n1,Mug\n", 'Unexpected header.'],
]);

it('fails when no basket is acceptable', function () {
    Storage::fake('local');

    $this->artisan('dataset:validate-baskets', ['path' => datasetFile("Transaction_ID,itemset\nt1,\"[]\"\n,\"['Milk']\"\n")])
        ->assertFailed();
});

it('saves nothing with the no-report option', function () {
    Storage::fake('local');

    $this->artisan('dataset:validate-baskets', ['path' => datasetFile("Transaction_ID,itemset\nt1,\"['Milk']\"\n"), '--no-report' => true])
        ->assertSuccessful();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});
