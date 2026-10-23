<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatbotControllers extends Controller
{
    public function chat(Request $request)
    {
        $message = strtolower(trim($request->input('message', '')));

        // Greeting
        if (str_contains($message, 'hello') || $message === 'hi') {
            $reply = 'Hello! I am AteTindera. You can ask me about customers, outstanding balances, overdue accounts, credit records, payments, and today\'s transactions.';

        // Today's transactions
        } elseif (
            str_contains($message, 'today') ||
            str_contains($message, 'transaction')
        ) {
            $today = now()->toDateString();

            $entries = LedgerEntry::with('customer')
                ->whereDate('created_at', $today)
                ->orderBy('created_at', 'desc')
                ->get();

            $payments = Payment::with('customer')
                ->whereDate('created_at', $today)
                ->orderBy('created_at', 'desc')
                ->get();

            if ($entries->isEmpty() && $payments->isEmpty()) {
                $reply = 'There are no transactions recorded today.';
            } else {
                $reply = "Today's transactions:\n";

                foreach ($entries as $entry) {
                    $customerName = $entry->customer?->full_name
                        ?? 'Unknown customer (Customer ID: '
                        . ($entry->customer_id ?? 'missing') . ')';

                    $reply .= "\n{$customerName} — Credit recorded: ₱"
                        . number_format((float) $entry->amount, 2);
                }

                foreach ($payments as $payment) {
                    $customerName = $payment->customer?->full_name
                        ?? 'Unknown customer (Customer ID: '
                        . ($payment->customer_id ?? 'missing') . ')';

                    $reply .= "\n{$customerName} — Payment received: ₱"
                        . number_format((float) $payment->amount, 2);
                }
            }

        // Overdue accounts
        } elseif (
            str_contains($message, 'overdue') ||
            str_contains($message, 'past due')
        ) {
            $today = now()->toDateString();

            $count = Customer::query()
                ->whereHas('ledgerEntries', function (Builder $query) use ($today) {
                    $query->where('repayment_deadline', '<', $today)
                        ->whereRaw(
                            'ledger_entries.amount > COALESCE(
                                (
                                    SELECT SUM(payments.amount)
                                    FROM payments
                                    WHERE payments.ledger_entry_id = ledger_entries.id
                                ),
                                0
                            )'
                        );
                })
                ->count();

            $reply = "There are {$count} customers with overdue accounts.";

        // Total outstanding balance
        } elseif (
            str_contains($message, 'outstanding') ||
            str_contains($message, 'balance') ||
            str_contains($message, 'owe')
        ) {
            $paymentsByEntry = DB::table('payments')
                ->select('ledger_entry_id')
                ->selectRaw('SUM(amount) AS total_paid')
                ->groupBy('ledger_entry_id');

            $total = DB::table('ledger_entries')
                ->leftJoinSub(
                    $paymentsByEntry,
                    'payments_by_entry',
                    function ($join) {
                        $join->on(
                            'payments_by_entry.ledger_entry_id',
                            '=',
                            'ledger_entries.id'
                        );
                    }
                )
                ->selectRaw(
                    'COALESCE(SUM(
                        CASE
                            WHEN ledger_entries.amount >
                                COALESCE(payments_by_entry.total_paid, 0)
                            THEN ledger_entries.amount -
                                COALESCE(payments_by_entry.total_paid, 0)
                            ELSE 0
                        END
                    ), 0) AS total_outstanding'
                )
                ->value('total_outstanding');

            $reply = 'The total outstanding balance is ₱'
                . number_format((float) $total, 2) . '.';

        // Number of customers
        } elseif (str_contains($message, 'customer')) {
            $count = Customer::count();

            $reply = "Your database currently has {$count} customers.";

        // Total payments
        } elseif (str_contains($message, 'payment')) {
            $total = Payment::sum('amount');

            $reply = 'The total recorded payments are ₱'
                . number_format((float) $total, 2) . '.';

        // Number of credit records
        } elseif (str_contains($message, 'credit')) {
            $count = LedgerEntry::count();

            $reply = "There are {$count} credit records in your database.";

        // Unknown question
        } else {
            $reply = 'I can help with customer counts, outstanding balances, overdue accounts, credit records, recorded payments, and today\'s transactions. What would you like to know?';
        }

        return response()->json([
            'reply' => $reply,
        ]);
    }
}
