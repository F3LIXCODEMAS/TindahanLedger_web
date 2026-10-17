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
                    @php($addsToDebt = in_array($transaction['type'], \App\Services\CustomerLedgerService::DEBT_TYPES, true))
                    <li class="ledger-transaction {{ $addsToDebt ? 'is-credit' : 'is-payment' }}">
                        <div class="ledger-transaction-heading">
                            <div class="ledger-transaction-kind">
                                <span class="ledger-transaction-marker" aria-hidden="true">{{ $addsToDebt ? '+' : '-' }}</span>
                                <div>
                                    <strong>{{ match ($transaction['type']) { 'credit' => 'Credit', 'loyalty_redemption' => 'Suki Points redemption', 'late_penalty' => 'Late payment fee', 'penalty_waiver' => 'Late fee waived', default => 'Payment' } }}</strong>
                                    <time datetime="{{ $transaction['date']->toDateString() }}">{{ $transaction['date']->format('M j, Y') }}</time>
                                </div>
                            </div>
                            <strong class="ledger-transaction-amount">
                                {{ $addsToDebt ? '+' : '-' }}₱{{ $transaction['amount'] }}
                            </strong>
                        </div>
                        <dl class="ledger-transaction-details">
                            <div class="ledger-transaction-description">
                                <dt>Description</dt>
                                <dd>{{ $transaction['type'] === 'credit' ? (count($transaction['items']) > 0 ? implode(', ', $transaction['items']) : 'Credit entry') : $transaction['description'] }}</dd>
                            </div>
                            @if ($transaction['type'] === 'loyalty_redemption')
                                <div>
                                    <dt>Points redeemed</dt>
                                    <dd>{{ number_format($transaction['points_redeemed']) }} {{ $transaction['points_redeemed'] === 1 ? 'point' : 'points' }} (not cash)</dd>
                                </div>
                            @endif
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
                        @php($penalty = $transaction['penalty_status'])
                        @if ($penalty && $penalty['state'] !== \App\Services\LatePenaltyService::STATE_NOT_COVERED)
                            <p class="ledger-penalty-status is-{{ $penalty['state'] }}">
                                Late fee policy: ₱{{ $penalty['fee'] }} after {{ $penalty['grace_ends_on']->format('M j, Y') }} ·
                                @switch($penalty['state'])
                                    @case(\App\Services\LatePenaltyService::STATE_NOT_DUE)
                                        Not yet due
                                        @break
                                    @case(\App\Services\LatePenaltyService::STATE_IN_GRACE)
                                        In grace period until {{ $penalty['grace_ends_on']->format('M j') }}
                                        @break
                                    @case(\App\Services\LatePenaltyService::STATE_ELIGIBLE)
                                        Grace period over; the fee will be added at the next daily run
                                        @break
                                    @case(\App\Services\LatePenaltyService::STATE_APPLIED)
                                        Late fee applied on {{ $penalty['penalty']->transaction_date->format('M j, Y') }}
                                        @break
                                    @case(\App\Services\LatePenaltyService::STATE_WAIVED)
                                        Late fee applied and later waived
                                        @break
                                    @default
                                        Paid on time, no fee
                                @endswitch
                            </p>
                        @endif
                        @if ($transaction['type'] === 'late_penalty')
                            @if ($transaction['penalty_waived_at'])
                                <p class="ledger-penalty-status is-waived">
                                    Waived {{ $transaction['penalty_waived_at']->format('M j, Y') }} · Reason: {{ $transaction['penalty_waiver_reason'] }}
                                </p>
                            @elseif ($transaction['can_waive'] && ($canManagePenalties ?? false))
                                <form class="ledger-penalty-waive" method="POST" action="{{ route('customers.late-penalties.waive', [$customer, $transaction['penalty_entry_id']]) }}" data-confirm="Waive the unpaid part of this late payment fee? This is recorded and cannot be undone.">
                                    @csrf
                                    <label for="waive-reason-{{ $transaction['penalty_entry_id'] }}">Reason for waiving</label>
                                    <input class="auth-input" id="waive-reason-{{ $transaction['penalty_entry_id'] }}" name="reason" type="text" maxlength="500" required placeholder="e.g. Customer paid on time in cash, recorded late">
                                    <button class="customer-edit-link" type="submit">Waive fee</button>
                                </form>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
