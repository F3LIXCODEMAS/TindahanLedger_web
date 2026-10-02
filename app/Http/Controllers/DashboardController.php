<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $paymentsByEntry = DB::table('payments')
            ->select('ledger_entry_id')
            ->selectRaw('SUM(amount) AS total_paid')
            ->groupBy('ledger_entry_id');

        $totalOutstanding = DB::table('ledger_entries')
            ->leftJoinSub($paymentsByEntry, 'payments_by_entry', function ($join): void {
                $join->on('payments_by_entry.ledger_entry_id', '=', 'ledger_entries.id');
            })
            ->selectRaw('COALESCE(SUM(CASE WHEN ledger_entries.amount > COALESCE(payments_by_entry.total_paid, 0) THEN ledger_entries.amount - COALESCE(payments_by_entry.total_paid, 0) ELSE 0 END), 0) AS total_outstanding')
            ->value('total_outstanding');

        $today = now()->toDateString();

        $overdueAccountCount = Customer::query()
            ->whereHas('ledgerEntries', function (Builder $query) use ($today): void {
                $query->where('repayment_deadline', '<', $today)
                    ->whereRaw('ledger_entries.amount > COALESCE((SELECT SUM(payments.amount) FROM payments WHERE payments.ledger_entry_id = ledger_entries.id), 0)');
            })
            ->count();

        $recentCredits = LedgerEntry::query()
            ->with('customer')
            ->latest('created_at')
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (LedgerEntry $entry): array => [
                'id' => 'credit-'.$entry->id,
                'type' => 'Credit recorded',
                'customer_name' => $entry->customer?->full_name ?? 'Unknown customer',
                'amount' => $entry->amount,
                'created_at' => $entry->created_at,
                'tie_priority' => 0,
            ]);

        $recentBatchedPayments = DB::table('payments')
            ->join('customers', 'customers.id', '=', 'payments.customer_id')
            ->select('payments.payment_batch_id', 'payments.customer_id', 'customers.full_name')
            ->selectRaw('SUM(payments.amount) AS amount')
            ->selectRaw('MAX(payments.created_at) AS created_at')
            ->selectRaw('MAX(payments.id) AS id')
            ->whereNotNull('payments.payment_batch_id')
            ->groupBy('payments.payment_batch_id', 'payments.customer_id', 'customers.full_name')
            ->orderByDesc(DB::raw('MAX(payments.created_at)'))
            ->orderByDesc(DB::raw('MAX(payments.id)'))
            ->limit(8)
            ->get()
            ->map(fn (object $payment): array => [
                'id' => 'payment-'.$payment->payment_batch_id,
                'type' => 'Payment received',
                'customer_name' => $payment->full_name,
                'amount' => Money::fromCents(Money::toCents((string) $payment->amount)),
                'created_at' => Carbon::parse($payment->created_at),
                'tie_priority' => 1,
            ]);

        $recentLegacyPayments = Payment::query()
            ->with('customer')
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

        return view('dashboard', [
            'totalCollectibles' => Money::fromCents(Money::toCents((string) ($totalOutstanding ?? '0'))),
            'overdueAccountCount' => $overdueAccountCount,
            'recentActivity' => $recentActivity,
        ]);
    }
}
