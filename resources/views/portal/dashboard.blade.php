@extends('portal.layout')

@section('title', 'My Account')

@section('topbar-action')
    <form method="POST" action="{{ route('portal.exit') }}">
        @csrf
        <button class="portal-exit-button" type="submit">Exit</button>
    </form>
@endsection

@section('content')
    <header class="portal-heading">
        <p class="eyebrow">{{ $customer->store->store_name }}</p>
        <h1>Hello, {{ $customer->full_name }}</h1>
        <p class="portal-muted">This page is read-only. For changes or payments, please visit the store.</p>
    </header>

    <section class="portal-card portal-balance" aria-labelledby="portal-balance-title">
        <h2 id="portal-balance-title">Current balance</h2>
        <p class="portal-balance-value">₱{{ $ledger['outstanding_balance'] }}</p>
        @if ($ledger['penalty_outstanding'] !== '0.00')
            <p class="portal-muted">Includes late payment fees of ₱{{ $ledger['penalty_outstanding'] }} (credits ₱{{ $ledger['principal_outstanding'] }}).</p>
        @endif

        @if ($ledger['outstanding_balance'] === '0.00')
            <p class="portal-status is-good">Fully paid. You have no outstanding balance.</p>
        @elseif ($ledger['is_overdue'])
            <p class="portal-status is-warning">Overdue: {{ $ledger['overdue_entry_count'] }} {{ $ledger['overdue_entry_count'] === 1 ? 'credit is' : 'credits are' }} past the repayment deadline.</p>
        @else
            <p class="portal-status">Not overdue</p>
        @endif

        <dl class="portal-summary">
            <div><dt>Credit limit</dt><dd>₱{{ $customer->credit_limit }}</dd></div>
            <div><dt>Available credit</dt><dd>₱{{ $ledger['available_credit'] }}</dd></div>
        </dl>
    </section>

    @if ($outstandingDeadlines->isNotEmpty())
        <section class="portal-card" aria-labelledby="portal-deadlines-title">
            <h2 id="portal-deadlines-title">Upcoming and past-due deadlines</h2>
            <ul class="portal-list">
                @foreach ($outstandingDeadlines as $credit)
                    <li>
                        <span>
                            Due {{ $credit['deadline']->format('M j, Y') }}
                            @if ($credit['is_overdue'])
                                <strong class="portal-status is-warning">Overdue</strong>
                            @endif
                        </span>
                        <span class="portal-amount">₱{{ $credit['remaining_amount'] }} left</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="portal-card" aria-labelledby="portal-history-title">
        <h2 id="portal-history-title">Transaction history</h2>
        <p class="portal-muted">Newest first</p>

        @if ($transactions->isEmpty())
            <p class="portal-notice" role="status">No credits or payments have been recorded yet.</p>
        @else
            <ul class="portal-list portal-history">
                @foreach ($transactions as $transaction)
                    <li>
                        <div>
                            <strong>{{ match ($transaction['type']) { 'credit' => 'Credit', 'loyalty_redemption' => 'Suki Points redeemed', 'late_penalty' => 'Late payment fee', 'penalty_waiver' => 'Late fee waived', default => 'Payment' } }}</strong>
                            <span class="portal-muted">{{ $transaction['date']->format('M j, Y') }}</span>
                            @if ($transaction['items'] !== [])
                                <span class="portal-muted">{{ implode(', ', $transaction['items']) }}</span>
                            @endif
                            @if ($transaction['note'])
                                <span class="portal-muted">{{ $transaction['note'] }}</span>
                            @endif
                            @if ($transaction['type'] === 'credit')
                                <span class="portal-muted">
                                    @if ($transaction['remaining_amount'] === '0.00')
                                        Fully paid
                                    @elseif ($transaction['remaining_amount'] !== $transaction['amount'])
                                        Partially paid · ₱{{ $transaction['remaining_amount'] }} left
                                    @else
                                        Outstanding
                                    @endif
                                    @if ($transaction['deadline'])
                                        · Due {{ $transaction['deadline']->format('M j, Y') }}
                                    @endif
                                    @if ($transaction['is_overdue'])
                                        · <strong class="portal-status is-warning">Overdue</strong>
                                    @endif
                                </span>
                            @endif
                        </div>
                        <div class="portal-amount">
                            @php($addsToDebt = in_array($transaction['type'], \App\Services\CustomerLedgerService::DEBT_TYPES, true))
                            <span class="{{ $addsToDebt ? '' : 'is-payment' }}">{{ $addsToDebt ? '+' : '−' }}₱{{ $transaction['amount'] }}</span>
                            <span class="portal-muted">Balance ₱{{ $transaction['running_balance'] }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="admin-pagination">{{ $transactions->links() }}</div>
        @endif
    </section>

    <section class="portal-card" aria-labelledby="portal-profile-title">
        <h2 id="portal-profile-title">Your details</h2>
        <dl class="portal-summary">
            <div><dt>Name</dt><dd>{{ $customer->full_name }}</dd></div>
            <div><dt>Contact number</dt><dd>{{ $customer->contact_number }}</dd></div>
            <div><dt>Email</dt><dd>{{ $customer->email ?? 'Not provided' }}</dd></div>
            <div><dt>Landmark</dt><dd>{{ $customer->residential_landmark }}</dd></div>
            <div><dt>Store</dt><dd>{{ $customer->store->store_name }}</dd></div>
        </dl>
        <p class="portal-muted">To correct your details, please ask the store.</p>
    </section>

    <p class="portal-muted portal-footnote">Opened with your store QR code. The QR code shows this account to whoever scans it, so keep your card private and tap Exit when you are done.</p>
@endsection
