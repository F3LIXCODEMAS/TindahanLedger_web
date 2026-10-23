<form class="customer-form" method="POST" action="{{ $product->exists ? route('products.update', $product) : route('products.store') }}" data-customer-form>
    @csrf
    @if ($product->exists)
        @method('PUT')
    @endif

    @if ($errors->any())
        <div class="password-error-summary" role="alert" aria-live="polite">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="form-field">
        <label for="name">Product name <span aria-hidden="true">*</span></label>
        <input class="auth-input" id="name" name="name" type="text" value="{{ old('name', $product->name) }}" maxlength="120" pattern=".*\S.*" placeholder="Coke 1.5L" data-trim-required data-empty-message="Please enter the product name." required @if ($errors->has('name')) aria-invalid="true" @endif aria-describedby="name-help name-error">
        <p class="password-requirements" id="name-help">Names are matched without regard to capital letters, so "Coke" and "coke" count as the same product.</p>
        <p class="field-error" id="name-error" role="alert" @if (!$errors->has('name')) hidden @endif>@error('name'){{ $message }}@enderror</p>
    </div>

    <div class="form-field">
        <label for="unit_price">Unit price <span class="credit-optional">Optional</span></label>
        <input class="auth-input" id="unit_price" name="unit_price" type="number" value="{{ old('unit_price', $product->unit_price) }}" min="0" max="9999999999.99" step="0.01" inputmode="decimal" placeholder="0.00" @if ($errors->has('unit_price')) aria-invalid="true" @endif aria-describedby="unit-price-help unit-price-error">
        <p class="password-requirements" id="unit-price-help">Your usual selling price in pesos. Changing it later does not change any recorded credit.</p>
        <p class="field-error" id="unit-price-error" role="alert" @if (!$errors->has('unit_price')) hidden @endif>@error('unit_price'){{ $message }}@enderror</p>
    </div>

    <div class="form-field">
        <label for="description">Description <span class="credit-optional">Optional</span></label>
        <textarea class="auth-input credit-textarea" id="description" name="description" maxlength="500" rows="2" placeholder="Size, brand, or variant" @if ($errors->has('description')) aria-invalid="true" @endif aria-describedby="description-error">{{ old('description', $product->description) }}</textarea>
        <p class="field-error" id="description-error" role="alert" @if (!$errors->has('description')) hidden @endif>@error('description'){{ $message }}@enderror</p>
    </div>

    <div class="customer-form-actions">
        <a class="customer-cancel-link" href="{{ route('products.index') }}">Cancel</a>
        <button class="login-submit" type="submit">{{ $product->exists ? 'Save changes' : 'Add product' }}</button>
    </div>
</form>
