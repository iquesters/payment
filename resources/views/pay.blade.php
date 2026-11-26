@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Pay', $order->reference]))

@section('content')
@php
    $money = fn ($v) => $order->currency.' '.number_format((float) $v, 2);
    $lastRejected = $payments->firstWhere('status', \Iquesters\Payment\Models\Payment::STATUS_REJECTED);
@endphp
<div class="d-grid gap-3">
    @if (session('success'))
        <div class="alert alert-success small mb-0">{{ session('success') }}</div>
    @endif

    {{-- The order --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between gap-2">
                <div>
                    <div class="small text-muted">{{ $order->organisation?->name }}</div>
                    <h5 class="fs-6 mb-1">Order <span class="font-monospace">{{ $order->reference }}</span></h5>
                    <span class="badge {{ $order->isPaid() ? 'text-bg-success' : ($order->status === \Iquesters\Commerce\Models\Order::STATUS_UNDER_REVIEW ? 'text-bg-info' : ($order->isPayable() ? 'text-bg-warning' : 'text-bg-secondary')) }}">{{ $order->statusLabel() }}</span>
                </div>
                <div class="text-end">
                    <div class="small text-muted">To pay</div>
                    <div class="fs-4 fw-semibold">{{ $money($order->total) }}</div>
                </div>
            </div>
            <ul class="list-unstyled small mt-3 mb-0">
                @foreach ($order->lines as $line)
                    <li class="d-flex justify-content-between gap-2 py-1 border-top">
                        <span>{{ $line->quantity }} × {{ $line->description }}</span>
                        <span class="text-nowrap">
                            @if ((float) $line->discount > 0)<s class="text-muted me-1">{{ $money((float) $line->unit_price * $line->quantity) }}</s>@endif
                            {{ $money($line->line_total) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>

    @if ($order->isPaid())
        <div class="card border-0 shadow-sm">
            <div class="card-body small">
                <p class="mb-2"><i class="fas fa-circle-check text-success me-1"></i>Paid. Thank you!</p>
                @if ($returnUrl)<a href="{{ $returnUrl }}" class="btn btn-primary btn-sm">Done</a>@endif
            </div>
        </div>
    @elseif ($order->status === \Iquesters\Commerce\Models\Order::STATUS_UNDER_REVIEW)
        <div class="card border-0 shadow-sm">
            <div class="card-body small">
                <p class="mb-2"><i class="fas fa-hourglass-half text-info me-1"></i>We received your payment details. The organisers will check them against their bank statement; this page shows when it's done.</p>
                @if ($returnUrl)<a href="{{ $returnUrl }}" class="btn btn-outline-primary btn-sm">Done</a>@endif
            </div>
        </div>
    @elseif ($order->isPayable())
        @if ($lastRejected && $order->status === \Iquesters\Commerce\Models\Order::STATUS_REJECTED)
            <div class="alert alert-danger small mb-0">
                Your last payment details weren't accepted{{ $lastRejected->reason ? ': '.$lastRejected->reason : '.' }}
                Please check and send them again.
            </div>
        @endif

        @if ($upiLink)
            {{-- Step 1: pay by UPI --}}
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fs-6 mb-1">1. Pay {{ $money($order->total) }} by UPI</h6>
                    <p class="small text-muted mb-3">Scan with any UPI app (GPay, PhonePe, Paytm, your bank's app). The amount and the reference <span class="font-monospace">{{ $order->reference }}</span> are filled in for you.</p>
                    <div class="row g-3 align-items-center">
                        <div class="col-12 col-md-auto text-center">
                            <div id="upi-qr" class="d-inline-block p-2 bg-white border rounded" aria-label="UPI QR code"></div>
                            <div class="d-md-none mt-2">
                                <a href="{{ $upiLink }}" class="btn btn-primary btn-sm"><i class="fas fa-mobile-screen me-1"></i>Open in a UPI app</a>
                            </div>
                        </div>
                        <div class="col small">
                            <dl class="row mb-0">
                                <dt class="col-5 col-sm-4 fw-normal text-muted">UPI ID</dt>
                                <dd class="col-7 col-sm-8 font-monospace text-break">{{ $method->setting('upi_id') }}</dd>
                                <dt class="col-5 col-sm-4 fw-normal text-muted">Name</dt>
                                <dd class="col-7 col-sm-8">{{ $method->setting('payee_name') }}</dd>
                                <dt class="col-5 col-sm-4 fw-normal text-muted">Amount</dt>
                                <dd class="col-7 col-sm-8 fw-semibold">{{ $money($order->total) }}</dd>
                                <dt class="col-5 col-sm-4 fw-normal text-muted">Reference</dt>
                                <dd class="col-7 col-sm-8 font-monospace">{{ $order->reference }}</dd>
                            </dl>
                            @if ($method->setting('instructions'))
                                <p class="mb-0 mt-2">{!! nl2br(e($method->setting('instructions'))) !!}</p>
                            @endif
                            <p class="text-muted mb-0 mt-2">Pay the exact amount, once. If the app doesn't fill the amount, type it in.</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Step 2: send the UTR and the screenshot (form schema payment-proof; {uid} = the order) --}}
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fs-6 mb-1">2. Send the payment details</h6>
                    <p class="small text-muted mb-3">After paying, enter the transaction ID (UTR) from your UPI app and attach a screenshot.</p>
                    @include('userinterface::components.form', ['id' => $formId, 'entity_uid' => $order->uid, 'formData' => ''])
                </div>
            </div>
        @else
            <div class="card border-0 shadow-sm">
                <div class="card-body small">
                    <p class="mb-0">Online payment isn't set up for {{ $order->organisation?->name }} yet. Please pay the organisers directly (cash or UPI at the desk) and quote <span class="font-monospace">{{ $order->reference }}</span>.</p>
                </div>
            </div>
        @endif
    @else
        <div class="card border-0 shadow-sm">
            <div class="card-body small"><p class="mb-0">This order is {{ strtolower($order->statusLabel()) }}; nothing to pay.</p></div>
        </div>
    @endif

    @if ($payments->isNotEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body small">
                <h6 class="fs-6 mb-2">Payments</h6>
                <ul class="list-unstyled mb-0">
                    @foreach ($payments as $payment)
                        <li class="py-1 border-top d-flex flex-wrap justify-content-between gap-2">
                            <span>{{ $payment->created_at?->format('j M Y, H:i') }} · {{ $payment->kind === \Iquesters\Payment\Models\PaymentMethod::KIND_CASH ? 'Cash' : 'UPI' }}@if ($payment->utr) · UTR <span class="font-monospace">{{ $payment->utr }}</span>@endif</span>
                            <span>{{ $money($payment->amount) }} · {{ $payment->statusLabel() }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @if ($refundPolicy)
        <div class="card border-0 shadow-sm">
            <div class="card-body small">
                <h6 class="fs-6 mb-1">Refund and cancellation</h6>
                <p class="mb-0 text-muted">{!! nl2br(e($refundPolicy)) !!}</p>
            </div>
        </div>
    @endif
</div>

@if ($order->isPayable() && $upiLink)
    {{-- QR drawn in the browser (qrcodejs 1.0.0), as on the event feedback poster. --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
        new QRCode(document.getElementById('upi-qr'), { text: @json($upiLink), width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M });
    </script>
@endif
@endsection
