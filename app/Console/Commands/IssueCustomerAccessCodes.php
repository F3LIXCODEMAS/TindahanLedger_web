<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\CustomerAccessService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class IssueCustomerAccessCodes extends Command
{
    /**
     * @var string
     */
    protected $signature = 'customers:issue-access-codes
        {--store= : Only issue codes for customers of this store ID}';

    /**
     * @var string
     */
    protected $description = 'Issue portal QR access codes to customers who do not have an active one. Existing codes are never replaced.';

    public function handle(CustomerAccessService $customerAccess): int
    {
        $storeId = $this->option('store');
        $issuedCount = 0;

        Customer::query()
            ->whereDoesntHave('activeAccessCredential')
            ->when($storeId !== null, fn (Builder $query) => $query->where('store_id', (int) $storeId))
            ->orderBy('id')
            ->chunkById(100, function ($customers) use ($customerAccess, &$issuedCount): void {
                foreach ($customers as $customer) {
                    $customerAccess->issue($customer);
                    $issuedCount++;
                }
            });

        $this->info("Issued {$issuedCount} customer access ".($issuedCount === 1 ? 'code' : 'codes').'.');

        return self::SUCCESS;
    }
}
