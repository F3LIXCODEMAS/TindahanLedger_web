@extends('admin.layout', ['activeNav' => 'stores'])

@section('title', 'Edit Store')

@section('content')
    <a class="back-link" href="{{ route('admin.stores.show', $store) }}">← Back to store</a>

    <header class="dashboard-heading">
        <p class="eyebrow">STORE MANAGEMENT</p>
        <h1>Edit {{ $store->store_name }}</h1>
    </header>

    <section class="admin-panel">
        <form class="admin-form" method="POST" action="{{ route('admin.stores.update', $store) }}">
            @csrf
            @method('PUT')

            <div class="form-field">
                <label for="store_name">Store name</label>
                <input class="auth-input" id="store_name" name="store_name" type="text" value="{{ old('store_name', $store->store_name) }}" maxlength="120" required>
                <p class="field-error" role="alert" @if (!$errors->has('store_name')) hidden @endif>@error('store_name'){{ $message }}@enderror</p>
            </div>

            <button class="admin-button" type="submit">Save store details</button>
        </form>
    </section>
@endsection
