<?php

namespace App\Services;

use App\Exceptions\CreditLimitExceeded;
use App\Exceptions\PaymentExceedsBalance;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class LedgerService
{
    /**
     * @param  array{amount: float|int|string, itemized_list?: array<int, mixed>|null, transaction_date?: string, repayment_deadline: string}  $attributes
     */
    public function recordCredit(Customer $customer, array $attributes): LedgerEntry
    {
        return DB::transaction(function () use ($customer, $attributes): LedgerEntry {
            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $amount = (float) $attributes['amount'];
            $projectedBalance = (float) $lockedCustomer->current_balance + $amount;

            if ($amount <= 0) {
                throw new \InvalidArgumentException('A credit amount must be greater than zero.');
            }

            if ($projectedBalance > (float) $lockedCustomer->credit_limit) {
                throw new CreditLimitExceeded((float) $lockedCustomer->credit_limit, $projectedBalance);
            }

            $entry = $lockedCustomer->ledgerEntries()->create([
                'amount' => $amount,
                'itemized_list' => $attributes['itemized_list'] ?? null,
                'transaction_date' => $attributes['transaction_date'] ?? now()->toDateString(),
                'repayment_deadline' => $attributes['repayment_deadline'],
                'running_balance' => $projectedBalance,
            ]);

            $lockedCustomer->update(['current_balance' => $projectedBalance]);

            return $entry;
        });
    }

    public function recordPayment(LedgerEntry $entry, float|int|string $amount, ?string $paymentDate = null): Payment
    {
        return DB::transaction(function () use ($entry, $amount, $paymentDate): Payment {
            $lockedEntry = LedgerEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($lockedEntry->customer_id);
            $paymentAmount = (float) $amount;
            $remainingBalance = (float) $lockedEntry->amount - (float) $lockedEntry->payments()->sum('amount');

            if ($paymentAmount <= 0) {
                throw new \InvalidArgumentException('A payment amount must be greater than zero.');
            }

            if ($paymentAmount > $remainingBalance) {
                throw new PaymentExceedsBalance($remainingBalance, $paymentAmount);
            }

            $payment = $lockedEntry->payments()->create([
                'customer_id' => $lockedCustomer->id,
                'amount' => $paymentAmount,
                'payment_date' => $paymentDate ?? now()->toDateString(),
            ]);

            $newCustomerBalance = max(0, (float) $lockedCustomer->current_balance - $paymentAmount);
            $lockedCustomer->update(['current_balance' => $newCustomerBalance]);

            if (abs($paymentAmount - $remainingBalance) < 0.005) {
                $this->completeDebtCycle($lockedCustomer);
            }

            return $payment;
        });
    }

    private function completeDebtCycle(Customer $customer): void
    {
        $sukiPoints = $customer->sukiPoints()->firstOrCreate([
            'customer_id' => $customer->id,
        ]);

        $currentCycleCount = $sukiPoints->current_cycle_count + 1;
        $rewardEligible = $currentCycleCount >= $sukiPoints->reward_threshold;

        $sukiPoints->update([
            'completed_cycles' => $sukiPoints->completed_cycles + 1,
            'current_cycle_count' => $rewardEligible ? 0 : $currentCycleCount,
            'points_balance' => $sukiPoints->points_balance + 1,
            'reward_eligible' => $rewardEligible,
        ]);
    }
}
