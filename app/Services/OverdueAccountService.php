<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Store;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;

class OverdueAccountService
{
    public function __construct(private readonly LatePenaltyService $latePenaltyService) {}

    public function paginate(Store $store, string $search, string $sort): LengthAwarePaginator
    {
        $timezone = (string) config('app.timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $penaltyToday = $this->latePenaltyService->today();

        $isUnpaidAndOverdue = static function (Builder|Relation $query) use ($today): void {
            $query
                ->whereNotNull('repayment_deadline')
                ->whereDate('repayment_deadline', '<', $today->toDateString())
                ->whereRaw(
                    'ledger_entries.amount > COALESCE((SELECT SUM(payments.amount) FROM payments WHERE payments.ledger_entry_id = ledger_entries.id), 0)',
                );
        };

        $earliestOverdueDeadline = LedgerEntry::query()
            ->selectRaw('MIN(ledger_entries.repayment_deadline)')
            ->whereColumn('ledger_entries.customer_id', 'customers.id');
        $isUnpaidAndOverdue($earliestOverdueDeadline);

        $creditTotal = '(SELECT COALESCE(SUM(ledger_entries.amount), 0) FROM ledger_entries WHERE ledger_entries.customer_id = customers.id)';
        $paymentTotal = '(SELECT COALESCE(SUM(payments.amount), 0) FROM payments WHERE payments.customer_id = customers.id)';
        $outstandingBalanceSort = '('.$creditTotal.' - '.$paymentTotal.')';

        $customersQuery = Customer::query()
            ->select('customers.*')
            ->where('customers.store_id', $store->getKey())
            ->selectSub($earliestOverdueDeadline, 'earliest_overdue_deadline')
            ->withSum('ledgerEntries as ledger_credit_total', 'amount')
            ->withSum('payments as customer_payment_total', 'amount')
            ->with([
                'ledgerEntries' => function (Relation $query) use ($isUnpaidAndOverdue): void {
                    $isUnpaidAndOverdue($query);
                    $query
                        ->withSum('payments as paid_amount', 'amount')
                        ->with([
                            'latePenalty',
                            'emailReminderLogs' => fn (Relation $reminders): Relation => $reminders
                                ->orderByDesc('scheduled_reminder_date')
                                ->orderByDesc('id'),
                        ])
                        ->orderBy('repayment_deadline')
                        ->orderBy('transaction_date')
                        ->orderBy('id');
                },
            ])
            ->whereHas('ledgerEntries', $isUnpaidAndOverdue)
            ->when($search !== '', fn (Builder $query): Builder => $query
                ->where('full_name', 'like', '%'.$search.'%'));

        if ($sort === 'balance') {
            $customersQuery
                ->orderByRaw($outstandingBalanceSort.' DESC')
                ->orderBy('earliest_overdue_deadline')
                ->orderBy('full_name');
        } else {
            $customersQuery
                ->orderBy('earliest_overdue_deadline')
                ->orderByRaw($outstandingBalanceSort.' DESC')
                ->orderBy('full_name');
        }

        $customers = $customersQuery
            ->orderBy('customers.id')
            ->paginate(20)
            ->withQueryString();

        $customers->getCollection()->each(function (Customer $customer) use ($today, $timezone, $penaltyToday): void {
            $creditTotalInCents = Money::toCents((string) ($customer->ledger_credit_total ?? '0'));
            $paymentTotalInCents = Money::toCents((string) ($customer->customer_payment_total ?? '0'));
            $customer->setAttribute(
                'outstanding_balance',
                Money::fromCents(max(0, $creditTotalInCents - $paymentTotalInCents)),
            );

            $overdueDays = 0;

            foreach ($customer->ledgerEntries as $entry) {
                $paidAmountInCents = Money::toCents((string) ($entry->paid_amount ?? '0'));
                $remainingAmountInCents = max(
                    0,
                    Money::toCents($entry->amount) - $paidAmountInCents,
                );
                $deadline = CarbonImmutable::parse($entry->repayment_deadline->toDateString(), $timezone)
                    ->startOfDay();
                $entryOverdueDays = (int) $deadline->diffInDays($today);

                $entry->setAttribute('outstanding_balance', Money::fromCents($remainingAmountInCents));
                $entry->setAttribute('days_overdue', $entryOverdueDays);
                $entry->setAttribute('penalty_status', $this->latePenaltyService->statusFor($entry, $remainingAmountInCents, $entry->latePenalty, $penaltyToday));
                $overdueDays = max($overdueDays, $entryOverdueDays);
            }

            $customer->setAttribute('overdue_days', $overdueDays);
            $customer->setAttribute(
                'earliest_overdue_deadline',
                CarbonImmutable::parse((string) $customer->getAttribute('earliest_overdue_deadline'), $timezone)
                    ->startOfDay(),
            );
        });

        return $customers;
    }
}
