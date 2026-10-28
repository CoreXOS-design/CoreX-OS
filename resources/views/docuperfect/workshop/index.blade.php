{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 — spec: .ai/specs/esign-template-transfer.md §13.4a (Document workshop hub, CoreX owner only) --}}
@extends('layouts.corex')

@section('corex-content')
@php
    use App\Models\Docuperfect\TemplateRelease;
    $sortUrl = fn (string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc', 'page' => null]);
    $arrow = fn (string $col) => $sort === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    $amber = 'var(--ds-amber, #d97706)';
    $stageLabel = ['workshop' => 'Being prepared', 'stale' => 'Changed since verified', 'verified' => 'Verified', 'released' => 'Waiting for approval', 'live' => 'Live at an agency'];
    $stageColour = ['workshop' => 'var(--text-muted)', 'stale' => $amber, 'verified' => 'var(--ds-green)', 'released' => $amber, 'live' => 'var(--ds-green)'];
    $verdictLabel = ['not_ready' => 'Fix first', 'ready_with_warnings' => 'Ready, with warnings', 'ready' => 'Ready'];
    $verdictColour = ['not_ready' => 'var(--ds-crimson)', 'ready_with_warnings' => $amber, 'ready' => 'var(--ds-green)'];
@endphp
<style>
    /* Fixed-shell page: the menu and header stay put and the page itself does not scroll; the list pane scrolls inside itself (page standard). */
    .ws-fit { height: calc(100vh - 54px - 48px); display: flex; flex-direction: column; overflow: hidden; }
    .ws-pane { flex: 1 1 auto; min-height: 0; overflow: auto; }
    .ws-th a { color: inherit; text-decoration: none; }
</style>
<div class="w-full ws-fit gap-3" data-fixed-shell data-workshop-hub>

    <div class="rounded-md px-6 py-4 corex-page-banner flex-shrink-0">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Document workshop</h1>
                <p class="text-xs" style="color: var(--text-muted);">Where a document is prepared, checked and sent to an agency.</p>
            </div>
            <div class="flex flex-wrap gap-2 text-xs" data-workshop-links>
                <a href="{{ route('docuperfect.template-releases.index') }}" class="corex-btn-outline px-3 py-1.5">Releases</a>
                <a href="{{ route('docuperfect.template-approvals.index') }}" class="corex-btn-outline px-3 py-1.5">Approvals @if($waitingTotal > 0)<span class="font-semibold" style="color: {{ $amber }};">({{ $waitingTotal }} waiting)</span>@endif</a>
                <a href="{{ route('docuperfect.template-releases.index', ['view' => 'log']) }}" class="corex-btn-outline px-3 py-1.5">Release log</a>
                <a href="{{ route('docuperfect.templates.index') }}" class="corex-btn-outline px-3 py-1.5">Templates</a>
            </div>
        </div>
        <ol class="mt-2 text-xs space-y-0.5 list-decimal pl-5" style="color: var(--text-secondary);" data-workshop-how>
            <li>Build the document under Templates, then press <strong>Check readiness</strong> — it lists anything that would stop it being signed properly — and open the <strong>Demo view</strong> to see it with sample data and every place to sign.</li>
            <li>When it looks right, tick that you have looked and press <strong>Verify</strong>. If the document is changed afterwards, it has to be verified again.</li>
            <li><strong>Release</strong> it to an agency. It is not live there until the people that agency named approve it; you follow it under Releases.</li>
        </ol>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-2 text-sm flex-shrink-0" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-2 text-sm flex-shrink-0" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <div class="font-semibold">{{ $errors->first() }}</div>
            @if(session('error_details'))<ul class="list-disc pl-5 mt-1 text-xs">@foreach(session('error_details') as $line)<li>{{ $line }}</li>@endforeach</ul>@endif
        </div>
    @endif

    <form method="GET" action="{{ route('docuperfect.workshop.index') }}" class="flex flex-wrap items-center gap-2 flex-shrink-0" data-workshop-filters>
        <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Search document or agency name" class="list-header-filter" style="min-width: 16rem;" aria-label="Search documents">
        <select name="stage" class="list-header-filter" aria-label="Where it is">
            <option value="">Any stage</option>
            @foreach(['not_verified' => 'Not verified yet', 'verified' => 'Verified', 'waiting' => 'Waiting for approval', 'live' => 'Live at an agency'] as $k => $label)
                <option value="{{ $k }}" @selected($filters['stage'] === $k)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="agency_id" class="list-header-filter" aria-label="Agency the document belongs to"><option value="">Any agency</option>@foreach($agencies as $a)<option value="{{ $a->id }}" @selected((int) $filters['agency_id'] === (int) $a->id)>{{ $a->name }}</option>@endforeach</select>
        <button type="submit" class="corex-btn-primary text-xs px-4 py-2">Filter</button>
        @if($filtered)<a href="{{ route('docuperfect.workshop.index') }}" class="text-xs underline" style="color: var(--text-muted);">Clear</a>@endif
    </form>

    <div class="ws-pane rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        @if($rows->isEmpty())
            <p class="p-5 text-sm" style="color: var(--text-muted);" data-empty>{{ $filtered ? 'No documents match these filters.' : 'There are no templates to prepare yet. Build or import one under Templates and it will be listed here.' }}</p>
        @else
            <table class="w-full text-sm" data-workshop-table>
                <thead style="background: var(--surface-2, rgba(0,0,0,0.03)); position: sticky; top: 0; z-index: 1;"><tr class="text-left text-xs ws-th" style="color: var(--text-muted);">
                    <th class="px-4 py-2"><a href="{{ $sortUrl('name') }}">Document{!! $arrow('name') !!}</a></th>
                    <th class="px-4 py-2"><a href="{{ $sortUrl('agency') }}">Belongs to{!! $arrow('agency') !!}</a></th>
                    <th class="px-4 py-2"><a href="{{ $sortUrl('verified') }}">Where it is{!! $arrow('verified') !!}</a></th>
                    <th class="px-4 py-2">Readiness</th>
                    <th class="px-4 py-2"><a href="{{ $sortUrl('last_release') }}">Last release{!! $arrow('last_release') !!}</a></th>
                    <th class="px-4 py-2">Next step</th>
                </tr></thead>
                <tbody>
                @foreach($rows as $r)
                    @php $t = $r['template']; $last = $r['last']; @endphp
                    <tr style="border-top: 1px solid var(--border); vertical-align: top;" data-workshop-row="{{ $t->id }}" data-verified-state="{{ $r['verified'] }}" data-stage="{{ $r['stage'] }}">
                        <td class="px-4 py-3">
                            <div class="font-semibold" style="color: var(--text-primary);">{{ $t->name }}</div>
                            <div class="text-xs" style="color: var(--text-muted);">Changed {{ $t->updated_at?->format('j M Y') }}</div>
                        </td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $r['agency_name'] }}</td>
                        <td class="px-4 py-3">
                            <span class="text-xs font-semibold" style="color: {{ $stageColour[$r['stage']] }};" data-stage-label>{{ $stageLabel[$r['stage']] }}</span>
                            @if($r['verified'] === 'verified' || $r['verified'] === 'stale')
                                <div class="text-xs" style="color: var(--text-muted);">{{ $r['verified'] === 'stale' ? 'Verified' : 'Verified on' }} {{ $t->workshop_verified_at?->format('j M Y') }}@if($r['verified'] === 'stale') — out of date @endif</div>
                            @endif
                            @if($r['live'] > 0 || $r['waiting'] > 0)
                                <div class="text-xs" style="color: var(--text-muted);">@if($r['live'] > 0){{ $r['live'] }} live @endif @if($r['waiting'] > 0){{ $r['waiting'] }} waiting @endif</div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-xs font-semibold" style="color: {{ $verdictColour[$r['verdict']] }};" data-readiness>{{ $verdictLabel[$r['verdict']] }}</span>
                            <div class="text-xs" style="color: var(--text-muted);">{{ $r['counts']['fail'] }} to fix · {{ $r['counts']['warn'] }} warnings</div>
                        </td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);" data-last-release>
                            @if($last)
                                <a href="{{ route('docuperfect.template-releases.show', $last->id) }}" class="underline">v{{ $last->version }} to {{ $last->agency?->name ?? 'an agency' }}</a>
                                <div style="color: var(--text-muted);">{{ TemplateRelease::stateLabel($last->state) }} · {{ $last->released_at?->format('j M Y') }}</div>
                            @else
                                <span style="color: var(--text-muted);">Never released</span>
                            @endif
                        </td>
                        <td class="px-4 py-3" style="min-width: 19rem;">
                            <div class="flex flex-wrap gap-2 mb-2">
                                <a href="{{ route('docuperfect.templates.readiness', $t->id) }}" class="corex-btn-outline text-xs px-3 py-1.5" data-action="readiness">Check readiness</a>
                                <a href="{{ route('docuperfect.templates.demo', $t->id) }}" class="corex-btn-outline text-xs px-3 py-1.5" data-action="demo">Demo view</a>
                            </div>
                            @if($r['verified'] !== 'verified')
                                <form method="POST" action="{{ route('docuperfect.templates.verify', $t->id) }}" class="flex flex-wrap items-center gap-2" data-verify-form>
                                    @csrf
                                    <label class="text-xs flex items-center gap-1" style="color: var(--text-secondary);"><input type="checkbox" name="i_have_looked" value="1"> I have looked at the demo view and the document</label>
                                    <button type="submit" class="corex-btn-outline text-xs px-3 py-1.5" @disabled($r['verdict'] === 'not_ready') title="{{ $r['verdict'] === 'not_ready' ? 'Fix what the readiness check lists first.' : 'Mark this document as verified.' }}" data-action="verify">Verify</button>
                                </form>
                                @if($r['verdict'] === 'not_ready')<div class="text-xs mt-1" style="color: var(--ds-crimson);">Cannot be verified yet — open Check readiness to see what to fix.</div>@endif
                            @else
                                <form method="POST" action="{{ route('docuperfect.templates.release', $t->id) }}" class="flex flex-wrap items-center gap-2" data-release-form>
                                    @csrf
                                    <select name="agency_id" class="list-header-filter" aria-label="Agency to release to" required>
                                        <option value="">Release to which agency?</option>
                                        @foreach($agencies as $a)@if((int) $a->id !== (int) $t->agency_id)<option value="{{ $a->id }}">{{ $a->name }}</option>@endif @endforeach
                                    </select>
                                    <button type="submit" class="corex-btn-primary text-xs px-3 py-1.5" title="It will wait for the agency to approve it." data-action="release">Release</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="flex-shrink-0">{{ $rows->links() }}</div>
</div>
@include('docuperfect.partials._fixed-shell')
@endsection
