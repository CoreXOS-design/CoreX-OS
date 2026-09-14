{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Documents › Approvals — the e-sign officer queue. Spec esign-compliance-approval-gate.md §8.4. --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5" x-data="{ decideId: null, decideMode: null, decideName: '' }">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Documents awaiting compliance approval</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    @if($routeOn)
                        Every e-sign document stops here after the sender signs, until a Reporting Officer approves it. The Compliance Officer can override a decline.
                    @else
                        This agency's e-sign route is "full-status practitioners send without approval" — nothing is held here unless the route is switched in Company Settings.
                    @endif
                </p>
            </div>
            <form method="GET" class="flex items-center gap-2">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <input type="search" name="q" value="{{ $search }}" placeholder="Document or sender…" class="rounded-md px-3 py-1.5 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary); min-width: 220px;">
                <button type="submit" class="corex-btn-outline text-xs">Search</button>
            </form>
        </div>
    </div>

    @foreach(['success' => 'var(--ds-green, #059669)', 'error' => 'var(--ds-crimson, #dc2626)'] as $flash => $colour)
        @if(session($flash))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, {{ $colour }} 10%, transparent); border: 1px solid color-mix(in srgb, {{ $colour }} 30%, transparent); color: var(--text-primary);">{{ session($flash) }}</div>
        @endif
    @endforeach
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 30%, transparent); color: var(--text-primary);">{{ $errors->first() }}</div>
    @endif

    @if(! $isOfficer)
        <div class="rounded-md p-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <p class="text-sm font-semibold" style="color: var(--text-primary);">You are not an e-sign officer for this agency.</p>
            <p class="text-xs mt-1" style="color: var(--text-muted);">An administrator appoints Reporting Officers and the Compliance Officer under Company Settings › Compliance officers.</p>
        </div>
    @else
        <div class="flex items-center gap-2">
            <a href="{{ route('docuperfect.approvals.index', ['tab' => 'pending', 'q' => $search]) }}" class="corex-btn-{{ $tab === 'pending' ? 'primary' : 'outline' }} text-xs">Waiting ({{ number_format($pendingCount) }})</a>
            <a href="{{ route('docuperfect.approvals.index', ['tab' => 'declined', 'q' => $search]) }}" class="corex-btn-{{ $tab === 'declined' ? 'primary' : 'outline' }} text-xs">Declined ({{ number_format($declinedCount) }})</a>
        </div>

        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            @forelse($approvals as $a)
                @php
                    $tpl = $a->signatureTemplate;
                    $doc = $tpl?->document;
                    $parties = $tpl ? $tpl->requests->where('party_role', '!=', 'agent')->pluck('signer_name')->filter()->values() : collect();
                    $isSender = (int) $a->requested_by_user_id === (int) auth()->id();
                @endphp
                <div class="px-4 py-3 flex flex-col md:flex-row md:items-start gap-3" style="border-bottom: 1px solid var(--border);">
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold" style="color: var(--text-primary);">
                            {{ $doc->name ?? 'Untitled document' }}
                            @if($doc && $doc->template)
                                <span class="ds-badge ds-badge-default ml-2" title="{{ $doc->template->name }}">{{ \Illuminate\Support\Str::limit($doc->template->name, 24) }}</span>
                            @endif
                        </div>
                        <div class="text-xs mt-1" style="color: var(--text-secondary);">
                            Sent by <strong>{{ $a->requester?->name ?? 'a former user' }}</strong>
                            @if($parties->isNotEmpty()) · to {{ $parties->implode(', ') }} @endif
                            · held {{ $a->created_at?->diffForHumans() }}
                        </div>
                        @if($a->status === 'declined')
                            <div class="text-xs mt-1" style="color: var(--ds-crimson, #dc2626);">Declined by {{ $a->decider?->name ?? 'an officer' }} {{ $a->decided_at?->diffForHumans() }}: {{ $a->decision_note }}</div>
                        @endif
                        @if($isSender && $a->status === 'pending')
                            <div class="text-[11px] mt-1" style="color: var(--text-muted);">This is your own document — another officer has to approve it{{ $isCo ? ' (you are the Compliance Officer, so you may)' : '' }}.</div>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if($doc)
                            <a href="{{ route('docuperfect.signatures.review', $doc) }}" class="corex-btn-outline text-xs">View document</a>
                        @endif
                        @if($a->status === 'pending')
                            <form method="POST" action="{{ route('docuperfect.approvals.approve', $a) }}">@csrf
                                <button type="submit" class="corex-btn-primary text-xs">Approve &amp; send</button>
                            </form>
                            <button type="button" class="corex-btn-outline text-xs" style="color: var(--ds-crimson, #dc2626);"
                                    @click="decideId = {{ $a->id }}; decideMode = 'decline'; decideName = {{ Js::from($doc->name ?? 'Untitled document') }}">Decline</button>
                        @elseif($a->status === 'declined' && $isCo)
                            <button type="button" class="corex-btn-primary text-xs"
                                    @click="decideId = {{ $a->id }}; decideMode = 'override'; decideName = {{ Js::from($doc->name ?? 'Untitled document') }}">Override &amp; send</button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="px-4 py-8 text-sm text-center" style="color: var(--text-muted);">
                    {{ $tab === 'declined' ? 'No declined documents in your scope.' : 'Nothing is waiting for your approval.' }}
                </p>
            @endforelse
        </div>

        @if($approvals && $approvals->hasPages())
            <div>{{ $approvals->links() }}</div>
        @endif
    @endif

    {{-- Decline / override modal — reason is compulsory --}}
    <div x-show="decideId !== null" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,.5);" @keydown.escape.window="decideId = null">
        <div class="rounded-md w-full max-w-md p-5 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" @click.outside="decideId = null">
            <h3 class="text-sm font-bold" style="color: var(--text-primary);" x-text="decideMode === 'override' ? 'Override the decline and send' : 'Decline to release'"></h3>
            <p class="text-xs" style="color: var(--text-muted);"><span x-text="decideName"></span> — <span x-text="decideMode === 'override' ? 'the reason goes on the record beside the original decline.' : 'the sender sees this reason and can ask again or cancel.'"></span></p>
            <form method="POST" :action="decideId ? (decideMode === 'override' ? '{{ url('/docuperfect/approvals') }}/' + decideId + '/override' : '{{ url('/docuperfect/approvals') }}/' + decideId + '/decline') : '#'">
                @csrf
                <textarea name="reason" rows="3" required minlength="3" maxlength="2000" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" placeholder="Reason"></textarea>
                <div class="flex justify-end gap-2 mt-3">
                    <button type="button" class="corex-btn-outline text-xs" @click="decideId = null">Cancel</button>
                    <button type="submit" class="corex-btn-primary text-xs" x-text="decideMode === 'override' ? 'Override & send' : 'Decline'"></button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
