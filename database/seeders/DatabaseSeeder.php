<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Each demo store owner logs in with the store name as their username and
     * the DEMO_OWNER_PASSWORD value as their password.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo seeder must not be run in production.');
        }

        $ownerPassword = config('app.demo_owner_password');

        if (! is_string($ownerPassword) || $ownerPassword === '') {
            throw new RuntimeException('Set DEMO_OWNER_PASSWORD in .env before running the demo seeder.');
        }

        $ledgerService = app(LedgerService::class);
        $catalogSourceStore = null;

        foreach ($this->demoStores() as $demoStore) {
            $owner = $this->seedOwner($demoStore['owner'], $demoStore['store_name'], $ownerPassword);
            $store = $this->seedStore($owner, $demoStore['store_name']);

            if ($demoStore['store_name'] === 'Mang Arthur Store') {
                $catalogSourceStore = $store;
            }

            foreach ($demoStore['scenarios'] as $scenario) {
                DB::transaction(function () use ($store, $scenario, $ledgerService): void {
                    $customer = $store->customers()->firstOrCreate(
                        ['contact_number' => $scenario['customer']['contact_number']],
                        $scenario['customer'],
                    );

                    if ($customer->ledgerEntries()->exists()) {
                        return;
                    }

                    foreach ($scenario['entries'] as [$amount, $items, $deadlineDays, $paymentAmount]) {
                        $entry = $ledgerService->recordCredit($customer, [
                            'amount' => $amount,
                            'itemized_list' => $items,
                            'transaction_date' => now()->subDays(max(0, -$deadlineDays + 5))->toDateString(),
                            'repayment_deadline' => now()->addDays($deadlineDays)->toDateString(),
                        ]);

                        if ($paymentAmount > 0) {
                            $ledgerService->recordPayment($entry, $paymentAmount, now()->subDays(2)->toDateString());
                        }
                    }
                });
            }
        }

        if ($catalogSourceStore instanceof Store) {
            $this->seedCatalogForExistingStores($catalogSourceStore);
        }
    }

    private function seedCatalogForExistingStores(Store $sourceStore): void
    {
        $sourceProducts = $sourceStore->products()->get();

        if ($sourceProducts->isEmpty()) {
            return;
        }

        $targetStores = Store::query()
            ->whereHas('owner', fn (Builder $query): Builder => $query->whereIn('username', ['emanstore19', 'arizastore19']))
            ->get();

        DB::transaction(function () use ($sourceProducts, $targetStores): void {
            foreach ($targetStores as $targetStore) {
                $targetProducts = $targetStore->products()->get()->keyBy('normalized_name');

                foreach ($sourceProducts as $sourceProduct) {
                    if ($targetProducts->has($sourceProduct->normalized_name)) {
                        continue;
                    }

                    $targetProduct = $targetStore->products()->make($sourceProduct->only([
                        'name',
                        'unit_price',
                        'description',
                    ]));
                    $targetProduct->forceFill(['status' => $sourceProduct->status])->save();
                    $targetProducts->put($targetProduct->normalized_name, $targetProduct);
                }
            }
        });
    }

    /**
     * @param  array{name: string, email: string, legacy_email?: string}  $ownerDetails
     */
    private function seedOwner(array $ownerDetails, string $storeName, string $ownerPassword): User
    {
        $owner = User::query()->firstOrNew(['email' => $ownerDetails['email']]);

        if (! $owner->exists && isset($ownerDetails['legacy_email'])) {
            $owner = User::query()->firstOrNew(['email' => $ownerDetails['legacy_email']]);
        }

        $owner->fill([
            'name' => $ownerDetails['name'],
            'username' => $storeName,
            'email' => $ownerDetails['email'],
            'password' => $ownerPassword,
        ])->forceFill([
            'role' => User::ROLE_STORE_OWNER,
            'status' => User::STATUS_ACTIVE,
        ])->save();

        return $owner;
    }

    private function seedStore(User $owner, string $storeName): Store
    {
        $store = Store::query()->firstOrNew(['owner_user_id' => $owner->getKey()]);
        $store->fill(['store_name' => $storeName])->forceFill([
            'owner_user_id' => $owner->getKey(),
            'status' => Store::STATUS_ACTIVE,
        ])->save();

        return $store;
    }

    /**
     * @return list<array{
     *     store_name: string,
     *     owner: array{name: string, email: string, legacy_email?: string},
     *     scenarios: list<array{customer: array<string, mixed>, entries: list<array{0: int, 1: list<string>, 2: int, 3: int}>}>
     * }>
     */
    private function demoStores(): array
    {
        return [
            [
                'store_name' => 'Mang Arthur Store',
                'owner' => ['name' => 'Felix', 'email' => 'felix@gmail.com', 'legacy_email' => 'owner@example.com'],
                'scenarios' => [
                    [
                        'customer' => ['full_name' => 'Maria Santos', 'contact_number' => '09171234567', 'email' => 'maria.santos@example.test', 'residential_landmark' => 'Near Mabini Elementary School', 'credit_limit' => 5000],
                        'entries' => [[850, ['Rice 5kg', 'Cooking oil'], 7, 850]],
                    ],
                    [
                        'customer' => ['full_name' => 'Jose Dela Cruz', 'contact_number' => '09181234567', 'email' => 'jose.delacruz@example.test', 'residential_landmark' => 'Beside the barangay hall', 'credit_limit' => 6000],
                        'entries' => [[2400, ['Grocery items', 'Laundry soap'], 14, 900]],
                    ],
                    [
                        'customer' => ['full_name' => 'Liza Reyes', 'contact_number' => '09191234567', 'email' => 'liza.reyes@example.test', 'residential_landmark' => 'Across the covered court', 'credit_limit' => 3000],
                        'entries' => [[1200, ['School supplies'], -20, 0]],
                    ],
                    [
                        'customer' => ['full_name' => 'Ramon Garcia', 'contact_number' => '09201234567', 'email' => 'ramon.garcia@example.test', 'residential_landmark' => 'Near the public market', 'credit_limit' => 10000],
                        'entries' => [[1800, ['Construction supplies'], -5, 800], [700, ['Household items'], 10, 0]],
                    ],
                    [
                        'customer' => ['full_name' => 'Ana Villanueva', 'contact_number' => '09211234567', 'email' => 'ana.villanueva@example.test', 'residential_landmark' => 'Behind the health center', 'credit_limit' => 4000],
                        'entries' => [[600, ['Rice and canned goods'], -30, 600], [900, ['Baby supplies'], -10, 900], [500, ['Coffee and snacks'], 15, 0]],
                    ],
                ],
            ],
            [
                'store_name' => 'Aling Nena Store',
                'owner' => ['name' => 'Nena', 'email' => 'aling.nena@example.test'],
                'scenarios' => [
                    [
                        'customer' => ['full_name' => 'Pedro Bautista', 'contact_number' => '09221234567', 'email' => 'pedro.bautista@example.test', 'residential_landmark' => 'Near the chapel', 'credit_limit' => 4000],
                        'entries' => [[1500, ['Rice 10kg'], -12, 500]],
                    ],
                    [
                        'customer' => ['full_name' => 'Carmen Lopez', 'contact_number' => '09231234567', 'email' => 'carmen.lopez@example.test', 'residential_landmark' => 'Beside the water station', 'credit_limit' => 3000],
                        'entries' => [[650, ['Canned goods'], 5, 650]],
                    ],
                ],
            ],
        ];
    }
}
