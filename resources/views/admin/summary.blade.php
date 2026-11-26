{{-- Record section (Payments table): what was expected and what was sent, the proof, the order. --}}
@php
    $order = $payment->order;
    $money = fn ($v) => $payment->currency.' '.number_format((float) $v, 2);
    $mismatch = round((float) $payment->amount, 2) !== round((float) $order->total, 2);
    $proofUrl = $payment->proof_path ? route('payment.payments.proof', $payment->uid) : null;
@endphp
<div class="small">
    <div class="row g-3 mb-2">
        <div class="col-6 col-md-3">
            <div class="text-muted">Order</div>
            <div class="fw-semibold font-monospace">{{ $order->reference }}</div>
            <div class="text-muted">{{ $order->statusLabel() }}</div>
        </div>
        <div class="col-6 col-md-3">
            <div class="text-muted">Expected</div>
            <div class="fs-6 fw-semibold">{{ $money($order->total) }}</div>
        </div>
        <div class="col-6 col-md-3">
            <div class="text-muted">{{ $payment->kind === \Iquesters\Payment\Models\PaymentMethod::KIND_CASH ? 'Cash' : 'UPI' }} payment</div>
            <div class="fs-6 fw-semibold {{ $mismatch ? 'text-danger' : '' }}">{{ $money($payment->amount) }}</div>
            @if ($mismatch)<div class="text-danger">Differs from the order total</div>@endif
        </div>
        <div class="col-6 col-md-3">
            <div class="text-muted">UTR</div>
            <div class="fw-semibold font-monospace text-break">{{ $payment->utr ?: '—' }}</div>
            <div class="text-muted">{{ $payment->paid_on?->format('j M Y') }}</div>
        </div>
    </div>

    <div class="mb-2">
        <span class="badge {{ $payment->status === \Iquesters\Payment\Models\Payment::STATUS_VERIFIED ? 'text-bg-success' : ($payment->status === \Iquesters\Payment\Models\Payment::STATUS_REJECTED ? 'text-bg-danger' : 'text-bg-warning') }}">{{ $payment->statusLabel() }}</span>
        @if ($payment->reason)<span class="text-muted ms-1">Reason: {{ $payment->reason }}</span>@endif
        @if ($payment->note)<span class="text-muted ms-1">Note: {{ $payment->note }}</span>@endif
    </div>

    <div class="mb-3">
        <div class="text-muted">Paid by</div>
        <div>{{ $order->buyer_name }}@if ($order->buyer_phone) · {{ $order->buyer_phone }}@endif @if ($order->buyer_email) · {{ $order->buyer_email }}@endif</div>
    </div>

    @if ($proofUrl)
        <div class="mb-3">
            <div class="text-muted mb-1">Screenshot</div>
            @if ($payment->isImage())
                <a href="{{ $proofUrl }}" target="_blank" rel="noopener">
                    <img src="{{ $proofUrl }}" alt="Payment screenshot" class="img-fluid border rounded" style="max-height: 420px;">
                </a>
            @else
                <a href="{{ $proofUrl }}" target="_blank" rel="noopener"><i class="fas fa-file-pdf me-1"></i>{{ $payment->proof_name }}</a>
            @endif
        </div>
    @endif

    <div class="text-muted mb-1">Items</div>
    <ul class="list-unstyled mb-0">
        @foreach ($order->lines as $line)
            <li>{{ $line->quantity }} × {{ $line->description }} <span class="text-muted">· {{ $money($line->line_total) }}</span></li>
        @endforeach
    </ul>
</div>
