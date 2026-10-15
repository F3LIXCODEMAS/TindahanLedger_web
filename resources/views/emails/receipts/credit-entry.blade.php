@extends('emails.layouts.base')

@section('content')
    <h1 style="margin: 0 0 16px; color: #24312b; font-size: 22px; line-height: 1.3;">Credit Entry Receipt</h1>
    <p style="margin: 0 0 16px;">Hello {{ $customer->full_name }}, your purchase has been recorded as store credit.</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin-bottom: 20px;">
        <tr>
            <td style="padding: 8px 0; color: #637169;">Transaction</td>
            <td align="right" style="padding: 8px 0; font-weight: bold;">Credit Entry Receipt</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; color: #637169;">Date and time</td>
            <td align="right" style="padding: 8px 0;">{{ $transactionDate }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; color: #637169;">Credit amount</td>
            <td align="right" style="padding: 8px 0; font-weight: bold;">₱{{ number_format((float) $ledgerEntry->amount, 2) }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; color: #637169;">Outstanding balance</td>
            <td align="right" style="padding: 8px 0; font-weight: bold;">₱{{ number_format((float) $ledgerEntry->running_balance, 2) }}</td>
        </tr>
    </table>

    @if (count($items) > 0)
        <h2 style="margin: 0 0 8px; font-size: 16px;">Items</h2>
        <ul style="margin: 0 0 20px; padding-left: 22px;">
            @foreach ($items as $item)
                <li style="margin-bottom: 4px;">{{ $item }}</li>
            @endforeach
        </ul>
    @endif

    @if (filled($ledgerEntry->note))
        <p style="margin: 0; padding: 14px 16px; background-color: #f4f7f5; border-left: 4px solid #176b45;">
            <strong>Note:</strong> {{ $ledgerEntry->note }}
        </p>
    @endif
@endsection
