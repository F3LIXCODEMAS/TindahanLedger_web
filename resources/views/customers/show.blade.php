<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $customer->full_name }} | {{ config('app.name', 'TindahanLedger') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="ledger-app-page">
    <header class="ledger-topbar">
        <a class="brand-lockup ledger-brand" href="{{ route('dashboard') }}" aria-label="TindahanLedger dashboard">
            <span class="brand-mark" aria-hidden="true">TL</span>
            <span class="brand-name">TindahanLedger</span>
        </a>
        <nav class="owner-navigation" aria-label="Owner navigation">
            <a class="owner-nav-link" href="{{ route('dashboard') }}">Dashboard</a>
            <a class="owner-nav-link is-current" href="{{ route('customers.index') }}" aria-current="page">Customers</a>
            <a class="owner-nav-link" href="{{ route('password.edit') }}">Change password</a>
            <span class="owner-name">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="logout-button" type="submit">Log out</button>
            </form>
        </nav>
    </header>

    <main class="dashboard-content customer-ledger-content">
        <a class="back-link" href="{{ route('customers.index') }}">Back to customers</a>

        <header class="customer-ledger-heading">
            <div>
                <p class="eyebrow">CUSTOMER LEDGER</p>
                <h1>{{ $customer->full_name }}</h1>
            </div>
            <a class="customer-edit-link" href="{{ route('customers.edit', $customer) }}">Edit details</a>
        </header>

        @if ($ledger['is_overdue'])
            <p class="ledger-overdue-banner" role="status">
                {{ $ledger['overdue_entry_count'] }} overdue {{ $ledger['overdue_entry_count'] === 1 ? 'transaction' : 'transactions' }}
            </p>
        @endif

        <section class="ledger-balance-grid" aria-label="Balance summary">
            <article class="ledger-balance-panel ledger-balance-primary">
                <p class="eyebrow">CURRENT OUTSTANDING</p>
                <p class="ledger-balance-value">₱{{ $ledger['outstanding_balance'] }}</p>
                <p class="ledger-balance-note">Unpaid balance across all credit entries</p>
            </article>
            <article class="ledger-balance-panel">
                <p class="eyebrow">CREDIT LIMIT</p>
                <p class="ledger-secondary-value">₱{{ $customer->credit_limit }}</p>
            </article>
            <article class="ledger-balance-panel">
                <p class="eyebrow">AVAILABLE CREDIT</p>
                <p class="ledger-secondary-value">₱{{ $ledger['available_credit'] }}</p>
            </article>
        </section>

        <section class="customer-ledger-section" aria-labelledby="customer-information-title">
            <div class="ledger-section-heading">
                <div>
                    <p class="eyebrow">PROFILE</p>
                    <h2 id="customer-information-title">Customer information</h2>
                </div>
                @if ($ledger['is_overdue'])
                    <span class="ledger-status is-overdue">Overdue</span>
                @else
                    <span class="ledger-status is-current">Current</span>
                @endif
            </div>
            <dl class="customer-ledger-details">
                <div>
                    <dt>Full name</dt>
                    <dd>{{ $customer->full_name }}</dd>
                </div>
                <div>
                    <dt>Contact number</dt>
                    <dd>{{ $customer->contact_number }}</dd>
                </div>
                <div>
                    <dt>Email address</dt>
                    <dd>{{ $customer->email }}</dd>
                </div>
                <div>
                    <dt>Residential landmark</dt>
                    <dd>{{ $customer->residential_landmark }}</dd>
                </div>
            </dl>
        </section>

        <section class="customer-ledger-section" aria-labelledby="quick-actions-title">
            <div class="ledger-section-heading">
                <div>
                    <p class="eyebrow">ACTIONS</p>
                    <h2 id="quick-actions-title">Quick actions</h2>
                </div>
            </div>
            <div class="customer-ledger-actions">
                <a class="ledger-action-button is-credit-action" href="{{ route('credits.create', ['customer_id' => $customer->id]) }}">Record Credit</a>
                @if ($ledger['outstanding_balance'] === '0.00')
                    <button class="ledger-action-button is-payment-action" type="button" disabled aria-disabled="true">No Balance to Pay</button>
                @else
                    <a class="ledger-action-button is-payment-action" href="{{ route('payments.create', ['customer_id' => $customer->id]) }}">Record Payment</a>
                @endif
            </div>
        </section>

        <section class="customer-ledger-section" aria-labelledby="rewards-title">
            <div class="ledger-section-heading">
                <div>
                    <p class="eyebrow">CUSTOMER REWARDS</p>
                    <h2 id="rewards-title">Suki rewards</h2>
                </div>
            </div>
            <div class="ledger-reward-grid">
                <article class="ledger-reward-panel ledger-points-panel">
                    <div class="ledger-reward-heading">
                        <h3>Suki Points</h3>
                        <span class="ledger-points-mark" aria-hidden="true">SP</span>
                    </div>
                    <p class="ledger-reward-value">{{ number_format($ledger['rewards']['points_balance']) }} <span>points</span></p>
                    <p class="ledger-reward-progress-label">Progress to next point</p>
                    <p class="ledger-reward-progress">₱{{ $ledger['rewards']['remainder_amount'] }} / ₱{{ $ledger['rewards']['point_threshold'] }}</p>
                </article>
                <article class="ledger-reward-panel ledger-cycle-panel">
                    <div class="ledger-reward-heading">
                        <h3>Debt cycle reward</h3>
                        <span class="ledger-cycle-mark" aria-hidden="true">3×</span>
                    </div>
                    <p class="ledger-reward-progress">{{ $ledger['rewards']['cycle_progress'] }} / {{ $ledger['rewards']['reward_threshold'] }}</p>
                    <p class="ledger-reward-progress-label">Completed debt cycles</p>
                    <p class="ledger-cycle-status {{ $ledger['rewards']['reward_achieved'] ? 'is-achieved' : '' }}">
                        {{ $ledger['rewards']['reward_achieved'] ? 'Three-cycle reward achieved' : 'Progress toward three-cycle reward' }}
                    </p>
                </article>
            </div>
        </section>

        <section class="customer-ledger-section ledger-history-section" aria-labelledby="transaction-history-title">
            <div class="ledger-section-heading">
                <div>
                    <p class="eyebrow">ACCOUNT ACTIVITY</p>
                    <h2 id="transaction-history-title">Transaction history</h2>
                </div>
                <span class="ledger-history-count">{{ $ledger['transactions']->count() }} {{ $ledger['transactions']->count() === 1 ? 'transaction' : 'transactions' }}</span>
            </div>

            @if ($ledger['transactions']->isEmpty())
                <div class="activity-empty ledger-history-empty" role="status">
                    <p>No transactions yet.</p>
                </div>
            @else
                <ol class="ledger-history-list">
                    @foreach ($ledger['transactions'] as $transaction)
                        <li class="ledger-transaction {{ $transaction['type'] === 'credit' ? 'is-credit' : 'is-payment' }}">
                            <div class="ledger-transaction-heading">
                                <div class="ledger-transaction-kind">
                                    <span class="ledger-transaction-marker" aria-hidden="true">{{ $transaction['type'] === 'credit' ? '+' : '-' }}</span>
                                    <div>
                                        <strong>{{ $transaction['type'] === 'credit' ? 'Credit' : 'Payment' }}</strong>
                                        <time datetime="{{ $transaction['date']->toDateString() }}">{{ $transaction['date']->format('M j, Y') }}</time>
                                    </div>
                                </div>
                                <strong class="ledger-transaction-amount">
                                    {{ $transaction['type'] === 'credit' ? '+' : '-' }}₱{{ $transaction['amount'] }}
                                </strong>
                            </div>
                            <dl class="ledger-transaction-details">
                                <div class="ledger-transaction-description">
                                    <dt>Description</dt>
                                    <dd>{{ $transaction['type'] === 'credit' ? (count($transaction['items']) > 0 ? implode(', ', $transaction['items']) : 'Credit entry') : 'Cash payment' }}</dd>
                                </div>
                                @if ($transaction['note'])
                                    <div>
                                        <dt>Note</dt>
                                        <dd>{{ $transaction['note'] }}</dd>
                                    </div>
                                @endif
                                <div>
                                    <dt>Repayment deadline</dt>
                                    <dd>{{ $transaction['deadline']?->format('M j, Y') ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt>Running balance</dt>
                                    <dd>₱{{ $transaction['running_balance'] }}</dd>
                                </div>
                                <div>
                                    <dt>Recorded</dt>
                                    <dd>{{ $transaction['recorded_at']->format('g:i A') }}</dd>
                                </div>
                            </dl>
                            @if ($transaction['is_overdue'])
                                <p class="ledger-transaction-overdue" role="status">
                                    Overdue · ₱{{ $transaction['remaining_amount'] }} remaining
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    </main>
</body>
</html>
