@extends('admin.layout', ['activeNav' => 'stores'])

@section('title', 'Create Store')

@section('content')
    <a class="back-link" href="{{ route('admin.stores.index') }}">← Back to stores</a>

    <header class="dashboard-heading">
        <p class="eyebrow">STORE MANAGEMENT</p>
        <h1>Create Store</h1>
        <p>Register a store and create its owner account. The owner must change the temporary password at first sign-in.</p>
    </header>

    <section class="admin-panel">
        <form class="admin-form" method="POST" action="{{ route('admin.stores.store') }}">
            @csrf

            <h2>Store</h2>
            <div class="form-field">
                <label for="store_name">Store name</label>
                <input class="auth-input" id="store_name" name="store_name" type="text" value="{{ old('store_name') }}" maxlength="120" required>
                <p class="field-error" role="alert" @if (!$errors->has('store_name')) hidden @endif>@error('store_name'){{ $message }}@enderror</p>
            </div>

            <h2>Owner account</h2>
            @include('admin.users.partials.owner-fields', ['usernameHelp' => 'Leave blank to use the store name as the username.'])

            <button class="admin-button" type="submit">Create store and owner</button>
        </form>
    </section>
@endsection
