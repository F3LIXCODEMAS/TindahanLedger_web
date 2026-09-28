<?php

namespace App\Services;

use App\Exceptions\CreditLimitExceeded;
use App\Exceptions\PaymentExceedsBalance;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\SukiPoint;
use App\Support\Money;
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
            $amountInCents = Money::toCents($attributes['amount']);

            if ($amountInCents === 0) {
                throw new \InvalidArgumentException('A credit amount must be greater than zero.');
            }

            $projectedBalanceInCents = Money::toCents($lockedCustomer->current_balance) + $amountInCents;
            $creditLimitInCents = Money::toCents($lockedCustomer->credit_limit);

            if ($projectedBalanceInCents > $creditLimitInCents) {
                throw new CreditLimitExceeded(
                    Money::fromCents($creditLimitInCents),
                    Money::fromCents($projectedBalanceInCents),
                );
            }

            $entry = $lockedCustomer->ledgerEntries()->create([
                'amount' => Money::fromCents($amountInCents),
                'itemized_list' => $attributes['itemized_list'] ?? null,
                'transaction_date' => $attributes['transaction_date'] ?? now()->toDateString(),
                'repayment_deadline' => $attributes['repayment_deadline'],
                'running_balance' => Money::fromCents($projectedBalanceInCents),
            ]);

            $lockedCustomer->update(['current_balance' => Money::fromCents($projectedBalanceInCents)]);

            return $entry;
        });
    }

    public function recordPayment(LedgerEntry $entry, float|int|string $amount, ?string $paymentDate = null): Payment
    {
        return DB::transaction(function () use ($entry, $amount, $paymentDate): Payment {
            $lockedEntry = LedgerEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($lockedEntry->customer_id);
            $paymentAmountInCents = Money::toCents($amount);

            if ($paymentAmountInCents === 0) {
                throw new \InvalidArgumentException('A payment amount must be greater than zero.');
            }

            $remainingBalanceInCents = Money::toCents($lockedEntry->amount)
                - Money::toCents((string) $lockedEntry->payments()->sum('amount'));

            if ($paymentAmountInCents > $remainingBalanceInCents) {
                throw new PaymentExceedsBalance(
                    Money::fromCents($remainingBalanceInCents),
                    Money::fromCents($paymentAmountInCents),
                );
            }

            $payment = $lockedEntry->payments()->create([
                'customer_id' => $lockedCustomer->id,
                'amount' => Money::fromCents($paymentAmountInCents),
                'payment_date' => $paymentDate ?? now()->toDateString(),
            ]);

            $totalCreditsInCents = Money::toCents((string) $lockedCustomer->ledgerEntries()->sum('amount'));
            $totalPaymentsInCents = Money::toCents((string) $lockedCustomer->payments()->sum('amount'));
            $newCustomerBalanceInCents = $totalCreditsInCents - $totalPaymentsInCents;
            $lockedCustomer->update(['current_balance' => Money::fromCents($newCustomerBalanceInCents)]);

            $this->awardRepaymentPoints($lockedCustomer, $payment, $paymentAmountInCents);

            $entryIsSettled = $paymentAmountInCents === $remainingBalanceInCents;
            $customerHasNoOutstandingDebt = $newCustomerBalanceInCents === 0;

            if ($entryIsSettled && $customerHasNoOutstandingDebt) {
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
            'reward_eligible' => $rewardEligible,
        ]);
    }

    private function awardRepaymentPoints(Customer $customer, Payment $payment, int $paymentAmountInCents): void
    {
        $sukiPoints = $customer->sukiPoints()->firstOrCreate([
            'customer_id' => $customer->id,
        ]);
        $remainderBeforeCents = $sukiPoints->repayment_remainder_cents;
        $repaymentTotalCents = $remainderBeforeCents + $paymentAmountInCents;
        $pointsAwarded = intdiv($repaymentTotalCents, SukiPoint::CENTS_PER_POINT);
        $remainderAfterCents = $repaymentTotalCents % SukiPoint::CENTS_PER_POINT;

        $customer->sukiPointTransactions()->create([
            'payment_id' => $payment->id,
            'paid_amount_cents' => $paymentAmountInCents,
            'points_awarded' => $pointsAwarded,
            'remainder_before_cents' => $remainderBeforeCents,
            'remainder_after_cents' => $remainderAfterCents,
        ]);

        $sukiPoints->update([
            'points_balance' => $sukiPoints->points_balance + $pointsAwarded,
            'repayment_remainder_cents' => $remainderAfterCents,
        ]);
    }
}
