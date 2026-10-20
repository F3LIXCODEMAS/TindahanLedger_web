<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Store;
use App\Models\StorePenaltyPolicy;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The only place that decides and records late payment penalties.
 *
 * Rule: a credit accepted under an enabled store policy and given a
 * repayment deadline gets one fixed fee once the grace period has fully
 * passed and part of it is still unpaid. Grace days are whole calendar days
 * in the application timezone: deadline Oct 10 with 3 grace days means
 * Oct 11–13 are grace days and the fee may be applied from Oct 14.
 *
 * The fee is recorded as its own `late_penalty` ledger entry, so the
 * original credit and its payments are never changed, and a unique key on
 * `penalty_for_entry_id` stops a second fee on the same credit even when two
 * processes run at once.
 */
class LatePenaltyService
{
    public const STATE_NOT_COVERED = 'not_covered';

    public const STATE_NOT_DUE = 'not_due';

    public const STATE_IN_GRACE = 'in_grace';

    public const STATE_ELIGIBLE = 'eligible';

    public const STATE_PAID_IN_TIME = 'paid';

    public const STATE_APPLIED = 'applied';

    public const STATE_WAIVED = 'waived';

    public function __construct(private readonly LedgerService $ledgerService) {}

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.timezone'))->startOfDay();
    }

    /**
     * The store's policy if a credit accepted today is covered by it.
     */
    public function activePolicyFor(Store $store, ?CarbonImmutable $today = null): ?StorePenaltyPolicy
    {
        $policy = $store->penaltyPolicy;

        return $policy?->appliesOn($today ?? $this->today()) ? $policy : null;
    }

    /**
     * The last grace day, or null when the credit is not covered.
     */
    public function graceEndsOn(LedgerEntry $credit): ?CarbonImmutable
    {
        $snapshot = $credit->penalty_policy_snapshot;

        if ($credit->isLatePenalty() || ! is_array($snapshot) || $credit->repayment_deadline === null) {
            return null;
        }

        return CarbonImmutable::parse($credit->repayment_deadline->toDateString(), (string) config('app.timezone'))
            ->addDays((int) $snapshot['grace_days']);
    }

    /**
     * Where a credit stands, for the ledger and overdue screens.
     *
     * @return array{state: string, grace_ends_on: CarbonImmutable|null, fee: string|null, penalty: LedgerEntry|null}
     */
    public function statusFor(LedgerEntry $credit, int $remainingCents, ?LedgerEntry $penalty, ?CarbonImmutable $today = null): array
    {
        $today ??= $this->today();
        $graceEndsOn = $this->graceEndsOn($credit);
        $fee = $graceEndsOn ? Money::fromCents($this->feeCents($credit)) : null;
        $state = match (true) {
            $penalty !== null => $penalty->penalty_waived_at ? self::STATE_WAIVED : self::STATE_APPLIED,
            $graceEndsOn === null => self::STATE_NOT_COVERED,
            $remainingCents === 0 => self::STATE_PAID_IN_TIME,
            $today->lessThanOrEqualTo($credit->repayment_deadline) => self::STATE_NOT_DUE,
            $today->lessThanOrEqualTo($graceEndsOn) => self::STATE_IN_GRACE,
            default => self::STATE_ELIGIBLE,
        };

        return ['state' => $state, 'grace_ends_on' => $graceEndsOn, 'fee' => $fee, 'penalty' => $penalty];
    }

    /**
     * Apply every penalty that is due. Safe to run repeatedly; each credit is
     * handled in its own transaction so one failure never affects another.
     *
     * @return array{checked: int, applied: int, skipped: int, failed: int}
     */
    public function applyDuePenalties(?CarbonImmutable $today = null): array
    {
        $today ??= $this->today();
        $summary = ['checked' => 0, 'applied' => 0, 'skipped' => 0, 'failed' => 0];

        LedgerEntry::query()
            ->credits()
            ->whereNotNull('penalty_policy_snapshot')
            ->whereNotNull('repayment_deadline')
            ->where('repayment_deadline', '<', $today->toDateString())
            ->whereDoesntHave('latePenalty')
            ->whereRaw('ledger_entries.amount > COALESCE((SELECT SUM(payments.amount) FROM payments WHERE payments.ledger_entry_id = ledger_entries.id), 0)')
            ->orderBy('id')
            ->chunkById(100, function ($credits) use ($today, &$summary): void {
                foreach ($credits as $credit) {
                    $summary['checked']++;

                    try {
                        $summary[$this->applyTo($credit, $today) ? 'applied' : 'skipped']++;
                    } catch (Throwable $exception) {
                        $summary['failed']++;
                        Log::warning('Late penalty could not be applied', [
                            'ledger_entry_id' => $credit->getKey(),
                            'exception' => $exception::class,
                        ]);
                    }
                }
            });

        return $summary;
    }

    /**
     * Apply the fee to one credit if it is still due. Returns false when it is
     * not (yet) eligible, already penalised, paid, or the store is suspended.
     */
    public function applyTo(LedgerEntry $credit, ?CarbonImmutable $today = null): bool
    {
        $today ??= $this->today();

        try {
            return DB::transaction(function () use ($credit, $today): bool {
                $customer = Customer::query()->lockForUpdate()->findOrFail($credit->customer_id);
                $store = Store::query()->whereKey($customer->store_id)->sharedLock()->firstOrFail();
                $lockedCredit = LedgerEntry::query()->lockForUpdate()->findOrFail($credit->getKey());

                if (! $store->isActive() || $lockedCredit->latePenalty()->exists()) {
                    return false;
                }

                $remainingCents = $this->remainingCents($lockedCredit);

                if ($this->statusFor($lockedCredit, $remainingCents, null, $today)['state'] !== self::STATE_ELIGIBLE) {
                    return false;
                }

                $feeCents = $this->feeCents($lockedCredit);
                $newBalanceCents = $this->ledgerService->outstandingBalanceInCents($customer) + $feeCents;
                $penalty = new LedgerEntry([
                    'customer_id' => $customer->getKey(),
                    'amount' => Money::fromCents($feeCents),
                    'note' => sprintf(
                        'Late payment fee: ₱%s credit dated %s was not fully paid by %s (deadline plus %d grace %s).',
                        $lockedCredit->amount,
                        $lockedCredit->transaction_date->format('M j, Y'),
                        $this->graceEndsOn($lockedCredit)->format('M j, Y'),
                        $lockedCredit->penalty_policy_snapshot['grace_days'],
                        $lockedCredit->penalty_policy_snapshot['grace_days'] === 1 ? 'day' : 'days',
                    ),
                    'transaction_date' => $today->toDateString(),
                    'repayment_deadline' => null,
                    'running_balance' => Money::fromCents($newBalanceCents),
                ]);
                $penalty->forceFill([
                    'kind' => LedgerEntry::KIND_LATE_PENALTY,
                    'penalty_for_entry_id' => $lockedCredit->getKey(),
                ])->save();

                $customer->update(['current_balance' => Money::fromCents($newBalanceCents)]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // Another process applied the fee to this credit first.
            return false;
        }
    }

    /**
     * Waive what is still unpaid on a penalty. Recorded as a separate
     * `penalty_waiver` adjustment; the penalty record stays and is marked
     * waived with the owner and reason.
     */
    public function waive(LedgerEntry $penalty, User $owner, string $reason): void
    {
        DB::transaction(function () use ($penalty, $owner, $reason): void {
            $customer = Customer::query()->lockForUpdate()->findOrFail($penalty->customer_id);
            $store = Store::query()->whereKey($customer->store_id)->sharedLock()->firstOrFail();
            $lockedPenalty = LedgerEntry::query()->lockForUpdate()->findOrFail($penalty->getKey());
            $remainingCents = $this->remainingCents($lockedPenalty);

            if (! $store->isActive()) {
                throw ValidationException::withMessages(['reason' => 'This store is suspended. Penalties cannot be waived until it is reactivated.']);
            }

            if (! $lockedPenalty->isLatePenalty() || $lockedPenalty->penalty_waived_at !== null || $remainingCents === 0) {
                throw ValidationException::withMessages(['reason' => 'This penalty has already been paid or waived.']);
            }

            $lockedPenalty->payments()->create([
                'customer_id' => $customer->getKey(),
                'amount' => Money::fromCents($remainingCents),
                'kind' => Payment::KIND_PENALTY_WAIVER,
                'payment_date' => $this->today()->toDateString(),
                'note' => 'Penalty waived: '.$reason,
                'payment_batch_id' => (string) Str::uuid(),
            ]);
            $lockedPenalty->forceFill([
                'penalty_waived_at' => now(),
                'penalty_waived_by_user_id' => $owner->getKey(),
                'penalty_waiver_reason' => $reason,
            ])->save();

            $customer->update(['current_balance' => Money::fromCents($this->ledgerService->outstandingBalanceInCents($customer))]);
        });
    }

    public function remainingCents(LedgerEntry $entry): int
    {
        return max(0, Money::toCents($entry->amount) - Money::toCents((string) $entry->payments()->sum('amount')));
    }

    /**
     * The fee agreed when the credit was accepted, never above today's approved maximum.
     */
    private function feeCents(LedgerEntry $credit): int
    {
        return min((int) $credit->penalty_policy_snapshot['fee_cents'], (int) config('penalties.max_fee_cents'));
    }
}
