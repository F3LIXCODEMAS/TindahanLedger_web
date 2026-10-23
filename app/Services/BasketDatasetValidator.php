<?php

namespace App\Services;

use App\Models\Product;

/**
 * Validation-only check of an external market-basket CSV (DUMS-38).
 *
 * Supported format, measured from database/transaction.csv:
 * - UTF-8, comma-delimited, double-quoted fields, header
 *   `Transaction_ID,itemset`.
 * - One row per basket. `itemset` is a Python-style list of quoted
 *   product labels, for example `['Milk', 'Bread']`.
 * - No quantities, prices, dates, customers, or cancellations.
 *
 * Rules (every exclusion is reported, nothing is dropped silently):
 * - File errors stop validation: unreadable or empty file, unexpected
 *   header.
 * - A row is rejected for: wrong field count, invalid UTF-8, missing
 *   transaction ID, repeated transaction ID (the first occurrence is kept),
 *   a malformed itemset, an empty basket, or a blank or over-long label.
 * - A label repeated inside one basket is kept once and reported as a
 *   warning, because a basket is a set of products for association mining.
 *   Blank lines are skipped and reported as warnings; they are not rows.
 * - Identical baskets under different transaction IDs are kept (repeat
 *   purchases are real) and counted.
 *
 * Labels are normalized with the product catalog's rule (trim, collapse
 * spaces, lowercase; Product::normalizeName) and the original spellings are
 * reported alongside each normalized label.
 *
 * Nothing is written anywhere; the caller decides what to do with the
 * report.
 */
class BasketDatasetValidator
{
    public const EXPECTED_HEADER = ['Transaction_ID', 'itemset'];

    /** Same limit as product names in the catalog. */
    public const MAX_LABEL_LENGTH = 120;

    /**
     * Pairs must appear together in at least this many baskets before their
     * lift is used in the suitability signals.
     */
    public const MIN_PAIR_BASKETS_FOR_LIFT = 20;

    /**
     * @return array<string, mixed>
     */
    public function validate(string $path): array
    {
        $report = [
            'file' => ['path' => $path],
            'file_errors' => [],
        ];

        if (! is_file($path) || ! is_readable($path)) {
            $report['file_errors'][] = 'The file does not exist or cannot be read.';

            return $report;
        }

        $report['file'] += [
            'bytes' => filesize($path),
            'sha256' => hash_file('sha256', $path),
        ];

        $handle = fopen($path, 'rb');
        $firstBytes = fread($handle, 3);
        $report['file']['byte_order_mark'] = $firstBytes === "\xEF\xBB\xBF";

        if (! $report['file']['byte_order_mark']) {
            rewind($handle);
        }

        $header = fgetcsv($handle, null, ',', '"', '');

        if ($header === false || $header === [null]) {
            fclose($handle);
            $report['file_errors'][] = 'The file is empty.';

            return $report;
        }

        $report['format'] = [
            'delimiter' => ',',
            'header' => $header,
            'row_type' => 'one basket per row',
            'item_encoding' => 'Python-style list of quoted labels',
        ];

        if ($header !== self::EXPECTED_HEADER) {
            fclose($handle);
            $report['file_errors'][] = 'Unexpected header. Expected exactly: '.implode(',', self::EXPECTED_HEADER).'.';

            return $report;
        }

        $result = $this->validateRows($handle);
        fclose($handle);

        return $report + $result;
    }

    /**
     * @param  resource  $handle
     * @return array<string, mixed>
     */
    private function validateRows($handle): array
    {
        $lineNumber = 1;
        $rowsRead = 0;
        $rejections = [];
        $warnings = [];
        $seenTransactionIds = [];
        $acceptedBaskets = [];
        $originalSpellings = [];

        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $lineNumber++;

            if ($row === [null]) {
                $warnings[] = ['line' => $lineNumber, 'transaction_id' => null, 'reason' => 'blank_line', 'label' => null];

                continue;
            }

            $rowsRead++;

            if (! mb_check_encoding(implode('', array_map('strval', $row)), 'UTF-8')) {
                $rejections[] = $this->rejection($lineNumber, null, 'invalid_encoding', 'The row is not valid UTF-8.');

                continue;
            }

            $transactionId = trim((string) $row[0]);

            if (count($row) !== count(self::EXPECTED_HEADER)) {
                $rejections[] = $this->rejection($lineNumber, $transactionId, 'wrong_field_count', 'Expected 2 fields, found '.count($row).'.');

                continue;
            }

            if ($transactionId === '') {
                $rejections[] = $this->rejection($lineNumber, null, 'missing_transaction_id', 'The transaction ID is empty.');

                continue;
            }

            if (isset($seenTransactionIds[$transactionId])) {
                $rejections[] = $this->rejection($lineNumber, $transactionId, 'duplicate_transaction_id', 'Transaction ID already used on line '.$seenTransactionIds[$transactionId].'; the first occurrence is kept.');

                continue;
            }

            $seenTransactionIds[$transactionId] = $lineNumber;
            $labels = $this->parseItemset($row[1]);

            if ($labels === null) {
                $rejections[] = $this->rejection($lineNumber, $transactionId, 'malformed_itemset', 'The itemset is not a list of quoted labels, for example [\'Milk\', \'Bread\'].');

                continue;
            }

            if ($labels === []) {
                $rejections[] = $this->rejection($lineNumber, $transactionId, 'empty_basket', 'The basket has no products.');

                continue;
            }

            $basket = [];
            $rowRejection = null;

            foreach ($labels as $label) {
                $normalizedLabel = Product::normalizeName($label);

                if ($normalizedLabel === '') {
                    $rowRejection = $this->rejection($lineNumber, $transactionId, 'blank_label', 'The basket contains an empty product label.');

                    break;
                }

                if (mb_strlen($normalizedLabel) > self::MAX_LABEL_LENGTH) {
                    $rowRejection = $this->rejection($lineNumber, $transactionId, 'label_too_long', 'A product label is longer than '.self::MAX_LABEL_LENGTH.' characters.');

                    break;
                }

                if (isset($basket[$normalizedLabel])) {
                    $warnings[] = ['line' => $lineNumber, 'transaction_id' => $transactionId, 'reason' => 'repeated_label', 'label' => $label];

                    continue;
                }

                $basket[$normalizedLabel] = true;
                $originalSpellings[$normalizedLabel][$label] = true;
            }

            if ($rowRejection !== null) {
                $rejections[] = $rowRejection;

                continue;
            }

            $acceptedBaskets[] = array_keys($basket);
        }

        return [
            'rows' => [
                'read' => $rowsRead,
                'accepted' => count($acceptedBaskets),
                'rejected' => count($rejections),
            ],
            'rejections_by_reason' => array_count_values(array_column($rejections, 'reason')),
            'rejections' => $rejections,
            'warnings' => $warnings,
            'baskets' => $this->basketStatistics($acceptedBaskets),
            'labels' => $this->labelStatistics($acceptedBaskets, $originalSpellings),
            'suitability_signals' => $this->pairStatistics($acceptedBaskets),
        ];
    }

    /**
     * Parse a Python-style list of single- or double-quoted strings, such
     * as `['Milk', "Lay's Chips"]`. A backslash escapes the next character.
     * Returns null for anything else (numbers, nesting, unquoted text).
     *
     * @return list<string>|null
     */
    public function parseItemset(string $itemset): ?array
    {
        $text = trim($itemset);
        $length = strlen($text);

        if ($length < 2 || $text[0] !== '[' || $text[$length - 1] !== ']') {
            return null;
        }

        $labels = [];
        $position = 1;
        $end = $length - 1;
        $expectLabel = true;

        while (true) {
            while ($position < $end && ctype_space($text[$position])) {
                $position++;
            }

            if ($position >= $end) {
                // `[]`, `['a']`, and Python's trailing comma `['a',]` all end here.
                return $labels;
            }

            if (! $expectLabel) {
                if ($text[$position] !== ',') {
                    return null;
                }

                $position++;
                $expectLabel = true;

                continue;
            }

            $quote = $text[$position];

            if ($quote !== "'" && $quote !== '"') {
                return null;
            }

            $label = '';
            $position++;

            while ($position < $end && $text[$position] !== $quote) {
                if ($text[$position] === '\\' && $position + 1 < $end) {
                    $position++;
                }

                $label .= $text[$position];
                $position++;
            }

            if ($position >= $end) {
                return null;
            }

            $labels[] = $label;
            $position++;
            $expectLabel = false;
        }
    }

    /**
     * @return array{line: int, transaction_id: string|null, reason: string, message: string}
     */
    private function rejection(int $lineNumber, ?string $transactionId, string $reason, string $message): array
    {
        return [
            'line' => $lineNumber,
            'transaction_id' => $transactionId === '' ? null : $transactionId,
            'reason' => $reason,
            'message' => $message,
        ];
    }

    /**
     * @param  list<list<string>>  $baskets
     * @return array<string, mixed>
     */
    private function basketStatistics(array $baskets): array
    {
        $sizes = array_map('count', $baskets);
        sort($sizes);
        $sizeHistogram = array_count_values($sizes);
        ksort($sizeHistogram);
        $itemSets = array_map(function (array $basket): string {
            sort($basket);

            return implode("\u{1F}", $basket);
        }, $baskets);

        return [
            'item_occurrences' => array_sum($sizes),
            'size' => $sizes === [] ? null : [
                'min' => $sizes[0],
                'max' => $sizes[count($sizes) - 1],
                'mean' => round(array_sum($sizes) / count($sizes), 2),
                'median' => $sizes[intdiv(count($sizes), 2)],
            ],
            'size_histogram' => $sizeHistogram,
            'repeated_item_sets' => count($itemSets) - count(array_unique($itemSets)),
        ];
    }

    /**
     * @param  list<list<string>>  $baskets
     * @param  array<string, array<string, true>>  $originalSpellings
     * @return list<array{label: string, baskets: int, original_spellings: list<string>}>
     */
    private function labelStatistics(array $baskets, array $originalSpellings): array
    {
        $basketCounts = array_count_values(array_merge(...($baskets === [] ? [[]] : $baskets)));
        arsort($basketCounts);
        $labels = [];

        foreach ($basketCounts as $label => $count) {
            $labels[] = [
                'label' => (string) $label,
                'baskets' => $count,
                'original_spellings' => array_map('strval', array_keys($originalSpellings[$label] ?? [])),
            ];
        }

        return $labels;
    }

    /**
     * Pairwise lift, a quick independence check. Lift near 1 for almost all
     * pairs means products were chosen independently, so association rules
     * learned from the data would be noise.
     *
     * @param  list<list<string>>  $baskets
     * @return array<string, mixed>
     */
    private function pairStatistics(array $baskets): array
    {
        $basketCount = count($baskets);
        $itemCounts = [];
        $pairCounts = [];

        foreach ($baskets as $basket) {
            sort($basket);

            foreach ($basket as $index => $item) {
                $itemCounts[$item] = ($itemCounts[$item] ?? 0) + 1;

                for ($other = $index + 1; $other < count($basket); $other++) {
                    $pairKey = $item."\u{1F}".$basket[$other];
                    $pairCounts[$pairKey] = ($pairCounts[$pairKey] ?? 0) + 1;
                }
            }
        }

        $pairs = [];

        foreach ($pairCounts as $pairKey => $count) {
            if ($count < self::MIN_PAIR_BASKETS_FOR_LIFT) {
                continue;
            }

            [$first, $second] = explode("\u{1F}", $pairKey);
            $pairs[] = [
                'pair' => [$first, $second],
                'baskets' => $count,
                'lift' => round($count * $basketCount / ($itemCounts[$first] * $itemCounts[$second]), 3),
            ];
        }

        $lifts = array_column($pairs, 'lift');
        sort($lifts);
        usort($pairs, fn (array $left, array $right): int => $right['lift'] <=> $left['lift']);
        $percentile = fn (float $fraction): ?float => $lifts === [] ? null : $lifts[min(count($lifts) - 1, (int) floor($fraction * count($lifts)))];

        return [
            'min_pair_baskets' => self::MIN_PAIR_BASKETS_FOR_LIFT,
            'pairs_measured' => count($pairs),
            'lift' => [
                'min' => $percentile(0.0),
                'p05' => $percentile(0.05),
                'median' => $percentile(0.5),
                'p95' => $percentile(0.95),
                'max' => $lifts === [] ? null : $lifts[count($lifts) - 1],
            ],
            'pairs_with_lift_at_least_1_5' => count(array_filter($lifts, fn (float $lift): bool => $lift >= 1.5)),
            'top_pairs_by_lift' => array_slice($pairs, 0, 10),
        ];
    }
}
