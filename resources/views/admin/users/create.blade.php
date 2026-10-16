@extends('admin.layout', ['activeNav' => 'users'])

@section('title', 'Create Owner Account')

@section('content')
    <a class="back-link" href="{{ route('admin.users.index') }}">← Back to users</a>

    <header class="dashboard-heading">
        <p class="eyebrow">USER MANAGEMENT</p>
        <h1>Create Owner Account</h1>
        <p>Use this to prepare a replacement owner. To register a new store with its owner, use <a href="{{ route('admin.stores.create') }}">Create store</a>.</p>
    </header>

    <section class="admin-panel">
        <form class="admin-form" method="POST" action="{{ route('admin.users.store') }}">
            @csrf
            @include('admin.users.partials.owner-fields')
            <button class="admin-button" type="submit">Create owner account</button>
        </form>
    </section>
@endsection
