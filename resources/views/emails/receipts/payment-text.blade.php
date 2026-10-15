{{ $appName }} — Payment Receipt

Hello {{ $customer->full_name }}, your payment has been recorded.

Transaction: Payment Receipt
Date and time: {{ $transactionDate }}
Payment received: ₱{{ number_format((float) $amount, 2) }}
Remaining balance: ₱{{ number_format((float) $remainingBalance, 2) }}
@if ($debtCycleCompleted)

Fully paid — your account balance is ₱0.00. Thank you!
@endif
@if (filled($payment->note))

Note: {{ $payment->note }}
@endif

This is an automated message from {{ $appName }}.
