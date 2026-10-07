{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — read / manage one version of the Subscription Agreement wording (spec §11.14). --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $isDraft = !$v->is_published;
    $open = $isDraft && !$v->trashed();
    $title = $isDraft ? ($v->trashed() ? 'Discarded draft' : 'Draft') : 'Version ' . $v->version . ' — ' . $v->version_date->format('j F Y');
    $actions = '<a href="' . route('platform-esign.wording.index') . '" class="corex-btn-outline">← Versions</a>'
        . '<a href="' . route('platform-esign.wording.preview', $v->id) . '" class="corex-btn-outline">Preview</a>'
        . '<a href="' . route('platform-esign.wording.preview.pdf', $v->id) . '" target="_blank" class="corex-btn-outline">Sample PDF</a>';
    if ($previous && $previous->id !== $v->id) {
        $actions .= '<a href="' . route('platform-esign.wording.compare', ['a' => $previous->id, 'b' => $v->id]) . '" class="corex-btn-outline">What changed since ' . ($previous->is_published ? 'v' . $previous->version : 'the current version') . '</a>';
    }
    $parts = \App\Services\PlatformEsign\Agreement\AgreementContent::PARTS + ['rates' => 'Rates'];
@endphp
<div class="w-full space-y-5">
    @include('platform-esign._header', [
        'title' => 'Agreement wording — ' . $title, 'tab' => 'wording', 'actions' => $actions,
        'sub' => $isDraft ? ($v->trashed() ? 'This draft was discarded. Restore it from the versions list to carry on.' : 'A working copy of version ' . ($v->parent?->version ?? '—') . '. Nothing here reaches an agency until you publish it.')
                          : ($v->change_note ?: 'Published ' . $v->published_at?->format('j F Y') . '.'),
    ])
    @include('platform-esign.wording._css')
    @if($errors->has('draft'))<div class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-crimson-bg, #fef2f2); color: var(--ds-crimson);">{{ $errors->first('draft') }}</div>@endif

    <div class="grid xl:grid-cols-3 gap-5">
        <div class="xl:col-span-2 space-y-4 min-w-0">
            <div class="part-tabs">
                @foreach($parts as $k => $label)<a href="{{ route('platform-esign.wording.show', [$v->id, 'part' => $k]) }}" class="{{ $part === $k ? 'on' : '' }}">{{ $label }}</a>@endforeach
            </div>

            @if($part === 'rates')
                @if($errors->has('rates'))<div class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-crimson-bg, #fef2f2); color: var(--ds-crimson);">@foreach($errors->get('rates') as $m)@foreach((array) $m as $mm)<div>{{ $mm }}</div>@endforeach @endforeach</div>@endif
                <form method="POST" action="{{ $open ? route('platform-esign.wording.rates.save', $v->id) : '#' }}" class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
                    @csrf @method('PUT')
                    <input type="hidden" name="rev" value="{{ $v->rev }}">
                    <div class="ds-section-header mb-3">Pricing in this version</div>
                    <div class="grid sm:grid-cols-2 gap-4">
                        @foreach($rateFields as $key => [$label, $kind])
                            <div>
                                <label class="ds-label block mb-1" for="rate-{{ $key }}">{{ $label }}</label>
                                <input id="rate-{{ $key }}" name="rates[{{ $key }}]" value="{{ old('rates.' . $key, \App\Services\PlatformEsign\Agreement\AgreementPricing::number((float) $rates[$key])) }}" inputmode="decimal" class="ds-field w-40" @disabled(!$open)>
                            </div>
                        @endforeach
                    </div>
                    <p class="text-xs mt-3" style="color: var(--text-muted);">These numbers drive the fee table, the live total and the debit order amount, and the rates quoted in the wording. A team of 25 agents on the Agency plan is the base fee plus seats at each tier.</p>
                    @if($open)<div class="mt-3"><button class="corex-btn-primary">Save rates</button></div>@endif
                </form>
            @else
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="text-sm" style="color: var(--text-muted);">{{ count($clauses) }} {{ \Illuminate\Support\Str::plural('clause', count($clauses)) }} · field markers appear as grey boxes.</div>
                    @if($open)<a href="{{ route('platform-esign.wording.edit', [$v->id, $part]) }}" class="corex-btn-primary">Edit {{ $parts[$part] }}</a>@endif
                </div>
                <div class="doc-col agr">{!! implode("\n", $clauses) !!}</div>
            @endif
        </div>

        <div class="space-y-5">
            <div class="rounded-md p-5 space-y-2 text-sm" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="ds-section-header mb-1">About this version</div>
                <div class="flex justify-between gap-3"><span style="color: var(--text-muted);">Status</span><span>@if($isDraft)<span class="ds-badge ds-badge-{{ $v->trashed() ? 'default' : 'orange' }}">{{ $v->trashed() ? 'Discarded draft' : 'Draft' }}</span>@else<span class="ds-badge ds-badge-{{ $v->id === $current->id ? 'success' : 'default' }}">{{ $v->id === $current->id ? 'Current' : 'Superseded' }}</span>@endif</span></div>
                @if(!$isDraft)<div class="flex justify-between gap-3"><span style="color: var(--text-muted);">Published</span><span>{{ $v->published_at?->format('j M Y H:i') }} · {{ $v->publisher?->name ?? '—' }}</span></div>@endif
                <div class="flex justify-between gap-3"><span style="color: var(--text-muted);">{{ $isDraft ? 'Started by' : 'Created by' }}</span><span>{{ $v->creator?->name ?? 'system' }}</span></div>
                @if($v->parent)<div class="flex justify-between gap-3"><span style="color: var(--text-muted);">Copied from</span><a class="underline" style="color: var(--brand-icon);" href="{{ route('platform-esign.wording.show', $v->parent->id) }}">Version {{ $v->parent->version }}</a></div>@endif
                @if(!$isDraft)<div class="flex justify-between gap-3"><span style="color: var(--text-muted);">Agreements sent with it</span><span>{{ $sentCount }}</span></div>
                    <div class="flex justify-between gap-3"><span style="color: var(--text-muted);">Public page</span><a class="underline" style="color: var(--brand-icon);" target="_blank" rel="noopener" href="{{ $v->id === $current->id ? route('public.agreement-terms') : route('public.agreement-terms.version', $v->version) }}">Parts B, C, D ↗</a></div>@endif
                @if(!$isDraft)
                    <div class="pt-2 text-xs" style="color: var(--text-muted);">Published versions are fixed. To change the wording, start a new version from this one — that is also how an earlier wording is brought back.</div>
                    @if(!$hasDraft)<form method="POST" action="{{ route('platform-esign.wording.draft.create') }}" class="pt-1">@csrf<input type="hidden" name="from" value="{{ $v->id }}"><button class="corex-btn-outline w-full">Start a new version from this one</button></form>
                    @else<div class="text-xs pt-1" style="color: var(--text-muted);">A draft is already in progress — finish or discard it first.</div>@endif
                @endif
            </div>

            @if($open)
            <div class="rounded-md p-5 space-y-3 text-sm" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="ds-section-header">Publish this draft</div>
                @if($errors->has('publish'))<div class="rounded-md px-3 py-2 text-xs" style="background: var(--ds-crimson-bg, #fef2f2); color: var(--ds-crimson);">@foreach($errors->get('publish') as $m)@foreach((array) $m as $mm)<div>{{ $mm }}</div>@endforeach @endforeach</div>@endif
                @if($problems)
                    <div class="rounded-md px-3 py-2 text-xs" style="background: var(--ds-crimson-bg, #fef2f2); color: var(--ds-crimson);"><strong>Fix before publishing:</strong>@foreach($problems as $p)<div>{{ $p }}</div>@endforeach</div>
                @endif
                <form method="POST" action="{{ route('platform-esign.wording.publish', $v->id) }}" class="space-y-3" onsubmit="return confirm('Publish this version? It becomes the version every NEW agreement uses, and it can never be changed or deleted afterwards.');">
                    @csrf
                    {{-- The revision of the draft this page showed: publishing is refused if the draft was saved elsewhere since. --}}
                    <input type="hidden" name="rev" value="{{ $v->rev }}">
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="ds-label block mb-1" for="pub-version">Version number</label><input id="pub-version" name="version" value="{{ old('version', $suggested) }}" class="ds-field w-full" maxlength="12" required></div>
                        <div><label class="ds-label block mb-1" for="pub-date">Version date</label><input id="pub-date" type="date" name="version_date" value="{{ old('version_date', now()->toDateString()) }}" class="ds-field w-full" required></div>
                    </div>
                    <div><label class="ds-label block mb-1" for="pub-note">What changed (shown with the version)</label><textarea id="pub-note" name="change_note" rows="3" maxlength="500" class="ds-field w-full" required>{{ old('change_note') }}</textarea></div>
                    <div class="flex flex-wrap gap-2">
                        <button class="corex-btn-primary" @disabled($problems)>Publish version</button>
                        <a href="{{ route('platform-esign.wording.preview', $v->id) }}" class="corex-btn-outline">Preview first</a>
                    </div>
                    <p class="text-xs" style="color: var(--text-muted);">Publishing checks the wording, lays out the pages, and then makes it immutable. Agreements already sent are not affected.</p>
                </form>
                <form method="POST" action="{{ route('platform-esign.wording.discard', $v->id) }}" onsubmit="return confirm('Discard this draft? It is kept and can be restored from the versions list.');">@csrf<button class="corex-btn-outline w-full" style="color: var(--ds-crimson);">Discard this draft</button></form>
            </div>
            @elseif($v->trashed())
                <form method="POST" action="{{ route('platform-esign.wording.restore', $v->id) }}">@csrf<button class="corex-btn-primary w-full">Restore this draft</button></form>
            @endif
        </div>
    </div>
    @include('platform-esign._end')
</div>
@endsection
