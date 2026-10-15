@extends('emails.layouts.base')

@section('content')
    <h1 style="margin: 0 0 16px; color: #24312b; font-size: 22px; line-height: 1.3;">Payment Receipt</h1>
    <p style="margin: 0 0 16px;">Hello {{ $customer->full_name }}, your payment has been recorded.</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin-bottom: 20px;">
        <tr>
            <td style="padding: 8px 0; color: #637169;">Transaction</td>
            <td align="right" style="padding: 8px 0; font-weight: bold;">Payment Receipt</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; color: #637169;">Date and time</td>
            <td align="right" style="padding: 8px 0;">{{ $transactionDate }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; color: #637169;">Payment received</td>
            <td align="right" style="padding: 8px 0; font-weight: bold;">₱{{ number_format((float) $amount, 2) }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; color: #637169;">Remaining balance</td>
            <td align="right" style="padding: 8px 0; font-weight: bold;">₱{{ number_format((float) $remainingBalance, 2) }}</td>
        </tr>
    </table>

    @if ($debtCycleCompleted)
        <p style="margin: 0 0 16px; padding: 14px 16px; background-color: #eaf5ee; border-left: 4px solid #176b45; font-weight: bold;">
            Fully paid — your account balance is ₱0.00. Thank you!
        </p>
    @endif

    @if (filled($payment->note))
        <p style="margin: 0; padding: 14px 16px; background-color: #f4f7f5; border-left: 4px solid #176b45;">
            <strong>Note:</strong> {{ $payment->note }}
        </p>
    @endif
@endsection
