    <section class="customer-ledger-section" aria-labelledby="reminder-history-title">
        <div class="ledger-section-heading">
            <div>
                <p class="eyebrow">EMAIL ACTIVITY</p>
                <h2 id="reminder-history-title">Repayment reminder history</h2>
            </div>
            <span class="ledger-history-count">{{ $reminderHistory->count() }} {{ $reminderHistory->count() === 1 ? 'reminder' : 'reminders' }}</span>
        </div>

        @if ($reminderHistory->isEmpty())
            <div class="activity-empty ledger-history-empty" role="status">
                <p>No repayment reminders have been scheduled for this customer.</p>
            </div>
        @else
            <ol class="ledger-history-list">
                @foreach ($reminderHistory as $reminder)
                    <li class="ledger-reminder {{ 'is-'.$reminder->delivery_status }}">
                        <div class="ledger-reminder-heading">
                            <div>
                                <strong>{{ $reminder->reminder_type_label }}</strong>
                                <time datetime="{{ $reminder->scheduled_reminder_date->toDateString() }}">
                                    Scheduled {{ $reminder->scheduled_reminder_date->format('M j, Y') }}
                                </time>
                            </div>
                            <span class="ledger-reminder-status {{ 'is-'.$reminder->delivery_status }}">
                                {{ \Illuminate\Support\Str::headline($reminder->delivery_status) }}
                            </span>
                        </div>
                        <dl class="ledger-reminder-details">
                            <div>
                                <dt>Repayment deadline</dt>
                                <dd>{{ $reminder->repayment_deadline->format('M j, Y') }}</dd>
                            </div>
                            <div>
                                <dt>Balance at reminder</dt>
                                <dd>₱{{ $reminder->outstanding_amount }}</dd>
                            </div>
                            <div>
                                <dt>Delivery</dt>
                                <dd>
                                    @if ($reminder->sent_at)
                                        Sent {{ $reminder->sent_at->format('M j, Y g:i A') }}
                                    @elseif ($reminder->attempted_at)
                                        Last attempted {{ $reminder->attempted_at->format('M j, Y g:i A') }}
                                    @else
                                        Not sent
                                    @endif
                                </dd>
                            </div>
                        </dl>
                        @if ($reminder->failure_description)
                            <p class="ledger-reminder-failure">{{ $reminder->failure_description }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
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
