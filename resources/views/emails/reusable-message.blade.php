@extends('emails.layouts.base')

@section('content')
    <h1 style="margin: 0 0 16px; color: #24312b; font-size: 22px; line-height: 1.3;">{{ $greeting }}</h1>
    <p style="margin: 0 0 16px; white-space: pre-line;">{{ $body }}</p>

    @if ($details !== null)
        <p style="margin: 0; padding: 14px 16px; background-color: #f4f7f5; border-left: 4px solid #176b45; white-space: pre-line;">
            {{ $details }}
        </p>
    @endif
@endsection
