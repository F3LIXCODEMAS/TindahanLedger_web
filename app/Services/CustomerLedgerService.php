<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\SukiPoint;
use App\Support\Money;
use Illuminate\Support\Collection;

class CustomerLedgerService
{
    public function __construct(private LedgerService $ledgerService) {}

    /**
     * @return array{
     *     outstanding_balance: string,
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
     *         cycle_progress: int,
     *         reward_threshold: int,
     *         reward_achieved: bool
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
            ->orderBy('payment_date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $paymentsByEntry = $payments->groupBy('ledger_entry_id');
        $paidAmountsByEntry = [];

        foreach ($paymentsByEntry as $ledgerEntryId => $entryPayments) {
            $paidAmountsByEntry[$ledgerEntryId] = $entryPayments->sum(
                fn (Payment $payment): int => Money::toCents($payment->amount),
            );
        }

        $financialSummary = $this->ledgerService->financialSummary($customer);
        $overdueEntryCount = 0;
        $events = [];

        foreach ($credits as $entry) {
            $remainingAmountInCents = max(
                0,
                Money::toCents($entry->amount) - ($paidAmountsByEntry[$entry->id] ?? 0),
            );
            $isOverdue = $remainingAmountInCents > 0
                && $entry->repayment_deadline !== null
                && $entry->repayment_deadline->lt(today());

            if ($isOverdue) {
                $overdueEntryCount++;
            }

            $events[] = [
                'id' => 'credit-'.$entry->id,
                'record_id' => $entry->id,
                'type' => 'credit',
                'type_order' => 0,
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

            $events[] = [
                'id' => $firstPayment->payment_batch_id
                    ? 'payment-'.$firstPayment->payment_batch_id
                    : 'payment-'.$firstPayment->id,
                'record_id' => $lastPayment->id,
                'type' => 'payment',
                'type_order' => 1,
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
            $runningBalanceInCents += $event['type'] === 'credit'
                ? $event['amount_in_cents']
                : -$event['amount_in_cents'];

            return [
                'id' => $event['id'],
                'type' => $event['type'],
                'date' => $event['date'],
                'recorded_at' => $event['recorded_at'],
                'amount' => Money::fromCents($event['amount_in_cents']),
                'note' => $event['note'],
                'description' => $event['type'] === 'payment' ? 'Cash payment' : null,
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
        $completedCycles = $sukiPoints?->completed_cycles ?? 0;
        $remainderCents = $sukiPoints?->repayment_remainder_cents ?? 0;

        return [
            'outstanding_balance' => $financialSummary['outstanding_balance'],
            'available_credit' => $financialSummary['available_credit'],
            'is_overdue' => $overdueEntryCount > 0,
            'overdue_entry_count' => $overdueEntryCount,
            'transactions' => $transactions,
            'rewards' => [
                'points_balance' => $sukiPoints?->points_balance ?? 0,
                'remainder_cents' => $remainderCents,
                'remainder_amount' => Money::fromCents($remainderCents),
                'point_threshold' => Money::fromCents(SukiPoint::CENTS_PER_POINT),
                'completed_cycles' => $completedCycles,
                'cycle_progress' => min($completedCycles, $rewardThreshold),
                'reward_threshold' => $rewardThreshold,
                'reward_achieved' => $completedCycles >= $rewardThreshold,
            ],
        ];
    }
}
