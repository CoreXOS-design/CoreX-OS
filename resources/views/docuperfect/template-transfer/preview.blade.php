{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 — spec: .ai/specs/esign-template-transfer.md --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $anyBlocking = collect($previews)->contains(fn ($p) => ! empty($p['blocking']));
    $anyAck = collect($previews)->contains(fn ($p) => ! empty($p['needs_ack']));
    $anyClash = collect($previews)->contains(fn ($p) => ! empty($p['clash']['exists']));
@endphp
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Check before importing</h1>
        <p class="text-xs" style="color: var(--text-muted);">From "{{ $original }}". Nothing has been created yet. Below is exactly what will be created if you confirm.</p>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <div class="font-semibold">{{ $errors->first() }}</div>
            @if(session('error_details'))<ul class="list-disc pl-5 mt-1 text-xs">@foreach(session('error_details') as $line)<li>{{ $line }}</li>@endforeach</ul>@endif
        </div>
    @endif

    {{-- Step 1: which agency --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-2" style="color: var(--text-primary);">1. Which agency is this template for?</h2>
        <form method="GET" action="{{ route('docuperfect.template-transfer.preview', $token) }}" class="flex flex-wrap items-center gap-3">
            <select name="agency_id" onchange="this.form.submit()" class="list-header-filter" style="min-width: 16rem;">
                <option value="">Choose an agency…</option>
                @foreach($agencies as $a)
                    <option value="{{ $a->id }}" @selected($target && $target->id === $a->id)>{{ $a->name }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="corex-btn-outline text-xs px-3 py-2">Show</button></noscript>
        </form>
        <p class="text-[0.6875rem] mt-2" style="color: var(--text-muted);">The template is created inside this agency only. Agents of other agencies will never see it.
            @if($settings['default_visibility'] === 'all_branches') Every branch of the agency can use it.@else Only agency administrators can use it until branches are assigned.@endif</p>
    </div>

    {{-- Step 2: what will be created --}}
    @foreach($previews as $p)
        <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex flex-wrap items-start justify-between gap-2 mb-3">
                <div>
                    <h2 class="text-sm font-semibold" style="color: var(--text-primary);">{{ $p['name'] }}</h2>
                    <p class="text-xs" style="color: var(--text-muted);">{{ $p['render_type'] === 'web' ? 'Web document' : 'PDF document' }}@if($p['category']) · {{ ucfirst($p['category']) }}@endif · Exported from {{ $p['source_label'] ?? 'another system' }}@if($p['exported_at']) on {{ \Illuminate\Support\Carbon::parse($p['exported_at'])->format('d M Y H:i') }}@endif</p>
                </div>
                <span class="text-[0.6875rem] font-mono" style="color: var(--text-muted);" title="{{ $p['checksum'] }}">Checksum {{ substr($p['checksum'], 0, 12) }}…</span>
            </div>

            <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2 text-xs" style="color: var(--text-secondary);">
                <div><dt class="font-semibold">Document type</dt><dd>@if($p['document_type']['status'] === 'none')None @elseif($p['document_type']['status'] === 'found'){{ $p['document_type']['label'] }} (exists) @else{{ $p['document_type']['label'] }} — not on this system @endif</dd></div>
                <div><dt class="font-semibold">Signers</dt><dd>{{ $p['roles'] ? implode(', ', array_map(fn ($r) => ucfirst(str_replace('_', ' ', $r)), $p['roles'])) : 'None listed' }}</dd></div>
                <div><dt class="font-semibold">Fields</dt><dd>{{ $p['tag_count'] }} on the document</dd></div>
                <div><dt class="font-semibold">E-signing</dt><dd>@if($p['esign_switched_off'])<strong>Will be switched OFF</strong> — this is a sale document type, so it arrives on wet ink. An administrator can switch e-signing on afterwards in template setup. @elseif($p['is_esign'])On @else Off @endif</dd></div>
                @if($p['render_type'] === 'pdf')<div><dt class="font-semibold">Pages</dt><dd>{{ $p['page_count'] }} page images, {{ $p['zone_count'] }} signature positions</dd></div>@endif
                <div><dt class="font-semibold">Field definitions</dt><dd><span>{{ count($p['fields_existing']) }} already exist</span>@if($p['fields_new']), <strong>{{ count($p['fields_new']) }} will be created</strong>: {{ implode(', ', $p['fields_new']) }}@endif</dd></div>
                @if($target)<div><dt class="font-semibold">Field groups</dt><dd><span>{{ count($p['groups_existing']) }} already in the agency</span>@if($p['groups_new']), <strong>{{ count($p['groups_new']) }} will be created</strong>: {{ implode(', ', $p['groups_new']) }}@endif</dd></div>@endif
            </dl>

            @foreach($p['blocking'] as $line)<div class="mt-3 text-xs rounded-md px-3 py-2" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--text-primary);">{{ $line }}</div>@endforeach
            @foreach($p['needs_ack'] as $line)<div class="mt-3 text-xs rounded-md px-3 py-2" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">{{ $line }}</div>@endforeach
            @foreach($p['warnings'] as $line)<div class="mt-3 text-xs rounded-md px-3 py-2" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">Note: {{ $line }}</div>@endforeach
            @if($p['removed_identifiers'])<div class="mt-3 text-xs rounded-md px-3 py-2" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">Note: {{ count($p['removed_identifiers']) }} identifier(s) from the other system were left out of the package on purpose.</div>@endif

            @if($target && $p['clash']['exists'])
                <div class="mt-4 rounded-md px-3 py-3 text-xs" style="border: 1px solid var(--border);">
                    <p class="font-semibold mb-2" style="color: var(--text-primary);">{{ $target->name }} already has a template called "{{ $p['name'] }}"{{ $p['clash']['archived'] ? ' (archived)' : '' }}. It will NOT be replaced. What should happen?</p>
                    <label class="flex items-center gap-2 mb-1"><input type="radio" name="choice[{{ $p['index'] }}]" value="new_version" form="confirmForm" required> Import as a new version (name gets a version number)</label>
                    <label class="flex items-center gap-2"><input type="radio" name="choice[{{ $p['index'] }}]" value="new_copy" form="confirmForm" required> Import as a new copy (name gets today's date)</label>
                </div>
            @endif
        </div>
    @endforeach

    {{-- Step 3: confirm --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">2. Confirm</h2>
        <form id="confirmForm" method="POST" action="{{ route('docuperfect.template-transfer.confirm', $token) }}" class="space-y-3">
            @csrf
            <input type="hidden" name="agency_id" value="{{ $target?->id }}">
            @if($anyAck)
                <label class="flex items-start gap-2 text-xs" style="color: var(--text-primary);"><input type="checkbox" name="allow_missing_document_type" value="1" class="mt-0.5"> I understand the document type is missing here and the template will be imported without one.</label>
            @endif
            <label class="flex items-start gap-2 text-xs" style="color: var(--text-primary);"><input type="checkbox" name="confirmed" value="1" class="mt-0.5"> I have read the preview above and want to create {{ count($previews) === 1 ? 'this template' : 'these ' . count($previews) . ' templates' }}@if($target) in {{ $target->name }}@endif.</label>
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="corex-btn-primary text-xs px-4 py-2" @disabled(! $target || $anyBlocking)>Create {{ count($previews) === 1 ? 'template' : 'templates' }}</button>
                @if(! $target)<span class="text-xs" style="color: var(--text-muted);">Choose an agency first.</span>@endif
                @if($anyBlocking)<span class="text-xs" style="color: var(--text-muted);">Something above blocks this import.</span>@endif
            </div>
        </form>
        <form method="POST" action="{{ route('docuperfect.template-transfer.cancel', $token) }}" class="mt-3">
            @csrf
            <button type="submit" class="text-xs underline" style="color: var(--text-muted);">Cancel — import nothing</button>
        </form>
    </div>
</div>
@endsection
