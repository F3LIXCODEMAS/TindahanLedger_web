<?php

namespace App\Services;

use App\Exceptions\CreditLimitExceeded;
use App\Exceptions\PaymentExceedsBalance;
use App\Exceptions\StoreSuspended;
use App\Exceptions\SukiPointRedemptionRejected;
use App\Models\Customer;
use App\Models\DebtCycleCompletion;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Store;
use App\Models\StorePenaltyPolicy;
use App\Models\SukiPoint;
use App\Models\SukiPointTransaction;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LedgerService
{
    /**
     * A credit with a repayment deadline falls under the store's late payment
     * policy only when that policy is active and the owner confirmed the
     * customer was told about it (`penalty_policy_acknowledged`). The policy
     * values are copied onto the credit, so later policy changes never
     * affect it.
     *
     * @param  array{amount: float|int|string, itemized_list?: array<int, mixed>|null, note?: string|null, transaction_date?: string, repayment_deadline?: string|null, idempotency_key?: string|null, penalty_policy_acknowledged?: bool}  $attributes
     */
    public function recordCredit(Customer $customer, array $attributes): LedgerEntry
    {
        return DB::transaction(function () use ($customer, $attributes): LedgerEntry {
            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $this->ensureStoreIsActive($lockedCustomer);

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
                'penalty_policy_snapshot' => $this->penaltyPolicySnapshot($lockedCustomer, $attributes),
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
            $this->ensureStoreIsActive($lockedCustomer);
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
                $this->completeDebtCycle($lockedCustomer, $payment);
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
            $this->ensureStoreIsActive($lockedCustomer);
            $existingPayment = Payment::query()
                ->where('idempotency_key', $attributes['idempotency_key'])
                ->first();

            if ($existingPayment) {
                if ($existingPayment->customer_id !== $lockedCustomer->id || $existingPayment->isLoyaltyRedemption()) {
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
            $payments = $this->allocateOldestFirst(
                $lockedCustomer,
                $paymentAmountInCents,
                $attributes['payment_date'] ?? now()->toDateString(),
                $attributes['note'] ?? null,
                $paymentBatchId,
                $attributes['idempotency_key'],
                Payment::KIND_CASH,
            );

            if ($payments === null) {
                throw new PaymentExceedsBalance(
                    Money::fromCents($previousBalanceInCents),
                    Money::fromCents($paymentAmountInCents),
                );
            }

            $newCustomerBalanceInCents = $this->outstandingBalanceInCents($lockedCustomer);
            $lockedCustomer->update(['current_balance' => Money::fromCents($newCustomerBalanceInCents)]);
            $debtCycleCompleted = $previousBalanceInCents > 0 && $newCustomerBalanceInCents === 0;

            if ($debtCycleCompleted) {
                $this->completeDebtCycle($lockedCustomer, $payments->last());
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

    /**
     * Redeem Suki Points as a non-cash credit against the customer's debt.
     *
     * One point is worth SukiPoint::REDEMPTION_CENTS_PER_POINT. The credit is
     * applied to the oldest unpaid credits first, never exceeds the current
     * outstanding balance, earns no points, never completes a debt cycle, and
     * is recorded as a `loyalty_redemption` allocation plus one points-ledger
     * row. Points are deducted in the same transaction, with the customer and
     * points rows locked, so the same points can never be spent twice.
     * Repeating a request with the same idempotency key returns the original
     * redemption without deducting again.
     *
     * @return array{
     *     payment_batch_id: string,
     *     points_redeemed: int,
     *     redeemed_amount: string,
     *     previous_balance: string,
     *     remaining_balance: string,
     *     points_balance: int,
     *     was_repeated: bool
     * }
     */
    public function redeemSukiPoints(Customer $customer, int $points, string $idempotencyKey, User $owner): array
    {
        return DB::transaction(function () use ($customer, $points, $idempotencyKey, $owner): array {
            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $this->ensureStoreIsActive($lockedCustomer);
            $sukiPoints = SukiPoint::query()->where('customer_id', $lockedCustomer->id)->lockForUpdate()->first();
            $existingRedemption = Payment::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existingRedemption) {
                if ($existingRedemption->customer_id !== $lockedCustomer->id || ! $existingRedemption->isLoyaltyRedemption()) {
                    throw new \InvalidArgumentException('This redemption submission key belongs to another transaction.');
                }

                $pointsLedgerRow = SukiPointTransaction::query()
                    ->where('payment_id', $existingRedemption->id)
                    ->firstOrFail();
                $remainingBalanceInCents = $this->outstandingBalanceInCents($lockedCustomer);

                return [
                    'payment_batch_id' => $existingRedemption->payment_batch_id,
                    'points_redeemed' => $pointsLedgerRow->points_redeemed,
                    'redeemed_amount' => Money::fromCents($pointsLedgerRow->redeemed_amount_cents),
                    'previous_balance' => Money::fromCents($remainingBalanceInCents + $pointsLedgerRow->redeemed_amount_cents),
                    'remaining_balance' => Money::fromCents($remainingBalanceInCents),
                    'points_balance' => $sukiPoints?->points_balance ?? 0,
                    'was_repeated' => true,
                ];
            }

            $availablePoints = $sukiPoints?->points_balance ?? 0;
            $previousBalanceInCents = $this->outstandingBalanceInCents($lockedCustomer);
            $maximumRedeemablePoints = $this->maximumRedeemablePoints($availablePoints, $previousBalanceInCents);

            if ($points < 1) {
                throw new SukiPointRedemptionRejected('Enter at least 1 point to redeem.');
            }

            if ($previousBalanceInCents === 0) {
                throw new SukiPointRedemptionRejected('This customer has no outstanding balance to reduce.');
            }

            if ($points > $availablePoints) {
                throw new SukiPointRedemptionRejected(sprintf(
                    'This customer only has %s Suki %s available.',
                    number_format($availablePoints),
                    $availablePoints === 1 ? 'Point' : 'Points',
                ));
            }

            $redeemedAmountInCents = $points * SukiPoint::REDEMPTION_CENTS_PER_POINT;

            if ($redeemedAmountInCents > $previousBalanceInCents) {
                throw new SukiPointRedemptionRejected(sprintf(
                    'Redeeming %s points (₱%s) would exceed the outstanding balance of ₱%s. The most that can be redeemed now is %s points.',
                    number_format($points),
                    Money::fromCents($redeemedAmountInCents),
                    Money::fromCents($previousBalanceInCents),
                    number_format($maximumRedeemablePoints),
                ));
            }

            $paymentBatchId = Str::uuid()->toString();
            $allocations = $this->allocateOldestFirst(
                $lockedCustomer,
                $redeemedAmountInCents,
                now()->toDateString(),
                'Suki Points redemption: '.number_format($points).' '.($points === 1 ? 'point' : 'points'),
                $paymentBatchId,
                $idempotencyKey,
                Payment::KIND_LOYALTY_REDEMPTION,
            );

            if ($allocations === null) {
                throw new SukiPointRedemptionRejected('The redemption could not be applied to the outstanding credits.');
            }

            $sukiPoints->update(['points_balance' => $availablePoints - $points]);
            $lockedCustomer->sukiPointTransactions()->create([
                'payment_id' => $allocations->first()->id,
                'transaction_type' => SukiPointTransaction::TYPE_REDEEMED,
                'paid_amount_cents' => 0,
                'points_awarded' => 0,
                'points_redeemed' => $points,
                'redeemed_amount_cents' => $redeemedAmountInCents,
                'remainder_before_cents' => $sukiPoints->repayment_remainder_cents,
                'remainder_after_cents' => $sukiPoints->repayment_remainder_cents,
                'recorded_by_user_id' => $owner->id,
            ]);

            $newBalanceInCents = $this->outstandingBalanceInCents($lockedCustomer);
            $lockedCustomer->update(['current_balance' => Money::fromCents($newBalanceInCents)]);

            return [
                'payment_batch_id' => $paymentBatchId,
                'points_redeemed' => $points,
                'redeemed_amount' => Money::fromCents($redeemedAmountInCents),
                'previous_balance' => Money::fromCents($previousBalanceInCents),
                'remaining_balance' => Money::fromCents($newBalanceInCents),
                'points_balance' => $availablePoints - $points,
                'was_repeated' => false,
            ];
        });
    }

    /**
     * The most points that can be redeemed right now: limited by the points
     * available and by the outstanding balance, in whole points.
     */
    public function maximumRedeemablePoints(int $availablePoints, int $outstandingBalanceInCents): int
    {
        return max(0, min($availablePoints, intdiv($outstandingBalanceInCents, SukiPoint::REDEMPTION_CENTS_PER_POINT)));
    }

    /**
     * Apply an amount to the customer's unpaid credits, oldest first, as
     * allocations sharing one batch. Returns null when the unpaid credits
     * cannot absorb the whole amount. Only cash allocations earn points.
     *
     * @return Collection<int, Payment>|null
     */
    private function allocateOldestFirst(
        Customer $lockedCustomer,
        int $amountInCents,
        string $paymentDate,
        ?string $note,
        string $paymentBatchId,
        string $idempotencyKey,
        string $kind,
    ): ?Collection {
        $remainingAmountInCents = $amountInCents;
        $payments = collect();
        $outstandingEntries = $lockedCustomer->ledgerEntries()
            ->withSum('payments as paid_amount', 'amount')
            ->orderBy('transaction_date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($outstandingEntries as $ledgerEntry) {
            if ($remainingAmountInCents === 0) {
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

            $allocatedAmountInCents = min($entryBalanceInCents, $remainingAmountInCents);
            $isFirstAllocation = $payments->isEmpty();
            $payments->push($this->createPaymentAllocation(
                $lockedCustomer,
                $ledgerEntry,
                $allocatedAmountInCents,
                $paymentDate,
                $note,
                $paymentBatchId,
                $isFirstAllocation ? $idempotencyKey : null,
                $kind,
            ));
            $remainingAmountInCents -= $allocatedAmountInCents;
        }

        return $remainingAmountInCents > 0 ? null : $payments;
    }

    private function createPaymentAllocation(
        Customer $customer,
        LedgerEntry $entry,
        int $amountInCents,
        string $paymentDate,
        ?string $note = null,
        ?string $paymentBatchId = null,
        ?string $idempotencyKey = null,
        string $kind = Payment::KIND_CASH,
    ): Payment {
        $payment = $entry->payments()->create([
            'customer_id' => $customer->id,
            'amount' => Money::fromCents($amountInCents),
            'kind' => $kind,
            'payment_date' => $paymentDate,
            'note' => $note,
            'payment_batch_id' => $paymentBatchId,
            'idempotency_key' => $idempotencyKey,
        ]);

        if ($kind === Payment::KIND_CASH) {
            $this->awardRepaymentPoints($customer, $payment, $amountInCents);
        }

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, int|string>|null
     */
    private function penaltyPolicySnapshot(Customer $customer, array $attributes): ?array
    {
        if (empty($attributes['repayment_deadline']) || ($attributes['penalty_policy_acknowledged'] ?? false) !== true) {
            return null;
        }

        $policy = StorePenaltyPolicy::query()->where('store_id', $customer->store_id)->first();
        $today = CarbonImmutable::now((string) config('app.timezone'))->startOfDay();

        return $policy?->appliesOn($today) ? $policy->snapshot() : null;
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

    /**
     * Read the store status with a shared lock so a concurrent suspension
     * waits for in-flight credits and payments to finish, and any write that
     * starts after the suspension commits is rejected.
     */
    private function ensureStoreIsActive(Customer $lockedCustomer): void
    {
        $storeStatus = Store::query()
            ->whereKey($lockedCustomer->store_id)
            ->sharedLock()
            ->value('status');

        if ($storeStatus !== Store::STATUS_ACTIVE) {
            throw new StoreSuspended;
        }
    }

    /**
     * Record a completed debt cycle and, on the last cycle of a streak, give
     * the three-cycle reward: one point per configured peso amount of cash
     * repaid during the streak. Runs inside the payment transaction with the
     * customer row locked; the unique completing payment and cycle number
     * make a second completion or reward for the same cycle impossible.
     */
    private function completeDebtCycle(Customer $customer, Payment $completingPayment): DebtCycleCompletion
    {
        $sukiPoints = $customer->sukiPoints()->firstOrCreate([
            'customer_id' => $customer->id,
        ]);
        $streakPosition = $sukiPoints->current_cycle_count + 1;
        $completesStreak = $streakPosition >= $sukiPoints->reward_threshold;
        $rewardPoints = $completesStreak
            ? intdiv($sukiPoints->streak_repaid_cents, $this->cycleRewardCentsPerPoint())
            : 0;

        $completion = new DebtCycleCompletion;
        $completion->forceFill([
            'customer_id' => $customer->id,
            'completing_payment_id' => $completingPayment->id,
            'cycle_number' => $sukiPoints->completed_cycles + 1,
            'streak_position' => $streakPosition,
            'repaid_cents' => $sukiPoints->cycle_repaid_cents,
            'reward_points' => $rewardPoints,
            'source' => DebtCycleCompletion::SOURCE_PAYMENT,
            'completed_at' => now(),
        ])->save();

        $sukiPoints->update([
            'completed_cycles' => $sukiPoints->completed_cycles + 1,
            'current_cycle_count' => $completesStreak ? 0 : $streakPosition,
            'points_balance' => $sukiPoints->points_balance + $rewardPoints,
            'cycle_repaid_cents' => 0,
            'streak_repaid_cents' => $completesStreak ? 0 : $sukiPoints->streak_repaid_cents,
            'reward_eligible' => false,
        ]);

        return $completion;
    }

    private function cycleRewardCentsPerPoint(): int
    {
        return max(1, (int) config('suki_points.cycle_reward_pesos_per_point')) * 100;
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
            'cycle_repaid_cents' => $sukiPoints->cycle_repaid_cents + $paymentAmountInCents,
            'streak_repaid_cents' => $sukiPoints->streak_repaid_cents + $paymentAmountInCents,
        ]);
    }
}
