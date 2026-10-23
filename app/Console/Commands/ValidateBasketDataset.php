<?php

namespace App\Console\Commands;

use App\Services\BasketDatasetValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Validation only (DUMS-38): checks an external market-basket CSV and
 * writes a JSON report. It never writes to the database, never creates
 * products, customers, purchases, or financial records, and never
 * activates a recommendation model.
 */
class ValidateBasketDataset extends Command
{
    public const REPORT_DIRECTORY = 'dataset-validation';

    /**
     * @var string
     */
    protected $signature = 'dataset:validate-baskets
        {path : Path to the CSV file}
        {--source-url= : Where the dataset was downloaded from}
        {--title= : Dataset title as published}
        {--dataset-version= : Dataset version or release}
        {--license= : License name or URL}
        {--downloaded-at= : Download date (YYYY-MM-DD)}
        {--no-report : Print the summary without saving the JSON report}';

    /**
     * @var string
     */
    protected $description = 'Validate an external market-basket CSV without importing anything. Writes a JSON report to storage/app/private/dataset-validation.';

    public function handle(BasketDatasetValidator $validator): int
    {
        $report = [
            'mode' => 'validation_only',
            'import_status' => 'not_imported',
            'validated_at' => now()->toIso8601String(),
            'source' => $this->sourceMetadata(),
            ...$validator->validate((string) $this->argument('path')),
        ];

        $this->printSource($report['source']);

        if ($report['file_errors'] !== []) {
            foreach ($report['file_errors'] as $fileError) {
                $this->error($fileError);
            }

            $this->saveReport($report);

            return self::FAILURE;
        }

        $this->printFileAndRows($report);
        $this->printBaskets($report);
        $this->printSuitabilitySignals($report['suitability_signals']);
        $this->saveReport($report);
        $this->newLine();
        $this->line('Validation only: nothing was imported. Importing requires a known source and license and a suitability review.');

        return $report['rows']['accepted'] > 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, string|null>
     */
    private function sourceMetadata(): array
    {
        return [
            'url' => $this->option('source-url') ?: null,
            'title' => $this->option('title') ?: null,
            'version' => $this->option('dataset-version') ?: null,
            'license' => $this->option('license') ?: null,
            'downloaded_at' => $this->option('downloaded-at') ?: null,
        ];
    }

    /**
     * @param  array<string, string|null>  $source
     */
    private function printSource(array $source): void
    {
        $this->table(['Source', 'Value'], collect($source)
            ->map(fn (?string $value, string $field): array => [$field, $value ?? 'not provided'])
            ->values()
            ->all());

        if ($source['url'] === null || $source['license'] === null) {
            $this->warn('Source URL or license not provided. This dataset cannot be approved for import until both are known.');
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printFileAndRows(array $report): void
    {
        $this->table(['File', 'Value'], [
            ['path', $report['file']['path']],
            ['bytes', $report['file']['bytes']],
            ['sha256', $report['file']['sha256']],
            ['byte order mark', $report['file']['byte_order_mark'] ? 'yes (skipped)' : 'no'],
            ['header', implode(',', $report['format']['header'])],
        ]);

        $this->table(['Rows', 'Count'], [
            ['read', $report['rows']['read']],
            ['accepted baskets', $report['rows']['accepted']],
            ['rejected', $report['rows']['rejected']],
            ['warnings', count($report['warnings'])],
        ]);

        if ($report['rejections_by_reason'] !== []) {
            $this->table(['Rejection reason', 'Rows'], collect($report['rejections_by_reason'])
                ->map(fn (int $count, string $reason): array => [$reason, $count])
                ->values()
                ->all());

            $this->table(['Line', 'Transaction', 'Reason'], collect($report['rejections'])
                ->take(20)
                ->map(fn (array $rejection): array => [$rejection['line'], $rejection['transaction_id'] ?? '—', $rejection['message']])
                ->all());

            if (count($report['rejections']) > 20) {
                $this->line('Showing the first 20 rejected rows; the JSON report lists all of them.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printBaskets(array $report): void
    {
        $size = $report['baskets']['size'];

        $this->table(['Baskets', 'Value'], [
            ['item occurrences', $report['baskets']['item_occurrences']],
            ['distinct labels', count($report['labels'])],
            ['basket size (min / median / mean / max)', $size === null ? '—' : "{$size['min']} / {$size['median']} / {$size['mean']} / {$size['max']}"],
            ['repeated item sets', $report['baskets']['repeated_item_sets']],
        ]);
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function printSuitabilitySignals(array $signals): void
    {
        $lift = $signals['lift'];

        $this->table(['Independence check', 'Value'], [
            ['pairs in at least '.$signals['min_pair_baskets'].' baskets', $signals['pairs_measured']],
            ['lift median', $lift['median'] ?? '—'],
            ['lift 5th to 95th percentile', $lift['p05'] === null ? '—' : "{$lift['p05']} to {$lift['p95']}"],
            ['lift max', $lift['max'] ?? '—'],
            ['pairs with lift ≥ 1.5', $signals['pairs_with_lift_at_least_1_5']],
        ]);

        $this->line('How to read this: if lift is close to 1 for nearly every pair, products were chosen independently and mined rules would be noise.');
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function saveReport(array $report): void
    {
        if ($this->option('no-report')) {
            return;
        }

        $fileName = ($report['file']['sha256'] ?? 'unreadable-'.now()->format('Ymd-His')).'.json';
        $relativePath = self::REPORT_DIRECTORY.'/'.$fileName;
        Storage::disk('local')->put($relativePath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->info('Report saved: '.Storage::disk('local')->path($relativePath));
    }
}
