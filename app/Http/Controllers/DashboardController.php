<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Services\FinancialSummaryService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * "Outstanding principal" keeps the original meaning of the former Total
     * collectibles KPI (unpaid credit only); late fees and the total amount
     * due are shown as separate figures from FinancialSummaryService.
     */
    public function __invoke(Request $request, FinancialSummaryService $financialSummaryService): View
    {
        $store = $request->user()->store;
        $storeId = $store->getKey();
        $storeSummary = $financialSummaryService->forStore($store);

        $recentCredits = LedgerEntry::query()
            ->with('customer')
            ->whereRelation('customer', 'store_id', $storeId)
            ->latest('created_at')
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (LedgerEntry $entry): array => [
                'id' => 'credit-'.$entry->id,
                'type' => $entry->isLatePenalty() ? 'Late payment fee' : 'Credit recorded',
                'customer_name' => $entry->customer?->full_name ?? 'Unknown customer',
                'amount' => $entry->amount,
                'created_at' => $entry->created_at,
                'tie_priority' => 0,
            ]);

        $recentBatchedPayments = DB::table('payments')
            ->join('customers', 'customers.id', '=', 'payments.customer_id')
            ->select('payments.payment_batch_id', 'payments.customer_id', 'payments.kind', 'customers.full_name')
            ->selectRaw('SUM(payments.amount) AS amount')
            ->selectRaw('MAX(payments.created_at) AS created_at')
            ->selectRaw('MAX(payments.id) AS id')
            ->where('customers.store_id', $storeId)
            ->whereNotNull('payments.payment_batch_id')
            ->groupBy('payments.payment_batch_id', 'payments.customer_id', 'payments.kind', 'customers.full_name')
            ->orderByDesc(DB::raw('MAX(payments.created_at)'))
            ->orderByDesc(DB::raw('MAX(payments.id)'))
            ->limit(8)
            ->get()
            ->map(fn (object $payment): array => [
                'id' => 'payment-'.$payment->payment_batch_id,
                'type' => match ($payment->kind) {
                    Payment::KIND_LOYALTY_REDEMPTION => 'Suki Points redeemed',
                    Payment::KIND_PENALTY_WAIVER => 'Late payment fee waived',
                    default => 'Payment received',
                },
                'customer_name' => $payment->full_name,
                'amount' => Money::fromCents(Money::toCents((string) $payment->amount)),
                'created_at' => Carbon::parse($payment->created_at),
                'tie_priority' => 1,
            ]);

        $recentLegacyPayments = Payment::query()
            ->with('customer')
            ->whereRelation('customer', 'store_id', $storeId)
            ->whereNull('payment_batch_id')
            ->latest('created_at')
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (Payment $payment): array => [
                'id' => 'payment-'.$payment->id,
                'type' => 'Payment received',
                'customer_name' => $payment->customer?->full_name ?? 'Unknown customer',
                'amount' => $payment->amount,
                'created_at' => $payment->created_at,
                'tie_priority' => 1,
            ]);

        $recentPayments = $recentBatchedPayments->concat($recentLegacyPayments);

        $recentActivity = $recentCredits
            ->concat($recentPayments)
            ->sort(function (array $left, array $right): int {
                $createdAtOrder = $right['created_at']->getTimestamp() <=> $left['created_at']->getTimestamp();

                return $createdAtOrder !== 0
                    ? $createdAtOrder
                    : $right['tie_priority'] <=> $left['tie_priority'];
            })
            ->take(8)
            ->map(function (array $activity): array {
                unset($activity['tie_priority']);

                return $activity;
            })
            ->values();

        $recentPenaltyCount = LedgerEntry::query()
            ->where('kind', LedgerEntry::KIND_LATE_PENALTY)
            ->whereRelation('customer', 'store_id', $storeId)
            ->where('transaction_date', '>=', now()->subDays(6)->toDateString())
            ->count();

        return view('dashboard', [
            'outstandingPrincipal' => $storeSummary['totals']['principal_outstanding'],
            'outstandingPenalties' => $storeSummary['totals']['penalties_outstanding'],
            'totalAmountDue' => $storeSummary['totals']['total_due'],
            'overdueAccountCount' => $storeSummary['overdue_account_count'],
            'customersWithOutstandingPenalties' => $storeSummary['customers_with_outstanding_penalties'],
            'overdueCreditPenaltyStates' => $storeSummary['overdue_credit_penalty_states'],
            'recentPenaltyCount' => $recentPenaltyCount,
            'recentActivity' => $recentActivity,
            'storeIsSuspended' => ! $request->user()->store->isActive(),
        ]);
    }
}
