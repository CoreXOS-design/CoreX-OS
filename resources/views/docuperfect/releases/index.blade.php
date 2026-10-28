{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 — spec: .ai/specs/esign-template-transfer.md §13.4 (Releases tab, owner only) --}}
@extends('layouts.corex')

@section('corex-content')
@php
    use App\Models\Docuperfect\TemplateRelease;
    use App\Models\Docuperfect\TemplateReleaseEvent;
    $sortUrl = fn (string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc', 'page' => null]);
    $arrow = fn (string $col) => $sort === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    $tabUrl = fn (string $v) => route('docuperfect.template-releases.index', ['view' => $v]);
    $stateColour = ['awaiting_approval' => 'var(--ds-amber, #d97706)', 'live' => 'var(--ds-green)', 'declined' => 'var(--ds-crimson)', 'withdrawn' => 'var(--text-muted)', 'superseded' => 'var(--text-muted)'];
@endphp
<style>
    /* Fixed-shell page: the menu and header stay put and the page itself does not scroll; the list pane scrolls inside itself (page standard). */
    .ws-fit { height: calc(100vh - 54px - 48px); display: flex; flex-direction: column; overflow: hidden; }
    .ws-pane { flex: 1 1 auto; min-height: 0; overflow: auto; }
    .ws-th a { color: inherit; text-decoration: none; }
</style>
<div class="w-full ws-fit gap-3" data-fixed-shell data-releases-page data-view="{{ $view }}">

    <div class="rounded-md px-6 py-4 corex-page-banner flex-shrink-0">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Releases</h1>
        <p class="text-xs" style="color: var(--text-muted);">Check a finished document, release it to an agency, and follow it until the agency approves it. A released document is not live at the agency until its authorisers approve it.</p>
    </div>

    @include('corex.partials._hub-tabs', ['hub' => \App\Support\Navigation\Hubs\DocumentTemplatesTabs::class])

    @if(session('status'))
        <div class="rounded-md px-4 py-2 text-sm flex-shrink-0" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-2 text-sm flex-shrink-0" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <div class="font-semibold">{{ $errors->first() }}</div>
            @if(session('error_details'))<ul class="list-disc pl-5 mt-1 text-xs">@foreach(session('error_details') as $line)<li>{{ $line }}</li>@endforeach</ul>@endif
        </div>
    @endif

    <div class="flex items-center gap-1 flex-shrink-0" data-release-views>
        @foreach(['releases' => 'Releases', 'workshop' => 'Workshop', 'log' => 'Release log'] as $v => $label)
            <a href="{{ $tabUrl($v) }}" data-release-view="{{ $v }}" class="text-xs px-3 py-1.5 rounded-md" style="{{ $view === $v ? 'background: var(--brand-icon, #0ea5e9); color: #fff; font-weight: 600;' : 'border: 1px solid var(--border); color: var(--text-secondary);' }}">{{ $label }}</a>
        @endforeach
    </div>

    {{-- ───────────── Workshop ───────────── --}}
    @if($view === 'workshop')
        <form method="GET" action="{{ route('docuperfect.template-releases.index') }}" class="flex flex-wrap items-center gap-2 flex-shrink-0">
            <input type="hidden" name="view" value="workshop">
            <input type="text" name="wq" value="{{ $wq }}" placeholder="Find a template by name" class="list-header-filter" style="min-width: 18rem;" aria-label="Find a template by name">
            <button type="submit" class="corex-btn-primary text-xs px-4 py-2">Find</button>
        </form>
        <div class="ws-pane rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
            @if($wq === '')
                <p class="p-5 text-sm" style="color: var(--text-muted);">Type part of a template's name to find the document you have been preparing. You can then check it, verify it and release it to an agency.</p>
            @elseif($rows->isEmpty())
                <p class="p-5 text-sm" style="color: var(--text-muted);" data-empty>No templates match "{{ $wq }}". Archived templates and copies waiting for approval are not listed here.</p>
            @else
                <div class="divide-y" style="border-color: var(--border);">
                    @foreach($rows as $r)
                        @php $t = $r['template']; $vc = ['not_ready' => 'var(--ds-crimson)', 'ready_with_warnings' => 'var(--ds-amber, #d97706)', 'ready' => 'var(--ds-green)'][$r['verdict']]; @endphp
                        <div class="p-4 space-y-2" data-workshop-row="{{ $t->id }}" data-verified-state="{{ $r['state'] }}">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $t->name }}</div>
                                    <div class="text-xs" style="color: var(--text-muted);">{{ $r['agency_name'] }} · readiness: <span style="color: {{ $vc }}; font-weight: 600;">{{ ['not_ready' => 'fix first', 'ready_with_warnings' => 'ready, with warnings', 'ready' => 'ready'][$r['verdict']] }}</span> ({{ $r['counts']['fail'] }} to fix, {{ $r['counts']['warn'] }} to look at)</div>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <a href="{{ route('docuperfect.templates.readiness', $t->id) }}" class="corex-btn-outline text-xs px-3 py-1.5">Check readiness</a>
                                    @if(\Illuminate\Support\Facades\Route::has('docuperfect.templates.demo'))
                                        <a href="{{ route('docuperfect.templates.demo', $t->id) }}" class="corex-btn-outline text-xs px-3 py-1.5">Demo view</a>
                                    @endif
                                </div>
                            </div>
                            <div class="text-xs" style="color: var(--text-secondary);">
                                @if($r['state'] === 'verified')
                                    <span style="color: var(--ds-green); font-weight: 600;">Verified</span> on {{ $t->workshop_verified_at?->format('j M Y H:i') }} — the document has not changed since.
                                @elseif($r['state'] === 'stale')
                                    <span style="color: var(--ds-amber, #d97706); font-weight: 600;">Verification out of date</span> — the document was changed after it was verified. Verify it again before releasing.
                                @else
                                    Not verified yet.
                                @endif
                            </div>
                            <div class="flex flex-wrap items-start gap-4">
                                <form method="POST" action="{{ route('docuperfect.templates.verify', $t->id) }}" class="flex flex-wrap items-center gap-2" data-verify-form>
                                    @csrf
                                    <label class="text-xs flex items-center gap-1" style="color: var(--text-secondary);"><input type="checkbox" name="i_have_looked" value="1"> I have looked at the demo view and the document</label>
                                    <button type="submit" class="corex-btn-outline text-xs px-3 py-1.5" @disabled($r['verdict'] === 'not_ready') title="{{ $r['verdict'] === 'not_ready' ? 'Fix what the readiness check lists first.' : 'Mark this document as verified.' }}">Mark as verified</button>
                                </form>
                                <form method="POST" action="{{ route('docuperfect.templates.release', $t->id) }}" class="flex flex-wrap items-center gap-2" data-release-form>
                                    @csrf
                                    <select name="agency_id" class="list-header-filter" aria-label="Agency to release to" required>
                                        <option value="">Release to which agency?</option>
                                        @foreach($agencies as $a)@if((int) $a->id !== (int) $t->agency_id)<option value="{{ $a->id }}">{{ $a->name }}</option>@endif @endforeach
                                    </select>
                                    <button type="submit" class="corex-btn-primary text-xs px-3 py-1.5" @disabled($r['state'] !== 'verified') title="{{ $r['state'] === 'verified' ? 'It will wait for the agency to approve it.' : 'Verify the document first.' }}">Release to agency</button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

    {{-- ───────────── Release log ───────────── --}}
    @elseif($view === 'log')
        <form method="GET" action="{{ route('docuperfect.template-releases.index') }}" class="flex flex-wrap items-center gap-2 flex-shrink-0" data-log-filters>
            <input type="hidden" name="view" value="log">
            <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Search template, agency, person, note" class="list-header-filter" style="min-width: 16rem;" aria-label="Search the release log">
            <select name="event" class="list-header-filter" aria-label="Event"><option value="">Any event</option>@foreach($eventTypes as $e)<option value="{{ $e }}" @selected($filters['event'] === $e)>{{ TemplateReleaseEvent::eventLabel($e) }}</option>@endforeach</select>
            <select name="agency_id" class="list-header-filter" aria-label="Agency"><option value="">Any agency</option>@foreach($agencies as $a)<option value="{{ $a->id }}" @selected((int) $filters['agency_id'] === (int) $a->id)>{{ $a->name }}</option>@endforeach</select>
            <label class="text-xs" style="color: var(--text-muted);">From <input type="date" name="from" value="{{ $filters['from'] }}" class="list-header-filter"></label>
            <label class="text-xs" style="color: var(--text-muted);">To <input type="date" name="to" value="{{ $filters['to'] }}" class="list-header-filter"></label>
            <button type="submit" class="corex-btn-primary text-xs px-4 py-2">Filter</button>
            @if($filtered)<a href="{{ $tabUrl('log') }}" class="text-xs underline" style="color: var(--text-muted);">Clear</a>@endif
        </form>
        <div class="ws-pane rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
            @if($events->isEmpty())
                <p class="p-5 text-sm" style="color: var(--text-muted);" data-empty>{{ $filtered ? 'No log entries match these filters.' : 'Nothing has been verified, released or approved yet.' }}</p>
            @else
                <table class="w-full text-sm" data-log-table>
                    <thead style="background: var(--surface-2, rgba(0,0,0,0.03)); position: sticky; top: 0;"><tr class="text-left text-xs ws-th" style="color: var(--text-muted);">
                        <th class="px-4 py-2"><a href="{{ $sortUrl('created_at') }}">When{!! $arrow('created_at') !!}</a></th>
                        <th class="px-4 py-2"><a href="{{ $sortUrl('event') }}">What happened{!! $arrow('event') !!}</a></th>
                        <th class="px-4 py-2">Document</th>
                        <th class="px-4 py-2"><a href="{{ $sortUrl('agency') }}">Agency{!! $arrow('agency') !!}</a></th>
                        <th class="px-4 py-2">By</th><th class="px-4 py-2">Note</th>
                    </tr></thead>
                    <tbody>
                    @foreach($events as $e)
                        <tr style="border-top: 1px solid var(--border);" data-log-row>
                            <td class="px-4 py-2 whitespace-nowrap" style="color: var(--text-secondary);">{{ $e->created_at?->format('j M Y H:i') }}</td>
                            <td class="px-4 py-2" style="color: var(--text-primary);">{{ TemplateReleaseEvent::eventLabel($e->event) }}</td>
                            <td class="px-4 py-2" style="color: var(--text-secondary);">{{ $e->template_name ?? '—' }}</td>
                            <td class="px-4 py-2" style="color: var(--text-secondary);">{{ $e->agency_name ?? '—' }}</td>
                            <td class="px-4 py-2" style="color: var(--text-secondary);">{{ $e->actor_name ?? '—' }}</td>
                            <td class="px-4 py-2 text-xs" style="color: var(--text-muted);">{{ $e->note }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <div class="flex-shrink-0">{{ $events->links() }}</div>

    {{-- ───────────── Releases ───────────── --}}
    @else
        <form method="GET" action="{{ route('docuperfect.template-releases.index') }}" class="flex flex-wrap items-center gap-2 flex-shrink-0" data-release-filters>
            <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Search template, agency, person, decision note" class="list-header-filter" style="min-width: 16rem;" aria-label="Search releases">
            <select name="state" class="list-header-filter" aria-label="State"><option value="">Any state</option>@foreach($states as $st)<option value="{{ $st }}" @selected($filters['state'] === $st)>{{ TemplateRelease::stateLabel($st) }}</option>@endforeach</select>
            <select name="agency_id" class="list-header-filter" aria-label="Agency"><option value="">Any agency</option>@foreach($agencies as $a)<option value="{{ $a->id }}" @selected((int) $filters['agency_id'] === (int) $a->id)>{{ $a->name }}</option>@endforeach</select>
            <label class="text-xs" style="color: var(--text-muted);">Released from <input type="date" name="from" value="{{ $filters['from'] }}" class="list-header-filter"></label>
            <label class="text-xs" style="color: var(--text-muted);">to <input type="date" name="to" value="{{ $filters['to'] }}" class="list-header-filter"></label>
            <button type="submit" class="corex-btn-primary text-xs px-4 py-2">Filter</button>
            @if($filtered)<a href="{{ $tabUrl('releases') }}" class="text-xs underline" style="color: var(--text-muted);">Clear</a>@endif
        </form>
        <div class="ws-pane rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
            @if($releases->isEmpty())
                <p class="p-5 text-sm" style="color: var(--text-muted);" data-empty>{{ $filtered ? 'No releases match these filters.' : 'Nothing has been released yet. Use the Workshop view to verify a document and release it to an agency.' }}</p>
            @else
                <table class="w-full text-sm" data-releases-table>
                    <thead style="background: var(--surface-2, rgba(0,0,0,0.03)); position: sticky; top: 0;"><tr class="text-left text-xs ws-th" style="color: var(--text-muted);">
                        <th class="px-4 py-2"><a href="{{ $sortUrl('template') }}">Document{!! $arrow('template') !!}</a></th>
                        <th class="px-4 py-2"><a href="{{ $sortUrl('version') }}">Version{!! $arrow('version') !!}</a></th>
                        <th class="px-4 py-2"><a href="{{ $sortUrl('agency') }}">Agency{!! $arrow('agency') !!}</a></th>
                        <th class="px-4 py-2"><a href="{{ $sortUrl('state') }}">State{!! $arrow('state') !!}</a></th>
                        <th class="px-4 py-2"><a href="{{ $sortUrl('released_at') }}">Released{!! $arrow('released_at') !!}</a></th>
                        <th class="px-4 py-2">Decided</th><th class="px-4 py-2"></th>
                    </tr></thead>
                    <tbody>
                    @foreach($releases as $r)
                        <tr style="border-top: 1px solid var(--border);" data-release-row="{{ $r->id }}" data-state="{{ $r->state }}">
                            <td class="px-4 py-2 font-semibold" style="color: var(--text-primary);">{{ $r->sourceTemplate?->name ?? 'A deleted template' }}</td>
                            <td class="px-4 py-2" style="color: var(--text-secondary);">v{{ $r->version }}</td>
                            <td class="px-4 py-2" style="color: var(--text-secondary);">{{ $r->agency?->name ?? '—' }}</td>
                            <td class="px-4 py-2"><span class="text-xs font-semibold" style="color: {{ $stateColour[$r->state] ?? 'var(--text-muted)' }};">{{ TemplateRelease::stateLabel($r->state) }}</span></td>
                            <td class="px-4 py-2 text-xs whitespace-nowrap" style="color: var(--text-secondary);">{{ $r->released_at?->format('j M Y H:i') }}<br><span style="color: var(--text-muted);">{{ $r->releasedByUser?->name }}</span></td>
                            <td class="px-4 py-2 text-xs whitespace-nowrap" style="color: var(--text-secondary);">@if($r->decided_at){{ $r->decided_at->format('j M Y H:i') }}<br><span style="color: var(--text-muted);">{{ $r->decidedByUser?->name }}</span>@else — @endif</td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <a href="{{ route('docuperfect.template-releases.show', $r->id) }}" class="corex-btn-outline text-xs px-2 py-1">View</a>
                                @if($r->state === 'awaiting_approval')
                                    <form method="POST" action="{{ route('docuperfect.template-releases.withdraw', $r->id) }}" class="inline" data-confirm="Withdraw this release? The copy at the agency is archived. You can release again afterwards — that makes the next version.">
                                        @csrf<button type="submit" class="corex-btn-outline text-xs px-2 py-1">Withdraw</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <div class="flex-shrink-0">{{ $releases->links() }}</div>
    @endif
</div>
@include('docuperfect.partials._fixed-shell')
@endsection
