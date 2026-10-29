{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 — spec: .ai/specs/esign-template-transfer.md §13.4 (Approvals tab, the agency's authorisers) --}}
@extends('layouts.corex')

@section('corex-content')
@php
    use App\Models\Docuperfect\TemplateRelease;
    $sortUrl = fn (string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc', 'page' => null]);
    $arrow = fn (string $col) => $sort === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    $stateColour = ['awaiting_approval' => 'var(--ds-amber, #d97706)', 'live' => 'var(--ds-green)', 'declined' => 'var(--ds-crimson)', 'withdrawn' => 'var(--text-muted)', 'superseded' => 'var(--text-muted)'];
@endphp
<style>
    .ws-fit { height: calc(100vh - 54px - 48px); display: flex; flex-direction: column; overflow: hidden; }
    .ws-pane { flex: 1 1 auto; min-height: 0; overflow: auto; }
    .ws-th a { color: inherit; text-decoration: none; }
</style>
<div class="w-full ws-fit gap-3" data-fixed-shell data-approvals-page>

    <div class="rounded-md px-6 py-4 corex-page-banner flex-shrink-0">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $ownerView ? 'Approvals' : 'Documents from CoreX' }}</h1>
        <p class="text-xs" style="color: var(--text-muted);">Documents CoreX has prepared for your agency. A document only goes live — and can only be used by your agents — once someone you have chosen to authorise documents approves it.</p>
        @unless($ownerView)
            <ol class="text-xs mt-1 list-decimal pl-4" style="color: var(--text-secondary);" data-agency-workshop-how>
                <li>CoreX builds the document and checks it is complete.</li>
                <li>You look at it with sample data (Demo view), exactly as your clients will see it.</li>
                <li>Someone you have chosen approves it, and it is live for your agents.</li>
            </ol>
        @endunless
        <div class="flex flex-wrap items-center gap-2 mt-2" data-state-chips>
            @foreach($states as $st)
                <a href="{{ route('docuperfect.template-approvals.index', ['state' => $st]) }}" class="text-xs px-2 py-1 rounded-md" style="border: 1px solid var(--border); color: {{ $stateColour[$st] ?? 'var(--text-muted)' }};" data-state-chip="{{ $st }}">{{ TemplateRelease::stateLabel($st) }}: {{ $stateCounts[$st] ?? 0 }}</a>
            @endforeach
            @if($canSetAuthorisers)<a href="{{ route('docuperfect.esign.settings.document-authorisers') }}" class="text-xs underline ml-2" style="color: var(--text-muted);" data-link-authoriser-setting>Who may authorise documents</a>@endif
        </div>
    </div>

    @include('corex.partials._hub-tabs', ['hub' => \App\Support\Navigation\Hubs\DocumentTemplatesTabs::class])

    @if(session('status'))
        <div class="rounded-md px-4 py-2 text-sm flex-shrink-0" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-2 text-sm flex-shrink-0" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ $errors->first() }}</div>
    @endif
    @if($authoriserNotSet)
        <div class="rounded-md px-4 py-2 text-xs flex-shrink-0" style="background: color-mix(in srgb, var(--ds-amber, #d97706) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #d97706) 30%, transparent); color: var(--text-primary);" data-authoriser-notice>
            No one is chosen yet, so for now your administrators can approve. Choose who may authorise documents under E-Sign Settings.
        </div>
    @endif
    @if($ownerView)
        <div class="rounded-md px-4 py-2 text-xs flex-shrink-0" style="border: 1px solid var(--border); color: var(--text-muted);" data-owner-readonly>You are looking at every agency's approvals. Approving is the agency's own decision, so you can look but not approve or decline.</div>
    @endif

    <form method="GET" action="{{ route('docuperfect.template-approvals.index') }}" class="flex flex-wrap items-center gap-2 flex-shrink-0" data-approval-filters>
        <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Search document name or decision note" class="list-header-filter" style="min-width: 16rem;" aria-label="Search approvals">
        <select name="state" class="list-header-filter" aria-label="State">
            <option value="all" @selected($filters['state'] === null)>Any state</option>
            @foreach($states as $st)<option value="{{ $st }}" @selected($filters['state'] === $st)>{{ TemplateRelease::stateLabel($st) }}</option>@endforeach
        </select>
        <label class="text-xs" style="color: var(--text-muted);">Released from <input type="date" name="from" value="{{ $filters['from'] }}" class="list-header-filter"></label>
        <label class="text-xs" style="color: var(--text-muted);">to <input type="date" name="to" value="{{ $filters['to'] }}" class="list-header-filter"></label>
        <button type="submit" class="corex-btn-primary text-xs px-4 py-2">Filter</button>
        @if($filtered)<a href="{{ route('docuperfect.template-approvals.index') }}" class="text-xs underline" style="color: var(--text-muted);">Back to waiting</a>@endif
    </form>

    <div class="ws-pane rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        @if($releases->isEmpty())
            <p class="p-5 text-sm" style="color: var(--text-muted);" data-empty>{{ $filtered ? 'No documents match these filters.' : 'Nothing is waiting for approval.' }}</p>
        @else
            <table class="w-full text-sm" data-approvals-table>
                <thead style="background: var(--surface-2, rgba(0,0,0,0.03)); position: sticky; top: 0;"><tr class="text-left text-xs ws-th" style="color: var(--text-muted);">
                    <th class="px-4 py-2"><a href="{{ $sortUrl('template') }}">Document{!! $arrow('template') !!}</a></th>
                    <th class="px-4 py-2">Version</th>
                    <th class="px-4 py-2"><a href="{{ $sortUrl('state') }}">State{!! $arrow('state') !!}</a></th>
                    <th class="px-4 py-2"><a href="{{ $sortUrl('released_at') }}">Released{!! $arrow('released_at') !!}</a></th>
                    <th class="px-4 py-2">Decided</th><th class="px-4 py-2"></th>
                </tr></thead>
                <tbody>
                @foreach($releases as $r)
                    <tr style="border-top: 1px solid var(--border);" data-approval-row="{{ $r->id }}" data-state="{{ $r->state }}">
                        <td class="px-4 py-2 font-semibold" style="color: var(--text-primary);">{{ $r->template?->name ?? 'A document' }}@if($ownerView)<div class="text-xs font-normal" style="color: var(--text-muted);">{{ $r->agency?->name }}</div>@endif</td>
                        <td class="px-4 py-2" style="color: var(--text-secondary);">v{{ $r->version }}</td>
                        <td class="px-4 py-2"><span class="text-xs font-semibold" style="color: {{ $stateColour[$r->state] ?? 'var(--text-muted)' }};">{{ TemplateRelease::stateLabel($r->state) }}</span></td>
                        <td class="px-4 py-2 text-xs whitespace-nowrap" style="color: var(--text-secondary);">{{ $r->released_at?->format('j M Y H:i') }}</td>
                        <td class="px-4 py-2 text-xs whitespace-nowrap" style="color: var(--text-secondary);">@if($r->decided_at){{ $r->decided_at->format('j M Y H:i') }}<br><span style="color: var(--text-muted);">{{ $r->decidedByUser?->name }}</span>@else — @endif</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            @if($r->template_id && in_array($r->state, ['awaiting_approval', 'live'], true))<a href="{{ route('docuperfect.templates.demo', $r->template_id) }}" class="corex-btn-outline text-xs px-3 py-1" data-demo-link>Demo view</a>@endif
                            <a href="{{ route('docuperfect.template-approvals.show', $r->id) }}" class="{{ ($canDecide && $r->state === 'awaiting_approval') ? 'corex-btn-primary' : 'corex-btn-outline' }} text-xs px-3 py-1">{{ ($canDecide && $r->state === 'awaiting_approval') ? 'Review and approve' : 'View' }}</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="flex-shrink-0">{{ $releases->links() }}</div>
</div>
@include('docuperfect.partials._fixed-shell')
@endsection
