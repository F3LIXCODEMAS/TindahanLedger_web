@extends('admin.layout', ['activeNav' => 'stores'])

@section('title', 'Create Store')

@section('content')
    <a class="back-link" href="{{ route('admin.stores.index') }}">← Back to stores</a>

    <header class="dashboard-heading">
        <p class="eyebrow">STORE MANAGEMENT</p>
        <h1>Create Store</h1>
        <p>Register a store with a new owner account, or give it to an existing owner account that does not have a store yet.</p>
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

            <h2 id="owner-type-title">Owner account</h2>
            <div class="admin-owner-choice" role="radiogroup" aria-labelledby="owner-type-title">
                <label class="remember-option">
                    <input type="radio" name="owner_type" value="{{ \App\Http\Requests\Admin\CreateStoreRequest::OWNER_TYPE_NEW }}" data-owner-type @checked($ownerType === \App\Http\Requests\Admin\CreateStoreRequest::OWNER_TYPE_NEW)>
                    <span>Create a new owner account</span>
                </label>
                <label class="remember-option">
                    <input type="radio" name="owner_type" value="{{ \App\Http\Requests\Admin\CreateStoreRequest::OWNER_TYPE_EXISTING }}" data-owner-type @checked($ownerType === \App\Http\Requests\Admin\CreateStoreRequest::OWNER_TYPE_EXISTING) @disabled($availableOwners->isEmpty())>
                    <span>Use an existing owner without a store</span>
                </label>
                @if ($availableOwners->isEmpty())
                    <p class="admin-muted">No active owner accounts are waiting for a store.</p>
                @endif
                <p class="field-error" role="alert" @if (!$errors->has('owner_type')) hidden @endif>@error('owner_type'){{ $message }}@enderror</p>
            </div>

            <fieldset class="admin-owner-section" data-owner-section="{{ \App\Http\Requests\Admin\CreateStoreRequest::OWNER_TYPE_NEW }}" @disabled($ownerType !== \App\Http\Requests\Admin\CreateStoreRequest::OWNER_TYPE_NEW) @if ($ownerType !== \App\Http\Requests\Admin\CreateStoreRequest::OWNER_TYPE_NEW) hidden @endif>
                <legend class="sr-only">New owner account</legend>
                @include('admin.users.partials.owner-fields', ['usernameHelp' => 'Leave blank to use the store name as the username.'])
            </fieldset>

            @if ($availableOwners->isNotEmpty())
                <fieldset class="admin-owner-section" data-owner-section="{{ \App\Http\Requests\Admin\CreateStoreRequest::OWNER_TYPE_EXISTING }}" @disabled($ownerType !== \App\Http\Requests\Admin\CreateStoreRequest::OWNER_TYPE_EXISTING) @if ($ownerType !== \App\Http\Requests\Admin\CreateStoreRequest::OWNER_TYPE_EXISTING) hidden @endif>
                    <legend class="sr-only">Existing owner account</legend>
                    <div class="form-field">
                        <label for="owner_user_id">Existing owner</label>
                        <select class="auth-input" id="owner_user_id" name="owner_user_id" required aria-describedby="owner-user-help">
                            @foreach ($availableOwners as $availableOwner)
                                <option value="{{ $availableOwner->id }}" @selected($selectedOwnerId === $availableOwner->id)>{{ $availableOwner->name }} ({{ $availableOwner->username }})</option>
                            @endforeach
                        </select>
                        <p class="password-requirements" id="owner-user-help">Only active owner accounts without a store are listed. The owner keeps their current username and password.</p>
                        <p class="field-error" role="alert" @if (!$errors->has('owner_user_id')) hidden @endif>@error('owner_user_id'){{ $message }}@enderror</p>
                    </div>
                </fieldset>
            @endif

            <button class="admin-button" type="submit">Create store</button>
        </form>
    </section>
@endsection
