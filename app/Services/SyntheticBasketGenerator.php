<?php

namespace App\Services;

/**
 * Plans deterministic SYNTHETIC test data for the association rule engine
 * (DUMS-39/40): a sari-sari catalog and purchase baskets. It only plans;
 * apriori:seed-synthetic-baskets records the data through the normal
 * services. Prices and buying patterns are invented for testing. They are
 * not verified market prices or observed customer behaviour.
 *
 * - Baskets mix recurring patterns at three frequencies (frequent,
 *   moderate, rare) with random fills weighted by product popularity, so
 *   products and pairs appear at realistic, unequal rates.
 * - Basket sizes 1 to 6: about 20%, 30%, 25%, 13%, 8%, 4%.
 * - Whole-number quantities with typical ranges per category.
 * - Each basket gets a date offset within the history window, and a share
 *   of baskets are utang sales for a small set of repeat customers.
 * - The same seed always gives the same plan; basket N does not depend on
 *   how many baskets are planned.
 *
 * Every basket has an idempotency key starting with KEY_PREFIX and with
 * UUID version nibble 8. Normal sales and credits use version-4 keys, so the
 * prefix and version together identify synthetic records exactly.
 */
class SyntheticBasketGenerator
{
    public const KEY_PREFIX = '0000d040-';

    /** SQL LIKE pattern matching only synthetic keys: the prefix plus version 8. */
    public const KEY_PATTERN = '0000d040-____-8%';

    /** Description stored on every product this tool creates. */
    public const CATALOG_TAG = 'Synthetic test product (apriori:seed-synthetic-baskets)';

    /** Description stored on products added with --catalog-only to a real store. */
    public const STARTER_TAG = 'Starter catalog item. Check the price before selling.';

    public const CUSTOMER_NAME_PREFIX = 'Synthetic Customer ';

    /**
     * name => [price, category, popularity 1-5]. Synthetic demonstration prices.
     *
     * @var array<string, array{0: string, 1: string, 2: int}>
     */
    public const CATALOG = [
        'Dinorado Rice 1kg' => ['58.00', 'rice', 5],
        'Sinandomeng Rice 1kg' => ['52.00', 'rice', 4],
        'Lucky Me Pancit Canton Original 60g' => ['16.00', 'noodles', 5],
        'Lucky Me Pancit Canton Chilimansi 60g' => ['16.00', 'noodles', 3],
        'Lucky Me Chicken Noodle Soup 55g' => ['11.00', 'noodles', 4],
        'Lucky Me Beef Na Beef 55g' => ['11.00', 'noodles', 3],
        'Payless Xtra Big Pancit Canton 130g' => ['22.00', 'noodles', 2],
        'Pandesal (10 pcs)' => ['30.00', 'bread', 5],
        'Gardenia Classic White Bread 400g' => ['75.00', 'bread', 2],
        'Brown Sugar 1/4 kg' => ['22.00', 'sugar', 3],
        'White Sugar 1/4 kg' => ['25.00', 'sugar', 2],
        'All-Purpose Flour 1/4 kg' => ['18.00', 'staples', 1],
        'Fresh Egg (medium)' => ['9.00', 'eggs', 5],
        'Ligo Sardines in Tomato Sauce 155g' => ['24.00', 'sardines', 5],
        'Mega Sardines Hot and Spicy 155g' => ['23.00', 'sardines', 4],
        'Argentina Corned Beef 150g' => ['36.00', 'corned_beef', 4],
        'Purefoods Corned Beef 150g' => ['45.00', 'corned_beef', 2],
        'Century Tuna Flakes in Oil 155g' => ['38.00', 'tuna', 3],
        'Argentina Meat Loaf 150g' => ['25.00', 'meat_loaf', 3],
        'Purefoods Vienna Sausage 130g' => ['42.00', 'meat_loaf', 1],
        'SkyFlakes Crackers 25g' => ['8.00', 'crackers', 5],
        'Fita Crackers 30g' => ['9.00', 'crackers', 3],
        'Rebisco Sandwich Crackers 32g' => ['9.00', 'biscuits', 3],
        'Cream-O Chocolate Cookies 33g' => ['10.00', 'biscuits', 3],
        'Hansel Mocha Sandwich 32g' => ['9.00', 'biscuits', 2],
        'Piattos Cheese 40g' => ['18.00', 'chips', 4],
        'Nova Country Cheddar 40g' => ['18.00', 'chips', 3],
        'Chippy Barbecue 27g' => ['12.00', 'chips', 3],
        'Boy Bawang Cornick Garlic 100g' => ['22.00', 'chips', 2],
        'Choc-Nut 24g' => ['5.00', 'candy', 2],
        'Max Mint Candy (3 pcs)' => ['3.00', 'candy', 2],
        'Cloud 9 Chocolate Bar 30g' => ['12.00', 'candy', 2],
        'Nature Spring Water 500ml' => ['15.00', 'water', 4],
        'Wilkins Distilled Water 1L' => ['28.00', 'water', 2],
        'Coca-Cola Mismo 290ml' => ['20.00', 'soft_drink', 5],
        'Royal Tru-Orange Mismo 290ml' => ['20.00', 'soft_drink', 3],
        'Sprite Mismo 290ml' => ['20.00', 'soft_drink', 3],
        'Coca-Cola 1.5L' => ['75.00', 'soft_drink_large', 3],
        'C2 Green Tea Apple 230ml' => ['18.00', 'tea', 2],
        'Tang Orange Powdered Juice 20g' => ['22.00', 'juice', 3],
        'Zesto Orange Juice Drink 200ml' => ['12.00', 'juice', 2],
        'Kopiko Brown Coffee 3-in-1 25g' => ['9.00', 'coffee', 5],
        'Nescafe Original 3-in-1 28g' => ['9.00', 'coffee', 4],
        'Great Taste White Coffee 30g' => ['9.00', 'coffee', 3],
        'Nescafe Classic Stick 2g' => ['5.00', 'coffee_black', 2],
        'Coffee-mate Creamer 5g' => ['5.00', 'creamer', 2],
        'Milo Powdered Choco Drink 22g' => ['10.00', 'milk', 4],
        'Bear Brand Powdered Milk 33g' => ['15.00', 'milk', 4],
        'Alaska Evaporated Milk 370ml' => ['38.00', 'milk_can', 2],
        'Alaska Condensada 300ml' => ['45.00', 'milk_can', 1],
        'Golden Fiesta Cooking Oil 250ml' => ['45.00', 'oil', 3],
        'Baguio Cooking Oil 100ml' => ['20.00', 'oil', 3],
        'Silver Swan Soy Sauce 200ml' => ['18.00', 'condiment', 3],
        'Datu Puti Vinegar 200ml' => ['16.00', 'condiment', 3],
        'Rufina Patis 150ml' => ['22.00', 'condiment', 2],
        'Ajinomoto Ginisa Magic Sarap 8g' => ['5.00', 'seasoning', 4],
        'Knorr Chicken Cube 10g' => ['8.00', 'seasoning', 3],
        'Iodized Salt 250g' => ['12.00', 'seasoning', 2],
        'Garlic (per 100g)' => ['18.00', 'produce', 2],
        'Red Onion (per 100g)' => ['15.00', 'produce', 2],
        'Palmolive Shampoo Sachet 12ml' => ['7.00', 'shampoo', 4],
        'Clear Shampoo Sachet 11ml' => ['8.00', 'shampoo', 3],
        'Cream Silk Conditioner Sachet 11ml' => ['7.00', 'conditioner', 2],
        'Safeguard Bath Soap 60g' => ['30.00', 'bath_soap', 4],
        'Palmolive Bath Soap 55g' => ['25.00', 'bath_soap', 2],
        'Colgate Toothpaste 25ml' => ['28.00', 'toothpaste', 3],
        'Colgate Toothbrush' => ['30.00', 'toothbrush', 1],
        'Surf Powder Detergent 65g' => ['9.00', 'detergent', 4],
        'Tide Laundry Bar 125g' => ['20.00', 'detergent', 3],
        'Downy Fabric Conditioner Sachet 24ml' => ['7.00', 'fabric_conditioner', 3],
        'Joy Dishwashing Liquid 20ml' => ['7.00', 'dishwashing', 3],
        'Zonrox Bleach 250ml' => ['22.00', 'bleach', 2],
        'Sanicare Tissue 2-ply' => ['14.00', 'tissue', 2],
        'Esperma Candle (medium)' => ['10.00', 'candle', 1],
        'Posporo Matches' => ['2.00', 'matches', 1],
        'Eveready AA Battery (2 pcs)' => ['45.00', 'battery', 1],
    ];

    /**
     * Recurring category patterns: [weight, categories]. A category listed
     * twice yields two different products from it.
     *
     * @var list<array{0: int, 1: list<string>}>
     */
    public const PATTERNS = [
        // Frequent
        [14, ['noodles', 'sardines']],
        [12, ['coffee', 'bread']],
        [12, ['soft_drink', 'chips']],
        [10, ['rice', 'sardines']],
        [9, ['shampoo', 'bath_soap']],
        [9, ['detergent', 'fabric_conditioner']],
        [8, ['eggs', 'rice']],
        // Moderate
        [7, ['noodles', 'eggs']],
        [6, ['bread', 'milk']],
        [5, ['crackers', 'water']],
        [5, ['biscuits', 'soft_drink']],
        [5, ['coffee', 'sugar']],
        [5, ['corned_beef', 'rice']],
        [4, ['oil', 'condiment', 'seasoning']],
        [4, ['shampoo', 'conditioner']],
        [3, ['toothpaste', 'toothbrush']],
        [3, ['tuna', 'bread']],
        [3, ['meat_loaf', 'rice']],
        // Rare
        [2, ['candle', 'matches']],
        [2, ['dishwashing', 'bleach']],
        [2, ['produce', 'produce']],
        [1, ['milk_can', 'coffee_black']],
        [1, ['juice', 'crackers']],
    ];

    /**
     * Typical whole-number quantity ranges per category; others are 1-2.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    public const QUANTITIES = [
        'eggs' => [1, 6], 'noodles' => [1, 3], 'coffee' => [1, 4], 'coffee_black' => [1, 3], 'creamer' => [1, 3],
        'rice' => [1, 3], 'shampoo' => [1, 3], 'conditioner' => [1, 3], 'detergent' => [1, 3], 'fabric_conditioner' => [1, 3],
        'seasoning' => [1, 3], 'dishwashing' => [1, 2], 'candy' => [1, 5], 'crackers' => [1, 3], 'biscuits' => [1, 2],
    ];

    /** Sizes 1 to 6 and their percentage weights. */
    private const SIZE_WEIGHTS = [1 => 20, 2 => 30, 3 => 25, 4 => 13, 5 => 8, 6 => 4];

    /**
     * @param  list<string>  $usableNames  catalog names available in the store
     * @return list<array{index: int, key: string, type: string, customer_slot: int|null, days_ago: int, items: list<array{name: string, quantity: string}>}>
     */
    public function plan(int $storeId, array $usableNames, int $count, int $seed, int $historyDays, int $creditSharePercent, int $customerSlots): array
    {
        $usable = array_values(array_intersect(array_keys(self::CATALOG), $usableNames));
        $byCategory = [];

        foreach ($usable as $name) {
            $byCategory[self::CATALOG[$name][1]][] = $name;
        }

        $patterns = array_values(array_filter(self::PATTERNS, function (array $pattern) use ($byCategory): bool {
            foreach (array_count_values($pattern[1]) as $category => $needed) {
                if (count($byCategory[$category] ?? []) < $needed) {
                    return false;
                }
            }

            return true;
        }));
        $baskets = [];

        mt_srand($seed);

        for ($index = 1; $index <= $count; $index++) {
            $size = min($this->weightedPick(self::SIZE_WEIGHTS), count($usable));
            $names = $this->basketNames($usable, $byCategory, $patterns, $size);
            $isCredit = mt_rand(1, 100) <= $creditSharePercent && $customerSlots > 0;
            $daysAgo = mt_rand(0, max(0, $historyDays - 1));
            $customerSlot = mt_rand(1, max(1, $customerSlots));

            $baskets[] = [
                'index' => $index,
                'key' => $this->keyFor($storeId, $seed, $index),
                'type' => $isCredit ? 'utang' : 'cash',
                'customer_slot' => $isCredit ? $customerSlot : null,
                'days_ago' => $daysAgo,
                'items' => array_map(fn (string $name): array => ['name' => $name, 'quantity' => (string) $this->quantity(self::CATALOG[$name][1])], $names),
            ];
        }

        mt_srand();

        return $baskets;
    }

    /**
     * Pattern labels whose categories have enough usable products.
     *
     * @param  list<string>  $usableNames
     * @return array{used: list<string>, skipped: list<string>}
     */
    public function patternCoverage(array $usableNames): array
    {
        $categories = array_count_values(array_map(fn (string $name): string => self::CATALOG[$name][1], array_values(array_intersect(array_keys(self::CATALOG), $usableNames))));
        $used = [];
        $skipped = [];

        foreach (self::PATTERNS as [, $patternCategories]) {
            $label = implode(' + ', $patternCategories);
            $available = collect(array_count_values($patternCategories))->every(fn (int $needed, string $category): bool => ($categories[$category] ?? 0) >= $needed);
            $available ? $used[] = $label : $skipped[] = $label;
        }

        return ['used' => $used, 'skipped' => $skipped];
    }

    /**
     * A deterministic UUID (version 8, RFC 4122 variant) with KEY_PREFIX.
     */
    public function keyFor(int $storeId, int $seed, int $index): string
    {
        $hash = sha1("tindahanledger-synthetic-baskets|v2|{$storeId}|{$seed}|{$index}");
        $variant = dechex(8 | (hexdec($hash[7]) & 3));

        return self::KEY_PREFIX.substr($hash, 0, 4).'-8'.substr($hash, 4, 3).'-'.$variant.substr($hash, 8, 3).'-'.substr($hash, 11, 12);
    }

    /**
     * @param  list<string>  $usable
     * @param  array<string, list<string>>  $byCategory
     * @param  list<array{0: int, 1: list<string>}>  $patterns
     * @return list<string>
     */
    private function basketNames(array $usable, array $byCategory, array $patterns, int $size): array
    {
        $chosen = [];

        if ($size >= 2 && $patterns !== [] && mt_rand(1, 4) <= 3) {
            $pattern = $patterns[$this->weightedPick(array_column($patterns, 0))][1];

            foreach ($pattern as $category) {
                $candidates = array_values(array_diff($byCategory[$category], $chosen));

                if ($candidates !== [] && count($chosen) < $size) {
                    $chosen[] = $this->popularPick($candidates);
                }
            }
        }

        while (count($chosen) < $size) {
            $chosen[] = $this->popularPick(array_values(array_diff($usable, $chosen)));
        }

        return $chosen;
    }

    /**
     * @param  list<string>  $names
     */
    private function popularPick(array $names): string
    {
        return $names[$this->weightedPick(array_map(fn (string $name): int => self::CATALOG[$name][2], $names))];
    }

    /**
     * A key of `$weights` chosen with probability proportional to its weight.
     *
     * @param  array<int, int>  $weights
     */
    private function weightedPick(array $weights): int
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $key => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    private function quantity(string $category): int
    {
        [$minimum, $maximum] = self::QUANTITIES[$category] ?? [1, 2];

        return mt_rand(1, 100) <= 65 ? $minimum : mt_rand($minimum, $maximum);
    }
}
