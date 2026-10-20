@extends('admin.layout', ['activeNav' => 'stores'])

@section('title', $customer->full_name)

@section('content')
    <a class="back-link" href="{{ route('admin.stores.show', $store) }}">← Back to {{ $store->store_name }}</a>

    <header class="dashboard-heading">
        <p class="eyebrow">{{ $store->store_name }} · READ-ONLY LEDGER</p>
        <h1>{{ $customer->full_name }}</h1>
        <p>{{ $customer->contact_number }} · {{ $customer->residential_landmark }}</p>
    </header>

    @if ($ledger['is_overdue'])
        <p class="status-banner" role="alert">
            {{ $ledger['overdue_entry_count'] }} overdue {{ $ledger['overdue_entry_count'] === 1 ? 'transaction' : 'transactions' }}
        </p>
    @endif

    @include('customers.partials.ledger-balances')
    @include('customers.partials.ledger-history')
@endsection
