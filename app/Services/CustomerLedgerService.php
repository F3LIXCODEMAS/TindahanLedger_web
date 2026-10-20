<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\SukiPoint;
use App\Support\Money;
use Illuminate\Support\Collection;

class CustomerLedgerService
{
    /**
     * Transaction types that add to what the customer owes.
     *
     * @var list<string>
     */
    public const DEBT_TYPES = ['credit', 'late_penalty'];

    public function __construct(
        private LedgerService $ledgerService,
        private LatePenaltyService $latePenaltyService,
        private FinancialSummaryService $financialSummaryService,
    ) {}

    /**
     * Totals come from FinancialSummaryService, the shared definition used by
     * the dashboard and overdue page too.
     *
     * @return array{
     *     outstanding_balance: string,
     *     principal_outstanding: string,
     *     penalty_outstanding: string,
     *     financials: array<string, mixed>,
     *     available_credit: string,
     *     is_overdue: bool,
     *     overdue_entry_count: int,
     *     transactions: Collection<int, array<string, mixed>>,
     *     rewards: array{
     *         points_balance: int,
     *         remainder_cents: int,
     *         remainder_amount: string,
     *         point_threshold: string,
     *         completed_cycles: int,
     *         current_streak: int,
     *         reward_threshold: int,
     *         cycles_until_reward: int,
     *         redemption_peso_per_point: string,
     *         max_redeemable_points: int
     *     }
     * }
     */
    public function forCustomer(Customer $customer): array
    {
        $credits = $customer->ledgerEntries()
            ->orderBy('transaction_date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $payments = $customer->payments()
            ->with('sukiPointTransaction')
            ->orderBy('payment_date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $financialSummary = $this->ledgerService->financialSummary($customer);
        $customerSummary = $this->financialSummaryService->forCustomer($customer);
        $financials = $customerSummary['totals'];
        $today = $this->financialSummaryService->today();
        $events = [];
        $penaltiesByCredit = $credits->filter(fn (LedgerEntry $entry): bool => $entry->isLatePenalty())->keyBy('penalty_for_entry_id');

        foreach ($credits as $entry) {
            $remainingAmountInCents = $customerSummary['entry_remaining_cents'][$entry->id] ?? 0;
            $isPenalty = $entry->isLatePenalty();
            $isOverdue = ! $isPenalty
                && $remainingAmountInCents > 0
                && $entry->repayment_deadline !== null
                && $entry->repayment_deadline->toDateString() < $today->toDateString();

            $penaltyStatus = $isPenalty ? null : $this->latePenaltyService->statusFor(
                $entry,
                $remainingAmountInCents,
                $penaltiesByCredit->get($entry->id),
                $today,
            );

            $events[] = [
                'id' => ($isPenalty ? 'penalty-' : 'credit-').$entry->id,
                'record_id' => $entry->id,
                'type' => $isPenalty ? 'late_penalty' : 'credit',
                'type_order' => 0,
                'points_redeemed' => null,
                'penalty_status' => $penaltyStatus,
                'penalty_entry_id' => $isPenalty ? $entry->id : null,
                'penalty_waived_at' => $isPenalty ? $entry->penalty_waived_at : null,
                'penalty_waiver_reason' => $isPenalty ? $entry->penalty_waiver_reason : null,
                'can_waive' => $isPenalty && $entry->penalty_waived_at === null && $remainingAmountInCents > 0,
                'date' => $entry->transaction_date,
                'recorded_at' => $entry->created_at,
                'amount_in_cents' => Money::toCents($entry->amount),
                'note' => $entry->note,
                'items' => array_values(array_map(
                    static fn (mixed $item): string => (string) $item,
                    $entry->itemized_list ?? [],
                )),
                'deadline' => $entry->repayment_deadline,
                'remaining_amount_in_cents' => $remainingAmountInCents,
                'is_overdue' => $isOverdue,
            ];
        }

        $paymentGroups = $payments->groupBy(
            fn (Payment $payment): string => $payment->payment_batch_id
                ? 'batch-'.$payment->payment_batch_id
                : 'payment-'.$payment->id,
        );

        foreach ($paymentGroups as $paymentGroup) {
            $firstPayment = $paymentGroup->first();
            $lastPayment = $paymentGroup->last();
            $isRedemption = $firstPayment->isLoyaltyRedemption();

            $events[] = [
                'id' => $firstPayment->payment_batch_id
                    ? 'payment-'.$firstPayment->payment_batch_id
                    : 'payment-'.$firstPayment->id,
                'record_id' => $lastPayment->id,
                'type' => match ($firstPayment->kind) {
                    Payment::KIND_LOYALTY_REDEMPTION => 'loyalty_redemption',
                    Payment::KIND_PENALTY_WAIVER => 'penalty_waiver',
                    default => 'payment',
                },
                'type_order' => 1,
                'points_redeemed' => $isRedemption ? ($firstPayment->sukiPointTransaction?->points_redeemed ?? 0) : null,
                'penalty_status' => null,
                'penalty_entry_id' => null,
                'penalty_waived_at' => null,
                'penalty_waiver_reason' => null,
                'can_waive' => false,
                'date' => $firstPayment->payment_date,
                'recorded_at' => $lastPayment->created_at,
                'amount_in_cents' => $paymentGroup->sum(
                    fn (Payment $payment): int => Money::toCents($payment->amount),
                ),
                'note' => $paymentGroup->first(fn (Payment $payment): bool => $payment->note !== null)?->note,
                'items' => [],
                'deadline' => null,
                'remaining_amount_in_cents' => null,
                'is_overdue' => false,
            ];
        }

        $transactions = collect($events)
            ->sort(function (array $left, array $right): int {
                $dateOrder = strcmp($left['date']->toDateString(), $right['date']->toDateString());

                if ($dateOrder !== 0) {
                    return $dateOrder;
                }

                $recordedAtOrder = strcmp(
                    $left['recorded_at']->format('Y-m-d H:i:s.u'),
                    $right['recorded_at']->format('Y-m-d H:i:s.u'),
                );

                if ($recordedAtOrder !== 0) {
                    return $recordedAtOrder;
                }

                $typeOrder = $left['type_order'] <=> $right['type_order'];

                return $typeOrder !== 0 ? $typeOrder : $left['record_id'] <=> $right['record_id'];
            })
            ->values();

        $runningBalanceInCents = 0;
        $transactions = $transactions->map(function (array $event) use (&$runningBalanceInCents): array {
            $runningBalanceInCents += in_array($event['type'], self::DEBT_TYPES, true)
                ? $event['amount_in_cents']
                : -$event['amount_in_cents'];

            return [
                'id' => $event['id'],
                'type' => $event['type'],
                'date' => $event['date'],
                'recorded_at' => $event['recorded_at'],
                'amount' => Money::fromCents($event['amount_in_cents']),
                'note' => $event['note'],
                'description' => match ($event['type']) {
                    'payment' => 'Cash payment',
                    'loyalty_redemption' => 'Suki Points redeemed as store credit',
                    'late_penalty' => 'Late payment fee',
                    'penalty_waiver' => 'Late payment fee waived (not cash)',
                    default => null,
                },
                'points_redeemed' => $event['points_redeemed'],
                'penalty_status' => $event['penalty_status'],
                'penalty_entry_id' => $event['penalty_entry_id'],
                'penalty_waived_at' => $event['penalty_waived_at'],
                'penalty_waiver_reason' => $event['penalty_waiver_reason'],
                'can_waive' => $event['can_waive'],
                'items' => $event['items'],
                'deadline' => $event['deadline'],
                'remaining_amount' => $event['remaining_amount_in_cents'] === null
                    ? null
                    : Money::fromCents($event['remaining_amount_in_cents']),
                'is_overdue' => $event['is_overdue'],
                'running_balance' => Money::fromCents($runningBalanceInCents),
            ];
        });

        $sukiPoints = $customer->sukiPoints()->first();
        $rewardThreshold = max(1, $sukiPoints?->reward_threshold ?? 3);
        $currentStreak = $sukiPoints?->current_cycle_count ?? 0;
        $remainderCents = $sukiPoints?->repayment_remainder_cents ?? 0;
        $pointsBalance = $sukiPoints?->points_balance ?? 0;

        return [
            'outstanding_balance' => $financialSummary['outstanding_balance'],
            'principal_outstanding' => $financials['principal_outstanding'],
            'penalty_outstanding' => $financials['penalties_outstanding'],
            'financials' => $financials,
            'available_credit' => $financialSummary['available_credit'],
            'is_overdue' => $financials['overdue_entry_count'] > 0,
            'overdue_entry_count' => $financials['overdue_entry_count'],
            'transactions' => $transactions,
            'rewards' => [
                'points_balance' => $pointsBalance,
                'remainder_cents' => $remainderCents,
                'remainder_amount' => Money::fromCents($remainderCents),
                'point_threshold' => Money::fromCents(SukiPoint::CENTS_PER_POINT),
                'completed_cycles' => $sukiPoints?->completed_cycles ?? 0,
                'current_streak' => $currentStreak,
                'reward_threshold' => $rewardThreshold,
                'cycles_until_reward' => max(0, $rewardThreshold - $currentStreak),
                'redemption_peso_per_point' => Money::fromCents(SukiPoint::REDEMPTION_CENTS_PER_POINT),
                'max_redeemable_points' => $this->ledgerService->maximumRedeemablePoints(
                    $pointsBalance,
                    Money::toCents($financialSummary['outstanding_balance']),
                ),
            ],
        ];
    }
}
