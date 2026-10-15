{{ $appName }} — Credit Entry Receipt

Hello {{ $customer->full_name }}, your purchase has been recorded as store credit.

Transaction: Credit Entry Receipt
Date and time: {{ $transactionDate }}
Credit amount: ₱{{ number_format((float) $ledgerEntry->amount, 2) }}
Outstanding balance: ₱{{ number_format((float) $ledgerEntry->running_balance, 2) }}

@if (count($items) > 0)
Items:
@foreach ($items as $item)
- {{ $item }}
@endforeach

@endif
@if (filled($ledgerEntry->note))
Note: {{ $ledgerEntry->note }}

@endif
This is an automated message from {{ $appName }}.
