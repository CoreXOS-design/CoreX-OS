{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md --}}
{{--
    AT-216 (DR2 · WS-PIPELINE) — pipeline tracking board for one DR2 deal.
    PURE TRACKING OVERLAY: attaching a pipeline / completing a step never changes the
    DR1 deal's state — only the pipeline's own steps + the deal's pipeline pointer.
--}}
@extends('layouts.corex')

@section('corex-content')
@php
    $statusStyles = [
        'not_started' => ['Not started', '#6b7280', '#f3f4f6'],
        'active'      => ['Active',      '#1d4ed8', '#dbeafe'],
        'completed'   => ['Completed',   '#047857', '#d1fae5'],
        'skipped'     => ['Skipped',     '#6b7280', '#f3f4f6'],
    ];
@endphp
<div class="corex-page">
    <div class="corex-page-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 class="corex-page-title">Deal Pipeline</h1>
            <p class="corex-page-subtitle">
                Deal {{ $deal->deal_no ?? $deal->id }}
                @if($deal->property_address) — {{ $deal->property_address }} @endif
            </p>
        </div>
        <a href="{{ route('deals-dr2.index') }}" class="corex-btn-secondary">← DR2 Register</a>
    </div>

    @if(session('info'))
        <div class="corex-alert corex-alert-info" style="margin:1rem 0;">{{ session('info') }}</div>
    @endif
    @if(session('error'))
        <div class="corex-alert corex-alert-danger" style="margin:1rem 0;">{{ session('error') }}</div>
    @endif

    @if($steps->isEmpty())
        {{-- No pipeline yet → attach one. --}}
        <div class="corex-card" style="margin-top:1rem;padding:1.5rem;max-width:520px;">
            <h2 style="margin:0 0 .75rem;font-size:1.05rem;">Attach a pipeline</h2>
            @if($templates->isEmpty())
                <p style="color:var(--corex-text-muted,#6b7280);">
                    No active pipeline templates for this agency yet. Create one under
                    @if(\Illuminate\Support\Facades\Route::has('deals-v2.pipeline.index'))
                        <a href="{{ route('deals-v2.pipeline.index') }}" class="corex-link">Pipeline Setup</a>.
                    @else Pipeline Setup. @endif
                </p>
            @else
            @permission('create_deals')
            <form method="POST" action="{{ route('deals-dr2.pipeline.attach', $deal) }}">
                @csrf
                <label for="template_id" style="display:block;margin-bottom:.35rem;font-weight:600;">
                    Template
                    @if($deal->deal_type)
                        <span style="font-weight:400;color:var(--corex-text-muted,#6b7280);">— defaulted from deal type "{{ $deal->deal_type }}"; change if needed</span>
                    @endif
                </label>
                <select name="template_id" id="template_id" class="corex-input" required style="width:100%;">
                    @foreach($templates as $t)
                        <option value="{{ $t->id }}" {{ (isset($defaultTemplateId) && $t->id === $defaultTemplateId) ? 'selected' : '' }}>{{ $t->name }}@if($t->deal_type) · {{ $t->deal_type }}@endif@if($t->is_default) (default)@endif</option>
                    @endforeach
                </select>
                <button type="submit" class="corex-btn-primary" style="margin-top:1rem;">Attach pipeline</button>
            </form>
            @endpermission
            @endif
        </div>
    @else
        {{-- Step board. --}}
        <div class="corex-card" style="margin-top:1rem;">
            <div style="overflow-x:auto;">
                <table class="corex-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:2.5rem;"></th>
                            <th>Step</th>
                            <th>Status</th>
                            <th>Due</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($steps as $row)
                            @php($s = $row['model'])
                            @php($badge = $statusStyles[$s->status] ?? [ucfirst($s->status), '#6b7280', '#f3f4f6'])
                            <tr>
                                <td>
                                    <span title="{{ ucfirst($row['rag']) }}" style="display:inline-block;width:.7rem;height:.7rem;border-radius:50%;background:{{ $row['colour'] }};"></span>
                                </td>
                                <td>
                                    <strong>{{ $s->name }}</strong>
                                    @if($s->is_milestone)
                                        <span style="font-size:.7rem;color:#b45309;margin-left:.35rem;">◆ milestone</span>
                                    @endif
                                    @if($row['blocked'])
                                        <div style="font-size:.75rem;color:#6b7280;">{{ $row['blocked'] }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span style="display:inline-block;padding:.15rem .5rem;border-radius:1rem;font-size:.75rem;color:{{ $badge[1] }};background:{{ $badge[2] }};">{{ $badge[0] }}</span>
                                </td>
                                <td>{{ $s->due_date ? \Illuminate\Support\Carbon::parse($s->due_date)->format('d M Y') : '—' }}</td>
                                <td style="text-align:right;">
                                    @if($s->status === 'active')
                                        @permission('create_deals')
                                        <form method="POST" action="{{ route('deals-dr2.pipeline.step.complete', [$deal, $s]) }}" style="display:inline;">
                                            @csrf
                                            <button type="submit" class="corex-btn-secondary" style="padding:.25rem .75rem;">Mark complete</button>
                                        </form>
                                        @endpermission
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
