    @php($financials = $ledger['financials'])
    <section class="ledger-balance-grid" aria-label="Balance summary">
        <article class="ledger-balance-panel ledger-balance-primary">
            <p class="eyebrow">TOTAL AMOUNT DUE</p>
            <p class="ledger-balance-value">₱{{ $financials['total_due'] }}</p>
            <p class="ledger-balance-note">Outstanding principal plus outstanding late fees</p>
        </article>
        <article class="ledger-balance-panel">
            <p class="eyebrow">OUTSTANDING PRINCIPAL</p>
            <p class="ledger-secondary-value">₱{{ $financials['principal_outstanding'] }}</p>
            <p class="ledger-balance-note">
                @if ($financials['overdue_principal'] !== '0.00')
                    ₱{{ $financials['overdue_principal'] }} of it is overdue
                @else
                    Unpaid credit, excluding late fees
                @endif
            </p>
        </article>
        <article class="ledger-balance-panel">
            <p class="eyebrow">OUTSTANDING LATE FEES</p>
            <p class="ledger-secondary-value">₱{{ $financials['penalties_outstanding'] }}</p>
            <p class="ledger-balance-note">
                @if ($financials['penalties_assessed'] !== '0.00')
                    ₱{{ $financials['penalties_assessed'] }} assessed · ₱{{ $financials['penalties_paid'] }} paid · ₱{{ $financials['penalties_waived'] }} waived
                @else
                    No late fees assessed
                @endif
            </p>
        </article>
    </section>

    <dl class="ledger-financial-summary" aria-label="Account totals">
        <div><dt>Total credited (principal)</dt><dd>₱{{ $financials['original_principal'] }}</dd></div>
        <div><dt>Principal paid</dt><dd>₱{{ $financials['principal_paid'] }}</dd></div>
        <div><dt>Cash payments received</dt><dd>₱{{ $financials['cash_received'] }}</dd></div>
        <div><dt>Suki Points used (not cash)</dt><dd>₱{{ $financials['points_redeemed_amount'] }}</dd></div>
        <div><dt>Credit limit</dt><dd>₱{{ $customer->credit_limit }}</dd></div>
        <div><dt>Available credit</dt><dd>₱{{ $ledger['available_credit'] }}</dd></div>
    </dl>
