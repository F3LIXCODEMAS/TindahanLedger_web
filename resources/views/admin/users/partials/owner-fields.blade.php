@php($usernameHelp ??= 'The owner signs in with this username.')

<div class="form-field">
    <label for="name">Owner full name</label>
    <input class="auth-input" id="name" name="name" type="text" value="{{ old('name') }}" maxlength="255" autocomplete="off" required>
    <p class="field-error" role="alert" @if (!$errors->has('name')) hidden @endif>@error('name'){{ $message }}@enderror</p>
</div>

<div class="form-field">
    <label for="username">Username</label>
    <input class="auth-input" id="username" name="username" type="text" value="{{ old('username') }}" maxlength="120" autocomplete="off" autocapitalize="none" aria-describedby="username-help">
    <p class="password-requirements" id="username-help">{{ $usernameHelp }} Usernames are not case-sensitive.</p>
    <p class="field-error" role="alert" @if (!$errors->has('username')) hidden @endif>@error('username'){{ $message }}@enderror</p>
</div>

<div class="form-field">
    <label for="email">Owner email address</label>
    <input class="auth-input" id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" autocomplete="off" required>
    <p class="field-error" role="alert" @if (!$errors->has('email')) hidden @endif>@error('email'){{ $message }}@enderror</p>
</div>

@include('admin.users.partials.temporary-password-fields')
