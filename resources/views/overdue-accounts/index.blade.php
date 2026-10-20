<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Overdue Accounts | {{ config('app.name', 'TindahanLedger') }}</title>
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
            <a class="owner-nav-link is-current" href="{{ route('overdue-accounts.index') }}" aria-current="page">Overdue accounts</a>
            <a class="owner-nav-link" href="{{ route('email-settings.edit') }}">Email notifications</a>
            <a class="owner-nav-link" href="{{ route('late-penalties.edit') }}">Late payment policy</a>
            <a class="owner-nav-link" href="{{ route('password.edit') }}">Change password</a>
            <span class="owner-name">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="logout-button" type="submit">Log out</button>
            </form>
        </nav>
    </header>

    <main class="dashboard-content overdue-accounts-content">
        <header class="dashboard-heading">
            <p class="eyebrow">COLLECTIONS</p>
            <h1>Overdue Accounts</h1>
            <p>Customers with unpaid credit entries past their repayment deadlines.</p>
        </header>

        <form class="overdue-controls" method="GET" action="{{ route('overdue-accounts.index') }}">
            <div class="overdue-search-field">
                <label for="overdue-search">Search customer</label>
                <input
                    id="overdue-search"
                    name="search"
                    type="search"
                    value="{{ $search }}"
                    maxlength="120"
                    placeholder="Enter a customer name"
                >
            </div>
            <div class="overdue-sort-field">
                <label for="overdue-sort">Sort by</label>
                <select id="overdue-sort" name="sort">
                    <option value="longest" @selected($sort === 'longest')>Longest overdue first</option>
                    <option value="balance" @selected($sort === 'balance')>Highest total amount due</option>
                </select>
            </div>
            <button class="overdue-filter-button" type="submit">Apply</button>
        </form>

        <section class="overdue-results" aria-labelledby="overdue-results-title">
            <div class="overdue-results-heading">
                <h2 id="overdue-results-title">Overdue customers</h2>
                <span>{{ $customers->total() }} {{ $customers->total() === 1 ? 'customer' : 'customers' }}</span>
            </div>

            @if ($customers->total() > 0)
                <dl class="overdue-totals" aria-label="Totals for overdue accounts">
                    <div><dt>Overdue principal</dt><dd>₱{{ $overdueTotals['overdue_principal'] }}</dd></div>
                    <div><dt>Outstanding principal</dt><dd>₱{{ $overdueTotals['principal_outstanding'] }}</dd></div>
                    <div><dt>Outstanding late fees</dt><dd>₱{{ $overdueTotals['penalties_outstanding'] }}</dd></div>
                    <div><dt>Total amount due</dt><dd>₱{{ $overdueTotals['total_due'] }}</dd></div>
                </dl>
            @endif

            @if ($customers->isEmpty())
                <div class="activity-empty overdue-empty" role="status">
                    <span class="empty-mark" aria-hidden="true">—</span>
                    <p>{{ $search === '' ? 'No customers have overdue balances.' : 'No overdue customers match your search.' }}</p>
                </div>
            @else
                <div class="overdue-account-list">
                    @foreach ($customers as $customer)
                        <article class="overdue-account-card">
                            <header class="overdue-account-heading">
                                <div class="overdue-customer-identity">
                                    <h3>{{ $customer->full_name }}</h3>
                                    <p>{{ $customer->contact_number ?: 'No contact number on file' }}</p>
                                </div>
                                <a class="overdue-ledger-link" href="{{ route('customers.show', $customer) }}">View Ledger</a>
                            </header>

                            <dl class="overdue-account-summary">
                                <div>
                                    <dt>Total amount due</dt>
                                    <dd>₱{{ $customer->financials['total_due'] }}</dd>
                                </div>
                                <div>
                                    <dt>Overdue principal</dt>
                                    <dd>₱{{ $customer->financials['overdue_principal'] }}</dd>
                                </div>
                                <div>
                                    <dt>Outstanding late fees</dt>
                                    <dd>₱{{ $customer->financials['penalties_outstanding'] }}</dd>
                                </div>
                                <div>
                                    <dt>Longest overdue</dt>
                                    <dd>{{ $customer->overdue_days }} {{ $customer->overdue_days === 1 ? 'day' : 'days' }}</dd>
                                </div>
                                <div>
                                    <dt>Earliest overdue deadline</dt>
                                    <dd>{{ $customer->earliest_overdue_deadline->format('M j, Y') }}</dd>
                                </div>
                            </dl>

                            <div class="overdue-entry-list">
                                @foreach ($customer->ledgerEntries as $entry)
                                    <section class="overdue-credit-entry" aria-label="Overdue credit entry">
                                        <div class="overdue-entry-heading">
                                            <div>
                                                <h4>{{ count($entry->itemized_list ?? []) > 0 ? implode(', ', $entry->itemized_list) : 'Credit entry' }}</h4>
                                                <p>Recorded {{ $entry->transaction_date->format('M j, Y') }} · ₱{{ $entry->amount }}</p>
                                                @if ($entry->note)
                                                    <p>{{ $entry->note }}</p>
                                                @endif
                                            </div>
                                            <span class="overdue-entry-balance">₱{{ $entry->unpaid_amount }} unpaid</span>
                                        </div>
                                        <dl class="overdue-entry-details">
                                            <div>
                                                <dt>Repayment deadline</dt>
                                                <dd>{{ $entry->repayment_deadline->format('M j, Y') }}</dd>
                                            </div>
                                            <div>
                                                <dt>Days overdue</dt>
                                                <dd>{{ $entry->days_overdue }} {{ $entry->days_overdue === 1 ? 'day' : 'days' }}</dd>
                                            </div>
                                            @php($penalty = $entry->penalty_status)
                                            @if ($penalty['state'] !== \App\Services\LatePenaltyService::STATE_NOT_COVERED)
                                                <div>
                                                    <dt>Late fee</dt>
                                                    <dd>
                                                        @switch($penalty['state'])
                                                            @case(\App\Services\LatePenaltyService::STATE_IN_GRACE)
                                                                In grace period until {{ $penalty['grace_ends_on']->format('M j') }} (₱{{ $penalty['fee'] }} after)
                                                                @break
                                                            @case(\App\Services\LatePenaltyService::STATE_ELIGIBLE)
                                                                Due: ₱{{ $penalty['fee'] }} at the next daily run
                                                                @break
                                                            @case(\App\Services\LatePenaltyService::STATE_APPLIED)
                                                                ₱{{ $penalty['penalty']->amount }} applied {{ $penalty['penalty']->transaction_date->format('M j, Y') }}
                                                                @break
                                                            @case(\App\Services\LatePenaltyService::STATE_WAIVED)
                                                                Applied, then waived
                                                                @break
                                                            @default
                                                                None
                                                        @endswitch
                                                    </dd>
                                                </div>
                                            @endif
                                        </dl>

                                        <div class="overdue-reminders">
                                            <h5>Reminder history for this entry</h5>
                                            @if ($entry->emailReminderLogs->isEmpty())
                                                <p class="overdue-no-reminders">No reminder history available.</p>
                                            @else
                                                <ol class="overdue-reminder-list">
                                                    @foreach ($entry->emailReminderLogs as $reminder)
                                                        <li class="overdue-reminder-item">
                                                            <div class="overdue-reminder-heading">
                                                                <strong>{{ $reminder->reminder_type_label }}</strong>
                                                                <span class="ledger-reminder-status {{ 'is-'.$reminder->delivery_status }}">
                                                                    {{ \Illuminate\Support\Str::headline($reminder->delivery_status) }}
                                                                </span>
                                                            </div>
                                                            <p>
                                                                Scheduled {{ $reminder->scheduled_reminder_date->format('M j, Y') }}.
                                                                @if ($reminder->sent_at)
                                                                    Sent {{ $reminder->sent_at->format('M j, Y g:i A') }}.
                                                                @elseif ($reminder->attempted_at)
                                                                    Last attempted {{ $reminder->attempted_at->format('M j, Y g:i A') }}.
                                                                @else
                                                                    Not sent.
                                                                @endif
                                                            </p>
                                                            @if ($reminder->failure_description)
                                                                <p class="ledger-reminder-failure">{{ $reminder->failure_description }}</p>
                                                            @endif
                                                        </li>
                                                    @endforeach
                                                </ol>
                                            @endif
                                        </div>
                                    </section>
                                @endforeach
                            </div>
                        </article>
                    @endforeach
                </div>

                {{ $customers->links() }}
            @endif
        </section>

        @if ($feeOnlyCustomers->isNotEmpty())
            <section class="overdue-results overdue-fee-only" aria-labelledby="fee-only-title">
                <div class="overdue-results-heading">
                    <h2 id="fee-only-title">Unpaid late fees</h2>
                    <span>{{ $feeOnlyCustomers->total() }} {{ $feeOnlyCustomers->total() === 1 ? 'customer' : 'customers' }}</span>
                </div>
                <p class="metric-note">These customers have no overdue credit, but a late fee is still unpaid. They are not counted as overdue accounts.</p>

                <div class="overdue-account-list">
                    @foreach ($feeOnlyCustomers as $customer)
                        <article class="overdue-account-card">
                            <header class="overdue-account-heading">
                                <div class="overdue-customer-identity">
                                    <h3>{{ $customer->full_name }}</h3>
                                    <p>{{ $customer->contact_number ?: 'No contact number on file' }}</p>
                                </div>
                                <a class="overdue-ledger-link" href="{{ route('customers.show', $customer) }}">View Ledger</a>
                            </header>

                            <dl class="overdue-account-summary">
                                <div>
                                    <dt>Total amount due</dt>
                                    <dd>₱{{ $customer->financials['total_due'] }}</dd>
                                </div>
                                <div>
                                    <dt>Outstanding principal</dt>
                                    <dd>₱{{ $customer->financials['principal_outstanding'] }}</dd>
                                </div>
                                <div>
                                    <dt>Outstanding late fees</dt>
                                    <dd>₱{{ $customer->financials['penalties_outstanding'] }}</dd>
                                </div>
                            </dl>

                            <div class="overdue-entry-list">
                                @foreach ($customer->ledgerEntries as $penalty)
                                    <section class="overdue-credit-entry" aria-label="Unpaid late fee">
                                        <div class="overdue-entry-heading">
                                            <div>
                                                <h4>Late payment fee · ₱{{ $penalty->amount }}</h4>
                                                <p>Added {{ $penalty->transaction_date->format('M j, Y') }}
                                                    @if ($penalty->penaltyFor)
                                                        on the ₱{{ $penalty->penaltyFor->amount }} credit due {{ $penalty->penaltyFor->repayment_deadline?->format('M j, Y') }}
                                                    @endif
                                                </p>
                                            </div>
                                            <span class="overdue-entry-balance">₱{{ $penalty->unpaid_amount }} unpaid</span>
                                        </div>
                                    </section>
                                @endforeach
                            </div>
                        </article>
                    @endforeach
                </div>

                {{ $feeOnlyCustomers->links() }}
            </section>
        @endif
    </main>
</body>
</html>
