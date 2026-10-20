@if ($status && $status['state'] !== \App\Services\LatePenaltyService::STATE_NOT_COVERED)
    <span class="portal-muted portal-fee-terms">
        @switch($status['state'])
            @case(\App\Services\LatePenaltyService::STATE_NOT_DUE)
                Late fee of ₱{{ $status['fee'] }} if not fully paid by {{ $status['grace_ends_on']->format('M j, Y') }} (deadline plus grace period).
                @break
            @case(\App\Services\LatePenaltyService::STATE_IN_GRACE)
                Grace period until {{ $status['grace_ends_on']->format('M j, Y') }}. A ₱{{ $status['fee'] }} late fee is added if still unpaid after that.
                @break
            @case(\App\Services\LatePenaltyService::STATE_ELIGIBLE)
                The grace period ended {{ $status['grace_ends_on']->format('M j, Y') }}. A ₱{{ $status['fee'] }} late fee will be added.
                @break
            @case(\App\Services\LatePenaltyService::STATE_APPLIED)
                Late fee of ₱{{ $status['penalty']->amount }} added on {{ $status['penalty']->transaction_date->format('M j, Y') }}.
                @break
            @case(\App\Services\LatePenaltyService::STATE_WAIVED)
                A late fee was added on {{ $status['penalty']->transaction_date->format('M j, Y') }}, then waived by the store.
                @break
            @case(\App\Services\LatePenaltyService::STATE_PAID_IN_TIME)
                Paid in time. No late fee.
                @break
        @endswitch
    </span>
@endif
