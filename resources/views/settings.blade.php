@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Payment settings', $organisation->name]))

@section('content')
<div class="d-grid gap-3">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                <div>
                    <h5 class="fs-6 mb-1">Payment settings of {{ $organisation->name }}</h5>
                    <p class="text-muted small mb-0">How people pay this organisation's orders (event tickets, memberships).</p>
                </div>
                @if ($organisations->count() > 1)
                    <select class="form-select form-select-sm w-auto" aria-label="Organisation" onchange="if (this.value) window.location = this.value">
                        @foreach ($organisations as $org)
                            <option value="{{ route('payment.settings.edit', $org->uid) }}" @selected($org->uid === $organisation->uid)>{{ $org->name }}</option>
                        @endforeach
                    </select>
                @endif
            </div>

            {{-- Form schema payment-settings; entity_uid: the organisation (its {uid}). Read-only first, Edit switches. --}}
            @include('userinterface::components.form', [
                'id' => $formId,
                'entity_uid' => $organisation->uid,
                'form_mode' => 'view',
                'formData' => json_encode($formData, JSON_UNESCAPED_UNICODE),
            ])
        </div>
    </div>
</div>
@endsection
