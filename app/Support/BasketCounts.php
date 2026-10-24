<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Basket statistics for one training scope. Items are integer keys:
 * product IDs for store purchases, or label indexes for external data.
 * Each item counts at most once per basket.
 */
final class BasketCounts
{
    /**
     * @param  array<int, int>  $itemCounts  item => baskets containing it
     * @param  list<array{0: int, 1: int, 2: int}>  $pairCounts  [first item, second item, baskets with both], first < second
     */
    public function __construct(
        public readonly int $basketCount,
        public readonly array $itemCounts,
        public readonly array $pairCounts,
    ) {}

    /**
     * Count a list of baskets in memory. Repeated items within a basket are
     * counted once; empty baskets are not baskets and are skipped. Only
     * pairs seen in at least `$minPairCount` baskets are kept.
     *
     * @param  iterable<list<int>>  $baskets
     */
    public static function fromBaskets(iterable $baskets, int $minPairCount = 1): self
    {
        $basketCount = 0;
        $itemCounts = [];
        $pairs = [];

        foreach ($baskets as $basket) {
            $items = array_values(array_unique($basket));

            if ($items === []) {
                continue;
            }

            sort($items);
            $basketCount++;

            foreach ($items as $position => $item) {
                $itemCounts[$item] = ($itemCounts[$item] ?? 0) + 1;

                for ($next = $position + 1, $size = count($items); $next < $size; $next++) {
                    $key = $item.':'.$items[$next];
                    $pairs[$key] = ($pairs[$key] ?? 0) + 1;
                }
            }
        }

        ksort($itemCounts);
        $pairCounts = [];

        foreach ($pairs as $key => $count) {
            if ($count >= $minPairCount) {
                [$first, $second] = array_map('intval', explode(':', (string) $key));
                $pairCounts[] = [$first, $second, $count];
            }
        }

        usort($pairCounts, fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);

        return new self($basketCount, $itemCounts, $pairCounts);
    }

    /**
     * Reject impossible counts before they reach the metrics.
     */
    public function assertConsistent(): void
    {
        foreach ($this->itemCounts as $count) {
            if ($count < 1 || $count > $this->basketCount) {
                throw new InvalidArgumentException('An item count is outside 1..N.');
            }
        }

        foreach ($this->pairCounts as [$first, $second, $count]) {
            if ($first >= $second || ! isset($this->itemCounts[$first], $this->itemCounts[$second])
                || $count < 1 || $count > min($this->itemCounts[$first], $this->itemCounts[$second])) {
                throw new InvalidArgumentException('A pair count is inconsistent with its item counts.');
            }
        }
    }
}
