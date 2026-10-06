{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — Subscription Agreement wording: versions, settings, audit (spec §11.14). --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $th = 'text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider';
    $f = $filters;
    $sortLink = function ($key, $label) use ($f) {
        $next = ($f['sort'] === $key && $f['dir'] === 'desc') ? 'asc' : 'desc';
        $arrow = $f['sort'] === $key ? ($f['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
        return '<a href="' . e(request()->fullUrlWithQuery(['sort' => $key, 'dir' => $next, 'page' => null])) . '">' . e($label) . $arrow . '</a>';
    };
    $actions = $draft
        ? '<a href="' . route('platform-esign.wording.show', $draft->id) . '" class="corex-btn-primary">Continue the draft</a>'
        : '<form method="POST" action="' . route('platform-esign.wording.draft.create') . '" class="inline">' . csrf_field() . '<button class="corex-btn-primary">New version</button></form>';
    $actions .= '<a href="' . route('platform-esign.wording.compare') . '" class="corex-btn-outline">What changed</a><a href="' . route('public.agreement-terms') . '" target="_blank" rel="noopener" class="corex-btn-outline">Public terms page ↗</a>';
@endphp
<div class="w-full space-y-5">
    @include('platform-esign._header', [
        'title' => 'Agreement wording', 'tab' => 'wording', 'actions' => $actions,
        'sub' => 'The CoreX Subscription Agreement text and pricing, in versions. A published version never changes — every agreement stays on the version it was sent with.',
    ])
    @if($errors->has('draft'))<div class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-crimson-bg, #fef2f2); color: var(--ds-crimson);">{{ $errors->first('draft') }}</div>@endif

    <div class="grid xl:grid-cols-3 gap-5">
        <div class="xl:col-span-2 space-y-5 min-w-0">
            <form method="GET" class="rounded-md p-3 flex flex-wrap gap-2 items-center" style="background: var(--surface); border: 1px solid var(--border);">
                <input type="text" name="q" value="{{ $f['q'] }}" placeholder="Search version or change note…" class="ds-field flex-1" style="min-width: 12rem;">
                <select name="status" class="list-header-filter" onchange="this.form.submit()">
                    @foreach(['all' => 'All versions', 'published' => 'Published', 'draft' => 'Draft in progress', 'discarded' => 'Discarded drafts'] as $k => $l)<option value="{{ $k }}" @selected($f['status'] === $k)>{{ $l }}</option>@endforeach
                </select>
                <label class="flex items-center gap-1 text-xs" style="color: var(--text-muted);">Dated from <input type="date" name="from" value="{{ $f['from'] }}" class="ds-field"></label>
                <label class="flex items-center gap-1 text-xs" style="color: var(--text-muted);">to <input type="date" name="to" value="{{ $f['to'] }}" class="ds-field"></label>
                <button class="corex-btn-primary">Search</button>
                @if($f['q'] !== '' || $f['status'] !== 'all' || $f['from'] || $f['to'])<a href="{{ route('platform-esign.wording.index') }}" class="corex-btn-outline">Clear</a>@endif
            </form>

            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead><tr style="background: var(--surface-2);">
                            <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('version', 'Version') !!}</th>
                            <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('date', 'Dated') !!}</th>
                            <th class="{{ $th }}" style="color: var(--text-muted);">What changed</th>
                            <th class="{{ $th }}" style="color: var(--text-muted);">{!! $sortLink('published', 'Published') !!}</th>
                            <th class="px-4 py-2.5"></th>
                        </tr></thead>
                        <tbody>
                        @forelse($rows as $r)
                            <tr style="border-top: 1px solid var(--border);">
                                <td class="px-4 py-3">
                                    <a href="{{ route('platform-esign.wording.show', $r->id) }}" class="font-semibold" style="color: var(--text-primary);">{{ $r->is_published ? 'Version ' . $r->version : 'Draft' }}</a>
                                    @if($r->is_published && $r->id === $current->id)<span class="ds-badge ds-badge-success ml-1">Current</span>@elseif($r->is_published)<span class="ds-badge ds-badge-default ml-1">Superseded</span>@elseif($r->trashed())<span class="ds-badge ds-badge-default ml-1">Discarded</span>@else<span class="ds-badge ds-badge-orange ml-1">In progress</span>@endif
                                    @if($r->is_published)<div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $r->documents_count }} {{ \Illuminate\Support\Str::plural('agreement', $r->documents_count) }} sent with it</div>@endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap" style="color: var(--text-muted);">{{ $r->is_published ? $r->version_date->format('j M Y') : '—' }}</td>
                                <td class="px-4 py-3" style="color: var(--text-secondary); min-width: 16rem;">{{ $r->change_note ?: ($r->is_published ? '—' : 'Not published yet') }}</td>
                                <td class="px-4 py-3 text-xs whitespace-nowrap" style="color: var(--text-muted);">@if($r->is_published){{ $r->published_at?->format('j M Y H:i') }}<br>{{ $r->publisher?->name ?? '—' }}@else{{ $r->creator?->name ?? '—' }}<br>started {{ $r->created_at?->format('j M Y') }}@endif</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <a href="{{ route('platform-esign.wording.show', $r->id) }}" class="corex-btn-outline corex-btn-xs">{{ $r->is_published || $r->trashed() ? 'View' : 'Edit' }}</a>
                                    @if($r->trashed())
                                        <form method="POST" action="{{ route('platform-esign.wording.restore', $r->id) }}" class="inline">@csrf<button class="corex-btn-outline corex-btn-xs">Restore</button></form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-12 text-center text-sm" style="color: var(--text-muted);">No versions match.@if($f['q'] !== '' || $f['status'] !== 'all' || $f['from'] || $f['to']) <a href="{{ route('platform-esign.wording.index') }}" class="underline" style="color: var(--brand-icon);">Clear the filters</a>.@endif</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if($rows->hasPages())<div class="px-5 py-3" style="border-top: 1px solid var(--border);">{{ $rows->links() }}</div>@endif
            </div>

            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);"><div class="ds-section-header">Who changed what</div></div>
                @forelse($audit as $a)
                    <div class="px-5 py-2.5 text-xs flex gap-3" style="border-top: 1px solid var(--border);">
                        <span class="whitespace-nowrap tabular-nums" style="color: var(--text-muted);">{{ $a->created_at?->format('j M Y H:i') }}</span>
                        <span style="color: var(--text-primary);"><strong>{{ ucfirst(str_replace('_', ' ', $a->action)) }}</strong>@if($a->version) · {{ $a->version->is_published ? 'v' . $a->version->version : 'draft' }}@endif @if($a->detail) — {{ $a->detail }}@endif <span style="color: var(--text-muted);">· {{ $a->user?->name ?? 'system' }}</span></span>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-sm" style="color: var(--text-muted);">Nothing has been changed yet.</div>
                @endforelse
                @if($audit->hasPages())<div class="px-5 py-3" style="border-top: 1px solid var(--border);">{{ $audit->links() }}</div>@endif
            </div>
        </div>

        <div class="space-y-5 min-w-0">
            <div class="rounded-md p-5 space-y-2 text-sm" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="ds-section-header mb-1">Current version</div>
                <div class="text-base font-semibold" style="color: var(--text-primary);">{{ $current->label() }}</div>
                <div style="color: var(--text-muted);">{{ $current->change_note }}</div>
                <div class="text-xs" style="color: var(--text-muted);">New agreements are sent with this version. {{ $sentTotal }} {{ \Illuminate\Support\Str::plural('agreement', $sentTotal) }} sent so far.</div>
                <div class="flex flex-wrap gap-2 pt-1">
                    <a href="{{ route('platform-esign.wording.show', $current->id) }}" class="corex-btn-outline corex-btn-xs">Read the wording</a>
                    <a href="{{ route('platform-esign.wording.preview', $current->id) }}" class="corex-btn-outline corex-btn-xs">Preview</a>
                    <a href="{{ route('public.agreement-terms') }}" target="_blank" rel="noopener" class="corex-btn-outline corex-btn-xs">Public terms ↗</a>
                </div>
            </div>

            <form method="POST" action="{{ route('platform-esign.wording.settings') }}" class="rounded-md p-5 space-y-3 text-sm" style="background: var(--surface); border: 1px solid var(--border);">
                @csrf
                <div class="ds-section-header">Link expiry and reminders</div>
                @if($errors->has('settings'))<div class="rounded-md px-3 py-2 text-xs" style="background: var(--ds-crimson-bg, #fef2f2); color: var(--ds-crimson);">@foreach($errors->get('settings') as $m)@foreach((array) $m as $mm)<div>{{ $mm }}</div>@endforeach @endforeach</div>@endif
                @foreach(\App\Services\PlatformEsign\Agreement\AgreementSettings::FIELDS as $key => [$k, $def, $min, $max, $label, $help])
                    <div>
                        <label class="ds-label block mb-1" for="set-{{ $key }}">{{ $label }}</label>
                        <input id="set-{{ $key }}" name="{{ $key }}" type="number" min="{{ $min }}" max="{{ $max }}" value="{{ old($key, $settings[$key]) }}" class="ds-field w-28">
                        <span class="text-xs ml-1" style="color: var(--text-muted);">default {{ $def }}</span>
                        <p class="text-xs mt-1" style="color: var(--text-muted);">{{ $help }}</p>
                    </div>
                @endforeach
                <button class="corex-btn-primary">Save settings</button>
                <p class="text-xs" style="color: var(--text-muted);">Applies to links and reminders from now on. Reminders are sent by the hourly scheduled job.</p>
            </form>
        </div>
    </div>
</div>
@endsection
