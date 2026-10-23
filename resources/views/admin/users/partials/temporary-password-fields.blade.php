<div class="form-field">
    <label for="temporary_password">Temporary password</label>
    <div class="password-field">
        <input class="auth-input" id="temporary_password" name="temporary_password" type="password" autocomplete="new-password" minlength="12" required aria-describedby="temporary-password-help">
        <button class="password-toggle" type="button" data-password-toggle aria-controls="temporary_password" aria-pressed="false" aria-label="Show password">Show</button>
    </div>
    <p class="password-requirements" id="temporary-password-help">At least 12 characters with letters and numbers. Share it with the owner privately; they must replace it at first sign-in.</p>
    <p class="field-error" role="alert" @if (!$errors->has('temporary_password')) hidden @endif>@error('temporary_password'){{ $message }}@enderror</p>
</div>

<div class="form-field">
    <label for="temporary_password_confirmation">Confirm temporary password</label>
    <div class="password-field">
        <input class="auth-input" id="temporary_password_confirmation" name="temporary_password_confirmation" type="password" autocomplete="new-password" minlength="12" required>
        <button class="password-toggle" type="button" data-password-toggle aria-controls="temporary_password_confirmation" aria-pressed="false" aria-label="Show password">Show</button>
    </div>
</div>
