@php
    $rewards = $ledger['rewards'];
    $storeIsActive = $customer->store?->isActive() ?? false;
    $redeemUnavailableReason = match (true) {
        ! $storeIsActive => 'This store is suspended, so points cannot be redeemed until it is reactivated.',
        $rewards['points_balance'] === 0 => 'No points to redeem yet. Customers earn 1 point for every ₱'.$rewards['point_threshold'].' they repay.',
        $ledger['outstanding_balance'] === '0.00' => 'This customer has no outstanding balance to reduce. Points can be redeemed the next time they owe a balance.',
        default => null,
    };
@endphp

<section class="customer-ledger-section" aria-labelledby="rewards-title">
    <div class="ledger-section-heading">
        <div>
            <p class="eyebrow">CUSTOMER REWARDS</p>
            <h2 id="rewards-title">Suki Points</h2>
        </div>
    </div>
    <div class="ledger-reward-grid">
        <article class="ledger-reward-panel ledger-points-panel">
            <div class="ledger-reward-heading">
                <h3>Available points</h3>
                <span class="ledger-points-mark" aria-hidden="true">SP</span>
            </div>
            <p class="ledger-reward-value">{{ number_format($rewards['points_balance']) }} <span>points</span></p>
            <p class="ledger-reward-progress-label">Progress to next point</p>
            <p class="ledger-reward-progress">₱{{ $rewards['remainder_amount'] }} / ₱{{ $rewards['point_threshold'] }}</p>
        </article>
        <article class="ledger-reward-panel ledger-cycle-panel">
            <div class="ledger-reward-heading">
                <h3>Paid-off streak</h3>
                <span class="ledger-cycle-mark" aria-hidden="true">3×</span>
            </div>
            <p class="ledger-reward-progress">{{ $rewards['current_streak'] }} / {{ $rewards['reward_threshold'] }}</p>
            <p class="ledger-reward-progress-label">Consecutive fully paid debt cycles</p>
            <p class="ledger-cycle-status">
                {{ $rewards['cycles_until_reward'] }} more fully paid {{ $rewards['cycles_until_reward'] === 1 ? 'cycle' : 'cycles' }} until the bonus
                · {{ number_format($rewards['completed_cycles']) }} completed in total
            </p>
        </article>
    </div>

    <div class="suki-redeem" id="redeem-points">
        <h3>Redeem points</h3>
        <p class="ledger-reward-progress-label">
            1 point = ₱{{ $rewards['redemption_peso_per_point'] }} of store credit, taken off the outstanding balance. It is not cash and cannot be withdrawn.
        </p>

        @if ($redeemUnavailableReason)
            <p class="suki-redeem-unavailable" role="status">{{ $redeemUnavailableReason }}</p>
        @else
            <form
                class="suki-redeem-form"
                method="POST"
                action="{{ route('customers.suki-redemptions.store', $customer) }}"
                data-suki-redeem-form
                data-cents-per-point="{{ \App\Support\Money::toCents($rewards['redemption_peso_per_point']) }}"
                data-balance-cents="{{ \App\Support\Money::toCents($ledger['outstanding_balance']) }}"
                data-confirm="Redeem these Suki Points as store credit for {{ $customer->full_name }}?"
            >
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', \Illuminate\Support\Str::uuid()->toString()) }}">

                <div class="form-field">
                    <label for="redeem-points-input">Points to redeem</label>
                    <input
                        class="auth-input"
                        id="redeem-points-input"
                        name="points"
                        type="number"
                        inputmode="numeric"
                        min="1"
                        max="{{ $rewards['max_redeemable_points'] }}"
                        step="1"
                        value="{{ old('points', $rewards['max_redeemable_points']) }}"
                        required
                        aria-describedby="redeem-points-help redeem-points-error"
                        @if ($errors->has('points')) aria-invalid="true" @endif
                    >
                    <p class="password-requirements" id="redeem-points-help">
                        You can redeem up to {{ number_format($rewards['max_redeemable_points']) }} {{ $rewards['max_redeemable_points'] === 1 ? 'point' : 'points' }} now: the customer's points, limited to their outstanding balance of ₱{{ $ledger['outstanding_balance'] }}.
                    </p>
                    <p class="field-error" id="redeem-points-error" role="alert" @if (! $errors->has('points')) hidden @endif>@error('points'){{ $message }}@enderror</p>
                </div>

                <dl class="suki-redeem-preview" aria-live="polite">
                    <div>
                        <dt>Credit value</dt>
                        <dd data-redeem-credit>—</dd>
                    </div>
                    <div>
                        <dt>Current balance</dt>
                        <dd>₱{{ $ledger['outstanding_balance'] }}</dd>
                    </div>
                    <div>
                        <dt>Balance after redemption</dt>
                        <dd data-redeem-balance>—</dd>
                    </div>
                </dl>

                <button class="ledger-action-button is-payment-action" type="submit" data-redeem-submit>Redeem points</button>
            </form>
        @endif
    </div>
</section>
