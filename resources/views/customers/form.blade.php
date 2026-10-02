<form class="customer-form" method="POST" action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}" data-customer-form>
    @csrf
    @if ($customer->exists)
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
        <label for="full_name">Full name <span aria-hidden="true">*</span></label>
        <input class="auth-input" id="full_name" name="full_name" type="text" value="{{ old('full_name', $customer->full_name) }}" autocomplete="name" maxlength="120" pattern=".*\S.*" data-trim-required data-empty-message="Please enter the customer name." required @if ($errors->has('full_name')) aria-invalid="true" @endif aria-describedby="full-name-error">
        <p class="field-error" id="full-name-error" role="alert" @if (!$errors->has('full_name')) hidden @endif>@error('full_name'){{ $message }}@enderror</p>
    </div>

    <div class="form-field">
        <label for="contact_number">Contact number <span aria-hidden="true">*</span></label>
        <input class="auth-input" id="contact_number" name="contact_number" type="tel" value="{{ old('contact_number', $customer->contact_number) }}" autocomplete="tel" inputmode="tel" placeholder="09171234567" pattern="(?:09|\+?639)[\s().-]*\d(?:[\s().-]*\d){8}" title="Enter a Philippine mobile number such as 09171234567 or +639171234567." required @if ($errors->has('contact_number')) aria-invalid="true" @endif aria-describedby="contact-number-help contact-number-error">
        <p class="password-requirements" id="contact-number-help">Use a Philippine mobile number, for example 09171234567 or +639171234567.</p>
        <p class="field-error" id="contact-number-error" role="alert" @if (!$errors->has('contact_number')) hidden @endif>@error('contact_number'){{ $message }}@enderror</p>
    </div>

    <div class="form-field">
        <label for="email">Email address <span aria-hidden="true">*</span></label>
        <input class="auth-input" id="email" name="email" type="email" value="{{ old('email', $customer->email) }}" autocomplete="email" inputmode="email" maxlength="255" placeholder="customer@example.com" data-normalize-email required @if ($errors->has('email')) aria-invalid="true" @endif aria-describedby="email-help email-error">
        <p class="password-requirements" id="email-help">Used for future customer reminders. No email is sent when saving a record.</p>
        <p class="field-error" id="email-error" role="alert" @if (!$errors->has('email')) hidden @endif>@error('email'){{ $message }}@enderror</p>
    </div>

    <div class="form-field">
        <label for="residential_landmark">Residential landmark <span aria-hidden="true">*</span></label>
        <input class="auth-input" id="residential_landmark" name="residential_landmark" type="text" value="{{ old('residential_landmark', $customer->residential_landmark) }}" maxlength="255" data-trim-required data-empty-message="Please enter a residential landmark." required @if ($errors->has('residential_landmark')) aria-invalid="true" @endif aria-describedby="landmark-error">
        <p class="field-error" id="landmark-error" role="alert" @if (!$errors->has('residential_landmark')) hidden @endif>@error('residential_landmark'){{ $message }}@enderror</p>
    </div>

    <div class="form-field">
        <label for="credit_limit">Credit limit <span aria-hidden="true">*</span></label>
        <input class="auth-input" id="credit_limit" name="credit_limit" type="number" value="{{ old('credit_limit', $customer->credit_limit) }}" min="0.01" max="9999999999.99" step="0.01" inputmode="decimal" required @if ($errors->has('credit_limit')) aria-invalid="true" @endif aria-describedby="credit-limit-help credit-limit-error">
        <p class="password-requirements" id="credit-limit-help">Maximum amount the customer may owe the store.</p>
        <p class="field-error" id="credit-limit-error" role="alert" @if (!$errors->has('credit_limit')) hidden @endif>@error('credit_limit'){{ $message }}@enderror</p>
    </div>

    <div class="customer-form-actions">
        <a class="customer-cancel-link" href="{{ route('customers.index') }}">Cancel</a>
        <button class="login-submit" type="submit">{{ $customer->exists ? 'Save changes' : 'Add customer' }}</button>
    </div>
</form>
