{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 — spec: .ai/specs/esign-template-transfer.md §13.4 (release detail; shared by the owner and the agency) --}}
@extends('layouts.corex')

@section('corex-content')
@php
    use App\Models\Docuperfect\TemplateRelease;
    use App\Models\Docuperfect\TemplateReleaseEvent;
    $r = $release;
    $copyId = $r->template_id;
    $stateColour = ['awaiting_approval' => 'var(--ds-amber, #d97706)', 'live' => 'var(--ds-green)', 'declined' => 'var(--ds-crimson)', 'withdrawn' => 'var(--text-muted)', 'superseded' => 'var(--text-muted)'][$r->state] ?? 'var(--text-muted)';
    $backRoute = $mode === 'owner' ? route('docuperfect.template-releases.index') : route('docuperfect.template-approvals.index');
    $snap = (array) ($r->readiness_snapshot ?? []);
    $checks = array_values(array_filter((array) ($snap['checks'] ?? []), fn ($c) => in_array($c['status'] ?? '', ['fail', 'warn'], true)));
    $canOpenReview = \Illuminate\Support\Facades\Route::has('docuperfect.templates.readiness');
@endphp
<div class="w-full space-y-4" data-release-detail data-state="{{ $r->state }}">

    <div class="rounded-md px-6 py-4 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ ($mode === 'owner' ? $r->sourceTemplate?->name : $r->template?->name) ?? 'Document' }} <span style="color: var(--text-muted); font-weight: 500;">version {{ $r->version }}</span></h1>
                <p class="text-xs" style="color: {{ $stateColour }}; font-weight: 600;" data-release-state>{{ TemplateRelease::stateLabel($r->state) }}@if($mode === 'owner') — released to {{ $r->agency?->name }}@endif</p>
            </div>
            <a href="{{ $backRoute }}" class="corex-btn-outline text-xs px-3 py-1.5">Back to the list</a>
        </div>
    </div>

    @include('corex.partials._hub-tabs', ['hub' => \App\Support\Navigation\Hubs\DocumentTemplatesTabs::class])

    @if(session('status'))
        <div class="rounded-md px-4 py-2 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-2 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ $errors->first() }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="lg:col-span-2 space-y-4">

            @if($r->state === 'awaiting_approval')
                <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                    <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Look at the document before you decide</h2>
                    <p class="text-xs" style="color: var(--text-muted);">Open the demo to see the document with sample details filled in, with every place to sign marked for the right person. The readiness check lists anything still wrong.</p>
                    <div class="flex flex-wrap gap-2">
                        @if(\Illuminate\Support\Facades\Route::has('docuperfect.templates.demo'))<a href="{{ route('docuperfect.templates.demo', $copyId) }}" class="corex-btn-outline text-xs px-3 py-1.5" data-open-demo>Open the demo</a>@endif
                        <a href="{{ route('docuperfect.templates.readiness', $copyId) }}" class="corex-btn-outline text-xs px-3 py-1.5" data-open-readiness>Readiness check</a>
                    </div>
                </div>

                @if($canDecide)
                    <div class="rounded-md p-4 space-y-4" style="background: var(--surface); border: 1px solid var(--border);" data-decision-panel>
                        <form method="POST" action="{{ route('docuperfect.template-approvals.approve', $r->id) }}" class="space-y-2" data-approve-form>
                            @csrf
                            <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Approve — make it live for your agency</h2>
                            <label class="text-xs flex items-center gap-1" style="color: var(--text-secondary);"><input type="checkbox" name="i_have_looked" value="1"> I have looked at the document and the demo</label>
                            <textarea name="note" rows="2" maxlength="1000" placeholder="A note (optional)" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);"></textarea>
                            <button type="submit" class="corex-btn-primary text-xs px-4 py-2" data-confirm="Approve this document? Your agents will be able to use it straight away." data-confirm-label="Approve">Approve</button>
                        </form>
                        <hr style="border-color: var(--border);">
                        <form method="POST" action="{{ route('docuperfect.template-approvals.decline', $r->id) }}" class="space-y-2" data-decline-form>
                            @csrf
                            <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Decline — send it back</h2>
                            <textarea name="reason" rows="2" maxlength="1000" required placeholder="Say what is wrong, so it can be fixed" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">{{ old('reason') }}</textarea>
                            <button type="submit" class="corex-btn-outline text-xs px-4 py-2" data-confirm="Decline this document? It stays hidden and the reason is sent back to CoreX." data-confirm-label="Decline" data-confirm-danger>Decline</button>
                        </form>
                    </div>
                @elseif($mode === 'agency')
                    <div class="rounded-md px-4 py-3 text-xs" style="border: 1px solid var(--border); color: var(--text-muted);" data-cannot-decide>Only the people your agency has chosen to authorise documents can approve or decline this one.</div>
                @endif
            @endif

            @if($r->decision_note)
                <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                    <h2 class="text-sm font-semibold" style="color: var(--text-primary);">{{ $r->state === 'declined' ? 'Why it was declined' : 'Note' }}</h2>
                    <p class="text-sm mt-1" style="color: var(--text-secondary);" data-decision-note>{{ $r->decision_note }}</p>
                    <p class="text-xs mt-1" style="color: var(--text-muted);">{{ $r->decidedByUser?->name }} · {{ $r->decided_at?->format('j M Y H:i') }}</p>
                </div>
            @endif

            @if($checks !== [])
                <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                    <h2 class="text-sm font-semibold" style="color: var(--text-primary);">What the readiness check said when it was released</h2>
                    <ul class="mt-2 space-y-1 text-xs" style="color: var(--text-secondary);">
                        @foreach($checks as $c)<li><strong>{{ $c['title'] }}</strong> — {{ $c['detail'] }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @if($mode === 'owner' && $r->state === 'awaiting_approval')
                <form method="POST" action="{{ route('docuperfect.template-releases.withdraw', $r->id) }}" data-confirm="Withdraw this release? The copy at the agency is archived. You can release again afterwards — that makes the next version.">
                    @csrf<button type="submit" class="corex-btn-outline text-xs px-3 py-1.5">Withdraw this release</button>
                </form>
            @endif
        </div>

        <div class="space-y-4">
            <div class="rounded-md p-4 text-xs space-y-1" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">
                <div><strong>Released</strong> {{ $r->released_at?->format('j M Y H:i') }} by {{ $r->releasedByUser?->name ?? '—' }}</div>
                @if($r->previous)<div><strong>Replaces</strong> version {{ $r->previous->version }}</div>@endif
                <div><strong>Readiness</strong> {{ ['not_ready' => 'fix first', 'ready_with_warnings' => 'ready, with warnings', 'ready' => 'ready'][$r->readiness_verdict] ?? '—' }}</div>
                @if(! empty($r->lease_link['category']))<div><strong>Lease agreement</strong> carried with it ({{ str_replace('_', ' ', $r->lease_link['category']) }}); it is set up when the document is approved.</div>@endif
            </div>
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                <h2 class="text-sm font-semibold mb-2" style="color: var(--text-primary);">History</h2>
                <ol class="space-y-2 text-xs" data-release-timeline>
                    @foreach($r->events as $e)
                        <li style="color: var(--text-secondary);" data-event="{{ $e->event }}"><span style="color: var(--text-primary); font-weight: 600;">{{ TemplateReleaseEvent::eventLabel($e->event) }}</span><br>{{ $e->created_at?->format('j M Y H:i') }} · {{ $e->actor_name ?? '—' }}@if($e->note)<br><em>{{ $e->note }}</em>@endif</li>
                    @endforeach
                </ol>
            </div>
        </div>
    </div>
</div>
@endsection
