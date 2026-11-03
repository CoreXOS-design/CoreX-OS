{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Road to Sold 23.25 steps 8 (Draft) and 9 (Active) of the pitch popup: the property's own gates, read as the belt reads them, with the EXISTING actions beside them
     (the property page's Move to Draft, Go live = the compliance snapshot, Publish). Nothing is decided here: the record moves when the property does (BeltLinks).
     Off-ramp ("Stops here - say why") belongs to the popup footer, not this screen. --}}
@extends('layouts.corex-app')
@section('title', $step === 'draft' ? 'Draft' : 'Active')

@section('corex-content')
@php
    $failing = collect($report->checklist)->filter(fn ($row) => ! ($row['passed'] ?? true));
    $tick = fn (bool $ok) => $ok ? '✓' : '✗';
    $card = 'background: var(--surface); border: 1px solid var(--border);';
    $propertyUrl = route('corex.properties.show', $property->id) . '?' . http_build_query($carry);
@endphp
<div class="mx-auto max-w-xl py-8 px-4 space-y-4" data-qa="pitch-property-step" data-step="{{ $step }}">
    <div>
        <h1 class="text-lg font-bold" style="color: var(--text-primary);" data-qa="step-heading">{{ $step === 'draft' ? 'Draft' : 'Active' }}</h1>
        <p class="text-sm mt-1" style="color: var(--text-muted);">{{ $address }}</p>
    </div>
    @foreach(['success', 'status'] as $k)@if(session($k))<div class="rounded-md px-4 py-3 text-sm" data-qa="step-saved" style="background: color-mix(in srgb, var(--ds-green, #10b981) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green, #10b981) 35%, transparent); color: var(--text-primary);" role="status">{{ session($k) }}</div>@endif @endforeach
    @if(session('error'))<div class="rounded-md px-4 py-3 text-sm" data-qa="step-problem" style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 35%, transparent); color: var(--text-primary);" role="alert">{{ session('error') }}</div>@endif
    <div id="pps-msg" class="hidden rounded-md px-4 py-3 text-sm" data-qa="step-problem" style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 35%, transparent); color: var(--text-primary);" role="alert"></div>

    @if($step === 'draft')
        <div class="rounded-md p-4 space-y-2" style="{{ $card }}" data-qa="draft-state">
            <div class="text-sm font-semibold" style="color: var(--text-primary);">
                {{ $isDraft ? 'The property is a Draft.' : ($isProspecting ? 'The property is still in Prospecting.' : 'The property is already past Draft.') }}
            </div>
            @if($isProspecting)
                <p class="text-sm" style="color: var(--text-muted);">When the mandate is won, move it to Draft. A "Won" result does this by itself; use the button when the mandate was agreed another way.</p>
                <form method="POST" action="{{ route('corex.properties.convert-from-prospecting', $property->id) }}">@csrf
                    <button type="submit" class="corex-btn-primary" data-qa="move-to-draft">Move to Draft</button></form>
            @endif
        </div>
        <div class="rounded-md p-4" style="{{ $card }}" data-qa="draft-checklist">
            <div class="text-xs font-bold uppercase tracking-widest mb-2" style="color: var(--text-muted);">Draft checklist - mandate, compliance, photos</div>
            @forelse($report->checklist as $row)
                <div class="flex items-start gap-2 text-sm py-1" style="color: var(--text-primary);" data-qa="check-row" data-passed="{{ ($row['passed'] ?? true) ? 1 : 0 }}">
                    <span style="color: {{ ($row['passed'] ?? true) ? 'var(--ds-green, #10b981)' : 'var(--ds-crimson, #dc2626)' }};">{{ $tick((bool) ($row['passed'] ?? true)) }}</span>
                    <span><strong>{{ $row['label'] ?? '' }}</strong>@if(!empty($row['detail'])) <span style="color: var(--text-muted);">- {{ $row['detail'] }}</span>@endif</span>
                </div>
            @empty
                <div class="text-sm" style="color: var(--text-muted);">Nothing to check yet.</div>
            @endforelse
            @if($failing->isNotEmpty())<p class="text-xs mt-2" style="color: var(--text-muted);">Clear these on the property page; each one that passes is ticked here.</p>@endif
        </div>
    @else
        <div class="rounded-md p-4 space-y-2" style="{{ $card }}" data-qa="active-gates">
            <div class="text-xs font-bold uppercase tracking-widest" style="color: var(--text-muted);">The two gates to Active</div>
            <div class="flex items-start gap-2 text-sm" data-qa="gate-compliance" data-passed="{{ $complianceDone ? 1 : 0 }}" style="color: var(--text-primary);">
                <span style="color: {{ $complianceDone ? 'var(--ds-green, #10b981)' : 'var(--ds-crimson, #dc2626)' }};">{{ $tick($complianceDone) }}</span>
                <span><strong>Compliance</strong> - {{ $complianceDone ? 'passed' . ($property->compliance_snapshot_at ? ' on ' . $property->compliance_snapshot_at->format('j M Y') : '') : ($report->ready ? 'ready to record' : 'not passed yet') }}</span>
            </div>
            <div class="flex items-start gap-2 text-sm" data-qa="gate-published" data-passed="{{ $published ? 1 : 0 }}" style="color: var(--text-primary);">
                <span style="color: {{ $published ? 'var(--ds-green, #10b981)' : 'var(--ds-crimson, #dc2626)' }};">{{ $tick($published) }}</span>
                <span><strong>Published</strong> - {{ $published ? 'on ' . $property->published_at->format('j M Y') : 'not published yet' }}</span>
            </div>
            @foreach($syndication as $s)
                <div class="flex items-start gap-2 text-sm" data-qa="gate-portal" style="color: var(--text-muted);">
                    <span>•</span><span>{{ $s['label'] }}: {{ $s['status'] !== '' ? $s['status'] : 'not sent' }}</span>
                </div>
            @endforeach
            @if($failing->isNotEmpty() && ! $complianceDone)
                <div class="text-xs pt-1" style="color: var(--text-muted);" data-qa="active-blockers">Still missing: {{ $failing->pluck('label')->filter()->implode(', ') }}.</div>
            @endif
        </div>
        <div class="rounded-md p-4 space-y-2" style="{{ $card }}" data-qa="active-actions">
            @if($complianceDone && $published)
                <div class="text-sm font-semibold" style="color: var(--text-primary);" data-qa="active-done">Both gates have passed - the property is Active.</div>
            @elseif(! $complianceDone)
                <p class="text-sm" style="color: var(--text-muted);">Record that the property passed compliance. It moves on once the listing is published too.</p>
                <button type="button" class="corex-btn-primary" id="pps-golive" data-qa="make-active" @disabled(! $report->ready)>Make active - record compliance</button>
            @else
                <p class="text-sm" style="color: var(--text-muted);">Compliance has passed. Publish the listing to make it Active.</p>
                <form method="POST" action="{{ route('corex.properties.publish-toggle', $property->id) }}">@csrf
                    <input type="hidden" name="action" value="publish"><button type="submit" class="corex-btn-primary" data-qa="make-active">Make active - publish</button></form>
            @endif
        </div>
    @endif
    <p class="text-xs" style="color: var(--text-muted);"><a href="{{ $propertyUrl }}" style="color: var(--brand-icon);">Open the whole property page</a></p>
</div>
@if($step === 'active' && ! $complianceDone)
<script>
document.getElementById('pps-golive')?.addEventListener('click', function () {
    var b = this, msg = document.getElementById('pps-msg'); b.disabled = true; msg.classList.add('hidden');
    fetch(@js(route('corex.properties.go-live', $property->id)), { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok && d.ok, d: d }; }); })
        .then(function (x) { if (x.ok) { window.location.reload(); } else { b.disabled = false; msg.textContent = x.d.message || 'Check the compliance gates.'; msg.classList.remove('hidden'); } })
        .catch(function () { b.disabled = false; msg.textContent = 'Network error - try again.'; msg.classList.remove('hidden'); });
});
</script>
@endif
@endsection
