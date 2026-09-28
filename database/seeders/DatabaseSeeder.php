<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
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

        User::query()->updateOrCreate([
            'email' => 'owner@example.com',
        ], [
            'name' => 'Test User',
            'password' => $ownerPassword,
        ]);

        $ledgerService = app(LedgerService::class);

        $scenarios = [
            [
                'customer' => ['full_name' => 'Maria Santos', 'contact_number' => '09171234567', 'residential_landmark' => 'Near Mabini Elementary School', 'credit_limit' => 5000],
                'entries' => [[850, ['Rice 5kg', 'Cooking oil'], 7, 850]],
            ],
            [
                'customer' => ['full_name' => 'Jose Dela Cruz', 'contact_number' => '09181234567', 'residential_landmark' => 'Beside the barangay hall', 'credit_limit' => 6000],
                'entries' => [[2400, ['Grocery items', 'Laundry soap'], 14, 900]],
            ],
            [
                'customer' => ['full_name' => 'Liza Reyes', 'contact_number' => '09191234567', 'residential_landmark' => 'Across the covered court', 'credit_limit' => 3000],
                'entries' => [[1200, ['School supplies'], -20, 0]],
            ],
            [
                'customer' => ['full_name' => 'Ramon Garcia', 'contact_number' => '09201234567', 'residential_landmark' => 'Near the public market', 'credit_limit' => 10000],
                'entries' => [[1800, ['Construction supplies'], -5, 800], [700, ['Household items'], 10, 0]],
            ],
            [
                'customer' => ['full_name' => 'Ana Villanueva', 'contact_number' => '09211234567', 'residential_landmark' => 'Behind the health center', 'credit_limit' => 4000],
                'entries' => [[600, ['Rice and canned goods'], -30, 600], [900, ['Baby supplies'], -10, 900], [500, ['Coffee and snacks'], 15, 0]],
            ],
        ];

        foreach ($scenarios as $scenario) {
            DB::transaction(function () use ($scenario, $ledgerService): void {
                $customer = Customer::query()->firstOrCreate(
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
}
