    <section class="ledger-balance-grid" aria-label="Balance summary">
        <article class="ledger-balance-panel ledger-balance-primary">
            <p class="eyebrow">CURRENT OUTSTANDING</p>
            <p class="ledger-balance-value">₱{{ $ledger['outstanding_balance'] }}</p>
            @if ($ledger['penalty_outstanding'] !== '0.00')
                <p class="ledger-balance-note">
                    Principal ₱{{ $ledger['principal_outstanding'] }} + late fees ₱{{ $ledger['penalty_outstanding'] }}
                </p>
            @else
                <p class="ledger-balance-note">Unpaid balance across all credit entries</p>
            @endif
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
