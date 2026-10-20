@extends('portal.layout')

@section('title', 'Customer Portal')

@section('content')
    <section class="portal-card portal-entry" aria-labelledby="portal-entry-title">
        <p class="eyebrow">CUSTOMER PORTAL</p>
        <h1 id="portal-entry-title">View your account</h1>

        @if (session('portal_message'))
            <p class="portal-notice" role="status">{{ session('portal_message') }}</p>
        @endif

        <form method="POST" action="{{ route('portal.access') }}" data-portal-access-form>
            @csrf
            <input type="hidden" name="token" value="">
            <p class="portal-loading" data-portal-loading hidden role="status">Opening your account…</p>
        </form>

        <div data-portal-instructions>
            <p>Scan the QR code on the card from your store using your phone camera, then tap the link that appears.</p>
            <p class="portal-muted">No password is needed. Keep your card private: anyone who scans it can view your account.</p>
        </div>

        <noscript>
            <p class="portal-notice">Please turn on JavaScript in your browser to open your account from the QR code.</p>
        </noscript>
    </section>
@endsection
