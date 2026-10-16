@php
    $chartWidth = 720;
    $chartHeight = 260;
    $plotLeft = 72;
    $plotRight = $chartWidth - 8;
    $plotTop = 12;
    $plotBottom = $chartHeight - 36;
    $plotWidth = $plotRight - $plotLeft;
    $plotHeight = $plotBottom - $plotTop;

    $largestCents = max(1, ...array_map(fn (array $bucket): int => max($bucket['credit_cents'], $bucket['payment_cents']), $trends));
    $magnitude = 10 ** max(0, (int) floor(log10($largestCents)));
    $scaleMaxCents = collect([1, 2, 5, 10])->map(fn (int $step): int => $step * $magnitude)->first(fn (int $ceiling): bool => $ceiling >= $largestCents);

    $groupCount = count($trends);
    $groupWidth = $plotWidth / max(1, $groupCount);
    $barWidth = max(2, min(18, ($groupWidth - 6) / 2 - 1));
    $labelEvery = (int) ceil($groupCount / 12);

    $barPath = function (float $x, float $width, float $height) use ($plotBottom): string {
        if ($height <= 0) {
            return '';
        }

        $radius = min(4, $width / 2, $height);
        $top = $plotBottom - $height;

        return sprintf(
            'M%.2f,%.2f V%.2f Q%.2f,%.2f %.2f,%.2f H%.2f Q%.2f,%.2f %.2f,%.2f V%.2f Z',
            $x, $plotBottom,
            $top + $radius,
            $x, $top, $x + $radius, $top,
            $x + $width - $radius,
            $x + $width, $top, $x + $width, $top + $radius,
            $plotBottom,
        );
    };
    $heightFor = fn (int $cents): float => $cents / $scaleMaxCents * $plotHeight;
@endphp

<div class="analytics-legend" aria-hidden="true">
    <span><i class="analytics-swatch is-credit"></i>Credits recorded</span>
    <span><i class="analytics-swatch is-payment"></i>Payments received</span>
</div>

<svg class="analytics-chart" viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" role="img" aria-labelledby="trend-chart-title trend-chart-description">
    <title id="trend-chart-title">Credits recorded and payments received per {{ $interval }}</title>
    <desc id="trend-chart-description">Bar chart of peso amounts. The data table below lists every value.</desc>

    @foreach ([0, 0.25, 0.5, 0.75, 1] as $tickRatio)
        @php($tickY = $plotBottom - $tickRatio * $plotHeight)
        <line class="analytics-grid" x1="{{ $plotLeft }}" x2="{{ $plotRight }}" y1="{{ $tickY }}" y2="{{ $tickY }}"></line>
        <text class="analytics-axis-label" x="{{ $plotLeft - 8 }}" y="{{ $tickY + 4 }}" text-anchor="end">₱{{ number_format($scaleMaxCents * $tickRatio / 100) }}</text>
    @endforeach

    @foreach ($trends as $index => $bucket)
        @php($groupX = $plotLeft + $index * $groupWidth + ($groupWidth - (2 * $barWidth + 2)) / 2)
        <path class="analytics-bar is-credit" d="{{ $barPath($groupX, $barWidth, $heightFor($bucket['credit_cents'])) }}">
            <title>{{ $bucket['label'] }} · Credits ₱{{ $bucket['credit_amount'] }} ({{ $bucket['credit_count'] }})</title>
        </path>
        <path class="analytics-bar is-payment" d="{{ $barPath($groupX + $barWidth + 2, $barWidth, $heightFor($bucket['payment_cents'])) }}">
            <title>{{ $bucket['label'] }} · Payments ₱{{ $bucket['payment_amount'] }} ({{ $bucket['payment_count'] }})</title>
        </path>
        <rect class="analytics-hit-area" x="{{ $plotLeft + $index * $groupWidth }}" y="{{ $plotTop }}" width="{{ $groupWidth }}" height="{{ $plotHeight }}">
            <title>{{ $bucket['label'] }} · Credits ₱{{ $bucket['credit_amount'] }} ({{ $bucket['credit_count'] }}) · Payments ₱{{ $bucket['payment_amount'] }} ({{ $bucket['payment_count'] }})</title>
        </rect>
        @if ($index % $labelEvery === 0)
            <text class="analytics-axis-label" x="{{ $plotLeft + ($index + 0.5) * $groupWidth }}" y="{{ $plotBottom + 18 }}" text-anchor="middle">{{ $bucket['label'] }}</text>
        @endif
    @endforeach

    <line class="analytics-baseline" x1="{{ $plotLeft }}" x2="{{ $plotRight }}" y1="{{ $plotBottom }}" y2="{{ $plotBottom }}"></line>
</svg>

<details class="analytics-table-toggle">
    <summary>Show data table</summary>
    <table class="admin-table">
        <thead>
            <tr>
                <th scope="col">Period</th>
                <th scope="col" class="is-amount">Credits</th>
                <th scope="col" class="is-amount">Credit count</th>
                <th scope="col" class="is-amount">Payments</th>
                <th scope="col" class="is-amount">Payment count</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($trends as $bucket)
                <tr>
                    <td data-label="Period">{{ $bucket['label'] }}</td>
                    <td data-label="Credits" class="is-amount">₱{{ $bucket['credit_amount'] }}</td>
                    <td data-label="Credit count" class="is-amount">{{ $bucket['credit_count'] }}</td>
                    <td data-label="Payments" class="is-amount">₱{{ $bucket['payment_amount'] }}</td>
                    <td data-label="Payment count" class="is-amount">{{ $bucket['payment_count'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</details>
