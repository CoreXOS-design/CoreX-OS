{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md --}}
{{--
    AT-215 (DR2) — Deal Register (DR2) front page (shared shell).
    DR2 rebuilds DR1 on the SAME `deals` rows. This is the branchable skeleton:
    the register list + the New Deal + Pipeline entry points. AT-217 (cc3) enriches
    capture; the register list refines under WS0. Spec: .ai/specs/deal-register-v2-rebuild-spec.md
--}}
@extends('layouts.corex')

@section('corex-content')
<div class="corex-page">
    <div class="corex-page-header" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 class="corex-page-title">Deal Register (DR2)</h1>
            <p class="corex-page-subtitle">The same deals as the classic register, on the rebuilt DR2 experience.</p>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            @permission('create_deals')
            <a href="{{ route('deals-dr2.create') }}" class="corex-btn-primary">+ New Deal</a>
            @endpermission
            @if(\Illuminate\Support\Facades\Route::has('deals-v2.pipeline.index'))
            <a href="{{ route('deals-v2.pipeline.index') }}" class="corex-btn-secondary">Pipeline Setup</a>
            @endif
        </div>
    </div>

    @if(session('info'))
        <div class="corex-alert corex-alert-info" style="margin:1rem 0;">{{ session('info') }}</div>
    @endif

    <div class="corex-card" style="margin-top:1rem;">
        @if($deals->isEmpty())
            <p style="padding:1.5rem;text-align:center;color:var(--corex-text-muted,#6b7280);">
                No deals yet. Capture the first one with <strong>New Deal</strong>.
            </p>
        @else
        <div style="overflow-x:auto;">
            <table class="corex-table" style="width:100%;">
                <thead>
                    <tr>
                        <th>Deal #</th>
                        <th>Date</th>
                        <th>Property</th>
                        <th>Seller</th>
                        <th>Buyer</th>
                        <th style="text-align:right;">Commission</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($deals as $deal)
                    <tr>
                        <td>{{ $deal->deal_no ?? $deal->id }}</td>
                        <td>{{ optional($deal->deal_date)->format('d M Y') }}</td>
                        <td>{{ $deal->property_address ?? '—' }}</td>
                        <td>{{ $deal->seller_name ?? '—' }}</td>
                        <td>{{ $deal->buyer_name ?? '—' }}</td>
                        <td style="text-align:right;">R {{ number_format((float) $deal->total_commission, 2) }}</td>
                        <td>{{ $deal->commission_status ?? $deal->accepted_status ?? '—' }}</td>
                        <td style="text-align:right;">
                            @permission('create_deals')
                            <a href="{{ route('deals-dr2.edit', $deal) }}" class="corex-link">Open</a>
                            @endpermission
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endsection
