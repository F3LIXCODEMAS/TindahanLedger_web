<?php

namespace App\Services;

use App\Exceptions\CreditLimitExceeded;
use App\Exceptions\PaymentExceedsBalance;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\SukiPoint;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LedgerService
{
    /**
     * @param  array{amount: float|int|string, itemized_list?: array<int, mixed>|null, note?: string|null, transaction_date?: string, repayment_deadline?: string|null, idempotency_key?: string|null}  $attributes
     */
    public function recordCredit(Customer $customer, array $attributes): LedgerEntry
    {
        return DB::transaction(function () use ($customer, $attributes): LedgerEntry {
            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            if (! empty($attributes['idempotency_key'])) {
                $existingEntry = $lockedCustomer->ledgerEntries()
                    ->where('idempotency_key', $attributes['idempotency_key'])
                    ->first();

                if ($existingEntry) {
                    return $existingEntry;
                }
            }

            $amountInCents = Money::toCents($attributes['amount']);

            if ($amountInCents === 0) {
                throw new \InvalidArgumentException('A credit amount must be greater than zero.');
            }

            $currentBalanceInCents = $this->outstandingBalanceInCents($lockedCustomer);
            $projectedBalanceInCents = $currentBalanceInCents + $amountInCents;
            $creditLimitInCents = Money::toCents($lockedCustomer->credit_limit);

            if ($projectedBalanceInCents > $creditLimitInCents) {
                throw new CreditLimitExceeded(
                    Money::fromCents($currentBalanceInCents),
                    Money::fromCents(max(0, $creditLimitInCents - $currentBalanceInCents)),
                    Money::fromCents($amountInCents),
                    Money::fromCents($projectedBalanceInCents),
                );
            }

            $entry = $lockedCustomer->ledgerEntries()->create([
                'amount' => Money::fromCents($amountInCents),
                'itemized_list' => $attributes['itemized_list'] ?? null,
                'note' => $attributes['note'] ?? null,
                'transaction_date' => $attributes['transaction_date'] ?? now()->toDateString(),
                'repayment_deadline' => $attributes['repayment_deadline'] ?? null,
                'running_balance' => Money::fromCents($projectedBalanceInCents),
                'idempotency_key' => $attributes['idempotency_key'] ?? null,
            ]);

            $lockedCustomer->update(['current_balance' => Money::fromCents($projectedBalanceInCents)]);

            return $entry;
        });
    }

    public function recordPayment(LedgerEntry $entry, float|int|string $amount, ?string $paymentDate = null): Payment
    {
        return DB::transaction(function () use ($entry, $amount, $paymentDate): Payment {
            $customerId = LedgerEntry::query()->whereKey($entry->id)->value('customer_id');
            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customerId);
            $lockedEntry = LedgerEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $paymentAmountInCents = Money::toCents($amount);

            if ($paymentAmountInCents === 0) {
                throw new \InvalidArgumentException('A payment amount must be greater than zero.');
            }

            $previousBalanceInCents = $this->outstandingBalanceInCents($lockedCustomer);
            $remainingBalanceInCents = Money::toCents($lockedEntry->amount)
                - Money::toCents((string) $lockedEntry->payments()->sum('amount'));

            if ($paymentAmountInCents > max(0, $remainingBalanceInCents)) {
                throw new PaymentExceedsBalance(
                    Money::fromCents(max(0, $remainingBalanceInCents)),
                    Money::fromCents($paymentAmountInCents),
                );
            }

            $payment = $this->createPaymentAllocation(
                $lockedCustomer,
                $lockedEntry,
                $paymentAmountInCents,
                $paymentDate ?? now()->toDateString(),
            );

            $newCustomerBalanceInCents = $this->outstandingBalanceInCents($lockedCustomer);
            $lockedCustomer->update(['current_balance' => Money::fromCents($newCustomerBalanceInCents)]);

            if ($previousBalanceInCents > 0 && $newCustomerBalanceInCents === 0) {
                $this->completeDebtCycle($lockedCustomer);
            }

            return $payment;
        });
    }

    /**
     * @param  array{amount: float|int|string, note?: string|null, idempotency_key: string, payment_date?: string}  $attributes
     * @return array{
     *     payment_batch_id: string,
     *     payments: Collection<int, Payment>,
     *     total_amount: string,
     *     previous_balance: string,
     *     remaining_balance: string,
     *     debt_cycle_completed: bool
     * }
     */
    public function recordCustomerPayment(Customer $customer, array $attributes): array
    {
        return DB::transaction(function () use ($customer, $attributes): array {
            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $existingPayment = Payment::query()
                ->where('idempotency_key', $attributes['idempotency_key'])
                ->first();

            if ($existingPayment) {
                if ($existingPayment->customer_id !== $lockedCustomer->id) {
                    throw new \InvalidArgumentException('This payment submission key belongs to another customer.');
                }

                $existingPayments = Payment::query()
                    ->where('payment_batch_id', $existingPayment->payment_batch_id)
                    ->orderBy('id')
                    ->get();
                $paidAmountInCents = $existingPayments->sum(
                    fn (Payment $payment): int => Money::toCents($payment->amount),
                );
                $remainingBalanceInCents = $this->outstandingBalanceInCents($lockedCustomer);
                $previousBalanceInCents = $remainingBalanceInCents + $paidAmountInCents;

                return [
                    'payment_batch_id' => $existingPayment->payment_batch_id,
                    'payments' => $existingPayments,
                    'total_amount' => Money::fromCents($paidAmountInCents),
                    'previous_balance' => Money::fromCents($previousBalanceInCents),
                    'remaining_balance' => Money::fromCents($remainingBalanceInCents),
                    'debt_cycle_completed' => $previousBalanceInCents > 0 && $remainingBalanceInCents === 0,
                ];
            }

            $paymentAmountInCents = Money::toCents($attributes['amount']);

            if ($paymentAmountInCents === 0) {
                throw new \InvalidArgumentException('A payment amount must be greater than zero.');
            }

            $previousBalanceInCents = $this->outstandingBalanceInCents($lockedCustomer);

            if ($paymentAmountInCents > $previousBalanceInCents) {
                throw new PaymentExceedsBalance(
                    Money::fromCents($previousBalanceInCents),
                    Money::fromCents($paymentAmountInCents),
                );
            }

            $paymentBatchId = Str::uuid()->toString();
            $remainingPaymentInCents = $paymentAmountInCents;
            $payments = collect();
            $paymentDate = $attributes['payment_date'] ?? now()->toDateString();
            $note = $attributes['note'] ?? null;
            $outstandingEntries = $lockedCustomer->ledgerEntries()
                ->withSum('payments as paid_amount', 'amount')
                ->orderBy('transaction_date')
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($outstandingEntries as $ledgerEntry) {
                if ($remainingPaymentInCents === 0) {
                    break;
                }

                $entryBalanceInCents = max(
                    0,
                    Money::toCents($ledgerEntry->amount)
                    - Money::toCents((string) ($ledgerEntry->paid_amount ?? '0')),
                );

                if ($entryBalanceInCents === 0) {
                    continue;
                }

                $allocatedAmountInCents = min($entryBalanceInCents, $remainingPaymentInCents);
                $isFirstAllocation = $payments->isEmpty();
                $payment = $this->createPaymentAllocation(
                    $lockedCustomer,
                    $ledgerEntry,
                    $allocatedAmountInCents,
                    $paymentDate,
                    $note,
                    $paymentBatchId,
                    $isFirstAllocation ? $attributes['idempotency_key'] : null,
                );
                $payments->push($payment);
                $remainingPaymentInCents -= $allocatedAmountInCents;
            }

            if ($remainingPaymentInCents > 0) {
                throw new PaymentExceedsBalance(
                    Money::fromCents($previousBalanceInCents),
                    Money::fromCents($paymentAmountInCents),
                );
            }

            $newCustomerBalanceInCents = $this->outstandingBalanceInCents($lockedCustomer);
            $lockedCustomer->update(['current_balance' => Money::fromCents($newCustomerBalanceInCents)]);
            $debtCycleCompleted = $previousBalanceInCents > 0 && $newCustomerBalanceInCents === 0;

            if ($debtCycleCompleted) {
                $this->completeDebtCycle($lockedCustomer);
            }

            return [
                'payment_batch_id' => $paymentBatchId,
                'payments' => $payments,
                'total_amount' => Money::fromCents($paymentAmountInCents),
                'previous_balance' => Money::fromCents($previousBalanceInCents),
                'remaining_balance' => Money::fromCents($newCustomerBalanceInCents),
                'debt_cycle_completed' => $debtCycleCompleted,
            ];
        });
    }

    private function createPaymentAllocation(
        Customer $customer,
        LedgerEntry $entry,
        int $amountInCents,
        string $paymentDate,
        ?string $note = null,
        ?string $paymentBatchId = null,
        ?string $idempotencyKey = null,
    ): Payment {
        $payment = $entry->payments()->create([
            'customer_id' => $customer->id,
            'amount' => Money::fromCents($amountInCents),
            'payment_date' => $paymentDate,
            'note' => $note,
            'payment_batch_id' => $paymentBatchId,
            'idempotency_key' => $idempotencyKey,
        ]);

        $this->awardRepaymentPoints($customer, $payment, $amountInCents);

        return $payment;
    }

    public function outstandingBalanceInCents(Customer $customer): int
    {
        $totalCreditsInCents = Money::toCents((string) $customer->ledgerEntries()->sum('amount'));
        $totalPaymentsInCents = Money::toCents((string) $customer->payments()->sum('amount'));

        return max(0, $totalCreditsInCents - $totalPaymentsInCents);
    }

    /**
     * @return array{outstanding_balance: string, available_credit: string, credit_limit: string}
     */
    public function financialSummary(Customer $customer): array
    {
        $outstandingBalanceInCents = $this->outstandingBalanceInCents($customer);
        $creditLimitInCents = Money::toCents($customer->credit_limit);

        return [
            'outstanding_balance' => Money::fromCents($outstandingBalanceInCents),
            'available_credit' => Money::fromCents(max(0, $creditLimitInCents - $outstandingBalanceInCents)),
            'credit_limit' => Money::fromCents($creditLimitInCents),
        ];
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
