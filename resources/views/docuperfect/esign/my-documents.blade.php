@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6"
     x-data="{
        showCancelModal: false,
        cancelTemplateId: null,
        cancelDocName: '',
        showCompleted: false,
        showCancelled: false,
        activeFilter: null,
     }">

    {{-- Page Header --}}
    <div style="background: var(--brand-default, #0b2a4a);" class="rounded-md px-6 py-5 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight tracking-tight">{{ ($showOnlyAuthorisation ?? false) ? 'Authorise Documents' : 'My E-Sign Documents' }}</h2>
            <div class="text-sm text-white/60 mt-1">
                @if($showOnlyAuthorisation ?? false)
                    <a href="{{ route('docuperfect.esign.myDocuments') }}" class="text-white/60 hover:text-white transition-all duration-300">&larr; My E-Sign Documents</a>
                    &middot; Candidate documents requiring your authorisation.
                @else
                    <a href="{{ route('docuperfect.dashboard') }}" class="text-white/60 hover:text-white transition-all duration-300">&larr; DocuPerfect</a>
                    &middot; Track all your e-sign flows, signing progress, and approvals.
                @endif
            </div>
        </div>
        <a href="{{ route('docuperfect.esign.create') }}"
           class="corex-btn-primary inline-flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
            New E-Sign
        </a>
    </div>

    @if(session('status'))
        <div class="rounded-md border border-emerald-500/30 px-4 py-3 text-sm" style="background: rgba(16,185,129,0.1); color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-md border border-red-500/30 px-4 py-3 text-sm" style="background: rgba(239,68,68,0.1); color: var(--text-primary);">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    @if(!($showOnlyAuthorisation ?? false))
    {{-- Status summary tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        @if(($counts['needs_authorisation'] ?? 0) > 0)
        <a href="#section-needs-authorisation" onclick="event.preventDefault(); scrollToSection('section-needs-authorisation')"
           class="rounded-md p-4 text-center border-2 cursor-pointer block transition-all duration-300 hover:opacity-90" style="border-color: #f59e0b; background: rgba(245,158,11,0.1);">
            <div class="text-2xl font-bold" style="color: #f59e0b;">{{ $counts['needs_authorisation'] }}</div>
            <div class="text-xs mt-1 font-semibold" style="color: #b45309;">Needs Authorisation</div>
        </a>
        @endif
        @if($counts['pending_approval'] > 0)
        <a href="#section-pending-approval" onclick="event.preventDefault(); scrollToSection('section-pending-approval')"
           class="rounded-md p-4 text-center border-2 border-amber-400 cursor-pointer block transition-all duration-300 hover:border-amber-500" style="background: rgba(245,158,11,0.1);">
            <div class="text-2xl font-bold text-amber-500">{{ $counts['pending_approval'] }}</div>
            <div class="text-xs text-amber-500 mt-1 font-semibold">Needs Approval</div>
        </a>
        @endif
        <a href="#section-draft" onclick="event.preventDefault(); scrollToSection('section-draft')"
           class="ds-status-card p-4 text-center transition-all duration-300 cursor-pointer block">
            <div class="text-2xl font-bold" style="color: {{ $counts['draft'] > 0 ? 'var(--text-primary)' : 'var(--text-muted)' }}">{{ $counts['draft'] }}</div>
            <div class="text-xs mt-1" style="color: {{ $counts['draft'] > 0 ? 'var(--text-secondary)' : 'var(--text-muted)' }}">Draft</div>
        </a>
        <a href="#section-ready" onclick="event.preventDefault(); scrollToSection('section-ready')"
           class="ds-status-card p-4 text-center transition-all duration-300 cursor-pointer block">
            <div class="text-2xl font-bold" style="color: {{ $counts['ready_to_sign'] > 0 ? 'var(--brand-icon, #0ea5e9)' : 'var(--text-muted)' }}">{{ $counts['ready_to_sign'] }}</div>
            <div class="text-xs mt-1" style="color: {{ $counts['ready_to_sign'] > 0 ? 'var(--text-secondary)' : 'var(--text-muted)' }}">Ready to Sign</div>
        </a>
        <a href="#section-awaiting" onclick="event.preventDefault(); scrollToSection('section-awaiting')"
           class="ds-status-card p-4 text-center transition-all duration-300 cursor-pointer block">
            <div class="text-2xl font-bold" style="color: {{ $counts['awaiting_signatures'] > 0 ? '#f59e0b' : 'var(--text-muted)' }}">{{ $counts['awaiting_signatures'] }}</div>
            <div class="text-xs mt-1" style="color: {{ $counts['awaiting_signatures'] > 0 ? 'var(--text-secondary)' : 'var(--text-muted)' }}">Awaiting Signatures</div>
        </a>
        <a href="#section-completed" onclick="event.preventDefault(); scrollToSection('section-completed')"
           class="ds-status-card p-4 text-center transition-all duration-300 cursor-pointer block">
            <div class="text-2xl font-bold" style="color: {{ $counts['completed'] > 0 ? '#10b981' : 'var(--text-muted)' }}">{{ $counts['completed'] }}</div>
            <div class="text-xs mt-1" style="color: {{ $counts['completed'] > 0 ? 'var(--text-secondary)' : 'var(--text-muted)' }}">Completed</div>
        </a>
    </div>
    @endif

    {{-- ===== CANDIDATE DOCUMENTS — NEEDS AUTHORISATION ===== --}}
    @if(($groups['needs_authorisation'] ?? collect())->isNotEmpty())
    <div id="section-needs-authorisation" class="space-y-3 scroll-mt-4">
        <h3 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2" style="color: #f59e0b;">
            <span class="inline-flex items-center justify-center w-5 h-5 text-white text-[10px] font-bold rounded-md" style="background: #f59e0b;">{{ $groups['needs_authorisation']->count() }}</span>
            Candidate Documents &mdash; Needs Authorisation
        </h3>
        <div class="space-y-3">
            @foreach($groups['needs_authorisation'] as $tpl)
                @php
                    $doc = $tpl->document;
                    $candidateName = $tpl->creator?->name ?? 'Unknown Candidate';
                @endphp
                <div class="rounded-md border-2 p-4" style="border-color: #f59e0b; background: rgba(245,158,11,0.08);">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <div class="font-semibold" style="color: var(--text-primary);">
                                {{ $doc->name ?? 'Untitled' }}
                                @if($doc && $doc->template)
                                    <span class="inline-block ml-2 px-2 py-0.5 rounded-md text-[10px] font-semibold" style="background: var(--surface-2); color: var(--text-secondary);">{{ $doc->template->name }}</span>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-2 mt-2">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold" style="background: rgba(245,158,11,0.15); color: #b45309;">
                                    Candidate: <strong>{{ $candidateName }}</strong>
                                </span>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold
                                    {{ $tpl->status === 'awaiting_supervisor' ? 'bg-yellow-100 text-yellow-800' : 'bg-orange-100 text-orange-800' }}">
                                    {{ $tpl->status === 'awaiting_supervisor' ? 'Initial Review' : 'Final Sign-off' }}
                                </span>
                                <span class="text-[10px]" style="color: var(--text-muted);">
                                    Created {{ $tpl->created_at->format('d M Y') }}
                                </span>
                            </div>
                        </div>
                        <div class="flex flex-col gap-2 ml-4">
                            @if($doc)
                            <a href="{{ route('docuperfect.signatures.review', $doc) }}"
                               class="inline-flex items-center gap-1.5 px-4 py-2 text-white text-xs font-semibold rounded-md shadow hover:opacity-90 whitespace-nowrap transition-all duration-300"
                               style="background: #f59e0b;">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                                Review &amp; Authorise
                            </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    @if(!($showOnlyAuthorisation ?? false))
    {{-- ===== NEEDS YOUR APPROVAL ===== --}}
    @if($groups['pending_approval']->isNotEmpty())
    <div id="section-pending-approval" class="space-y-3 scroll-mt-4">
        <h3 class="text-sm font-semibold text-amber-500 uppercase tracking-wider flex items-center gap-2">
            <span class="inline-flex items-center justify-center w-5 h-5 bg-amber-500 text-white text-[10px] font-bold rounded-md">{{ $groups['pending_approval']->count() }}</span>
            Needs Your Approval
        </h3>
        <div class="space-y-3">
            @foreach($groups['pending_approval'] as $tpl)
                @php
                    $doc = $tpl->document;
                    $completedReq = $tpl->requests->where('status', 'completed')->where('party_role', '!=', 'agent')->sortByDesc('completed_at')->first();
                    $requests = $tpl->requests->keyBy('party_role');
                @endphp
                <div class="rounded-md border-2 border-amber-400 p-4" style="background: rgba(245,158,11,0.08);">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <div class="font-semibold" style="color: var(--text-primary);">
                                {{ $doc->name ?? 'Untitled' }}
                                @if($doc && $doc->template)
                                    <span class="inline-block ml-2 px-2 py-0.5 rounded-md text-[10px] font-semibold" style="background: var(--surface-2); color: var(--text-secondary);">{{ $doc->template->name }}</span>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-2 mt-2">
                                @foreach($tpl->requests as $req)
                                    @if($req->status === 'completed')
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold bg-emerald-500/15 text-emerald-400">
                                            &#10003; {{ ucfirst($req->party_role ?? 'Party') }} signed
                                            @if($req->signing_method === 'wet_ink')
                                                <span style="color: var(--text-muted);">(wet ink)</span>
                                            @endif
                                        </span>
                                    @elseif($req->status === 'waiting')
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold" style="background: var(--surface-2); color: var(--text-muted);">
                                            &#128274; {{ ucfirst($req->party_role ?? 'Party') }} waiting
                                        </span>
                                    @elseif(in_array($req->status, ['pending', 'viewed', 'partially_signed']))
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold" style="background: rgba(14,165,233,0.12); color: var(--brand-icon, #0ea5e9);">
                                            &#9993; {{ ucfirst($req->party_role ?? 'Party') }} — {{ $req->status === 'viewed' ? 'viewed' : ($req->status === 'partially_signed' ? 'signing' : 'sent') }}
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                            @if($completedReq)
                                <div class="text-xs text-amber-500 mt-2">
                                    {{ ucfirst($completedReq->party_role ?? 'Party') }} <strong>{{ $completedReq->signer_name }}</strong>
                                    signed {{ $completedReq->completed_at?->diffForHumans() }}
                                </div>
                            @endif
                        </div>
                        <div class="flex flex-col gap-2 ml-4">
                            @if($doc)
                            <a href="{{ route('docuperfect.signatures.review', $doc) }}"
                               class="inline-flex items-center px-4 py-2 bg-amber-600 text-white text-xs font-medium rounded-md hover:bg-amber-700 whitespace-nowrap transition-all duration-300">
                                Review &amp; Approve
                            </a>
                            @endif
                            <button type="button"
                                    @click="cancelTemplateId = {{ $tpl->id }}; cancelDocName = {{ Js::from($doc->name ?? 'Untitled') }}; showCancelModal = true"
                                    class="text-xs text-red-400 hover:text-red-300 text-center transition-all duration-300">
                                Cancel Document
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ===== AWAITING SIGNATURES ===== --}}
    @if($groups['awaiting']->isNotEmpty())
    <div id="section-awaiting" class="space-y-3 scroll-mt-4">
        <h3 class="text-sm font-semibold text-amber-500 uppercase tracking-wider">Awaiting Signatures</h3>
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <table class="w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Document</th>
                        <th class="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Signing Progress</th>
                        <th class="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Created</th>
                        <th class="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($groups['awaiting'] as $tpl)
                    @php
                        $doc = $tpl->document;
                        $totalReq = $tpl->requests->count();
                        $completedReq = $tpl->requests->where('status', 'completed')->count();
                    @endphp
                    <tr class="transition-all duration-300" style="border-bottom: 1px solid var(--border);"
                        onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                        <td class="px-4 py-3">
                            <div class="font-medium" style="color: var(--text-primary);">{{ $doc->name ?? 'Untitled' }}</div>
                            @if($doc && $doc->template)
                                <div class="text-[10px] mt-0.5" style="color: var(--text-muted);">{{ $doc->template->name }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($totalReq > 0)
                            <div class="flex flex-col gap-1.5">
                                @foreach($tpl->requests as $req)
                                <div class="flex items-start gap-1.5 text-xs">
                                    @if($req->status === 'completed')
                                        <span class="text-emerald-400 mt-0.5">&#10003;</span>
                                        <div>
                                            <span class="capitalize" style="color: var(--text-secondary);">{{ $req->party_role ?? 'Party' }}</span>
                                            <span class="text-emerald-400 font-medium">{{ $req->signer_name }}</span>
                                        </div>
                                    @elseif(in_array($req->status, ['pending', 'viewed', 'partially_signed']))
                                        <span class="mt-0.5" style="color: var(--brand-icon, #0ea5e9);">&#9993;</span>
                                        <div>
                                            <span class="capitalize" style="color: var(--text-secondary);">{{ $req->party_role ?? 'Party' }}</span>
                                            <span style="color: var(--brand-icon, #0ea5e9);">
                                                {{ $req->signer_name }}
                                                — {{ $req->status === 'viewed' ? 'viewed' : ($req->status === 'partially_signed' ? 'signing' : 'sent') }}
                                            </span>
                                        </div>
                                    @elseif($req->status === 'waiting')
                                        <span class="mt-0.5" style="color: var(--text-muted);">&#128274;</span>
                                        <div>
                                            <span class="capitalize" style="color: var(--text-muted);">{{ $req->party_role ?? 'Party' }}</span>
                                            <span style="color: var(--text-muted);">waiting</span>
                                        </div>
                                    @endif
                                </div>
                                @endforeach
                            </div>
                            @else
                                <span class="text-xs" style="color: var(--text-muted);">No signers</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-xs" style="color: var(--text-muted);">{{ $tpl->created_at->format('d M Y') }}</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex flex-col items-end gap-1">
                                @if($doc)
                                <a href="{{ route('docuperfect.signatures.sendConfirmation', $doc) }}" class="text-xs hover:underline transition-all duration-300" style="color: var(--brand-icon, #0ea5e9);">View Progress</a>
                                @endif
                                @php
                                    $activeReq = $tpl->requests->first(fn($r) => in_array($r->status, ['pending', 'viewed', 'partially_signed']));
                                @endphp
                                @if($activeReq && $doc)
                                    <form method="POST" action="{{ route('docuperfect.signatures.sendReminder', ['document' => $doc->id, 'signatureRequest' => $activeReq->id]) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-amber-500 hover:underline text-xs transition-all duration-300" onclick="return confirm('Send reminder to {{ $activeReq->signer_name }}?')">
                                            Send Reminder
                                        </button>
                                    </form>
                                @endif
                                <button type="button"
                                        @click="cancelTemplateId = {{ $tpl->id }}; cancelDocName = {{ Js::from($doc->name ?? 'Untitled') }}; showCancelModal = true"
                                        class="text-red-400 hover:underline text-xs transition-all duration-300">
                                    Cancel
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- ===== READY TO SIGN ===== --}}
    @if($groups['ready_to_sign']->isNotEmpty())
    <div id="section-ready" class="space-y-3 scroll-mt-4">
        <h3 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--brand-icon, #0ea5e9);">Ready to Sign</h3>
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <table class="w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Document</th>
                        <th class="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Template</th>
                        <th class="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Created</th>
                        <th class="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($groups['ready_to_sign'] as $tpl)
                    @php $doc = $tpl->document; @endphp
                    <tr class="transition-all duration-300" style="border-bottom: 1px solid var(--border);"
                        onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                        <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $doc->name ?? 'Untitled' }}</td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $doc->template->name ?? '-' }}</td>
                        <td class="px-4 py-3">
                            <span class="text-xs" style="color: var(--text-muted);">{{ $tpl->created_at->format('d M Y') }}</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if($doc)
                                <a href="{{ route('docuperfect.signatures.sign', $doc) }}" class="corex-btn-primary text-xs px-3 py-1">Sign Document</a>
                                @endif
                                <button type="button"
                                        @click="cancelTemplateId = {{ $tpl->id }}; cancelDocName = {{ Js::from($doc->name ?? 'Untitled') }}; showCancelModal = true"
                                        class="text-red-400 hover:underline text-xs transition-all duration-300">
                                    Cancel
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- ===== DRAFT ===== --}}
    @if($groups['draft']->isNotEmpty())
    <div id="section-draft" class="space-y-3 scroll-mt-4">
        <h3 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--text-secondary);">Draft</h3>
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <table class="w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Document</th>
                        <th class="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Template</th>
                        <th class="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Signing Progress</th>
                        <th class="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Created</th>
                        <th class="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($groups['draft'] as $tpl)
                    @php
                        $doc = $tpl->document;
                        $totalReq = $tpl->requests->count();
                        $completedReq = $tpl->requests->where('status', 'completed')->count();
                    @endphp
                    <tr class="transition-all duration-300" style="border-bottom: 1px solid var(--border);"
                        onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                        <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $doc->name ?? 'Untitled' }}</td>
                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $doc->template->name ?? '-' }}</td>
                        <td class="px-4 py-3">
                            @if($totalReq > 0)
                            <div class="flex items-center gap-2">
                                <div class="flex-1 max-w-[120px] rounded-md h-1.5" style="background: var(--surface-2);">
                                    <div class="bg-amber-500 h-1.5 rounded-md" style="width: {{ round(($completedReq / $totalReq) * 100) }}%"></div>
                                </div>
                                <span class="text-xs text-amber-500 font-medium">{{ $completedReq }}/{{ $totalReq }}</span>
                            </div>
                            @else
                                <span class="text-xs" style="color: var(--text-muted);">No signers yet</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-xs" style="color: var(--text-muted);">{{ $tpl->created_at->format('d M Y') }}</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if($doc)
                                <a href="{{ route('docuperfect.signatures.setup', $doc) }}" class="text-xs hover:underline transition-all duration-300" style="color: var(--brand-icon, #0ea5e9);">Continue Setup</a>
                                @endif
                                <button type="button"
                                        @click="cancelTemplateId = {{ $tpl->id }}; cancelDocName = {{ Js::from($doc->name ?? 'Untitled') }}; showCancelModal = true"
                                        class="text-red-400 hover:underline text-xs transition-all duration-300">
                                    Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- ===== COMPLETED (collapsed by default) ===== --}}
    @if($groups['completed']->isNotEmpty())
    <div id="section-completed" class="space-y-3 scroll-mt-4">
        <h3 class="text-sm font-semibold text-emerald-500 uppercase tracking-wider cursor-pointer transition-all duration-300"
            @click="showCompleted = !showCompleted">
            Completed ({{ $groups['completed']->count() }})
            <span class="text-xs" x-text="showCompleted ? '&#9660;' : '&#9654;'"></span>
        </h3>
        <div x-show="showCompleted" x-collapse>
            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <table class="w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Document</th>
                            <th class="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Template</th>
                            <th class="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Completed</th>
                            <th class="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($groups['completed'] as $tpl)
                        @php $doc = $tpl->document; @endphp
                        <tr class="transition-all duration-300" style="border-bottom: 1px solid var(--border);"
                            onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                            <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $doc->name ?? 'Untitled' }}</td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $doc->template->name ?? '-' }}</td>
                            <td class="px-4 py-3">
                                <span class="text-xs" style="color: var(--text-muted);">{{ $tpl->completed_at?->format('d M Y') ?? $tpl->updated_at->format('d M Y') }}</span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if($doc)
                                <a href="{{ route('docuperfect.signatures.audit', $doc) }}" class="text-xs hover:underline transition-all duration-300" style="color: var(--brand-icon, #0ea5e9);">Audit</a>
                                <a href="{{ route('docuperfect.signatures.download', $doc) }}" class="text-emerald-400 hover:underline text-xs ml-2 transition-all duration-300">Download</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif

    {{-- ===== CANCELLED (collapsed by default) ===== --}}
    @if($groups['cancelled']->isNotEmpty())
    <div id="section-cancelled" class="space-y-3 scroll-mt-4 mt-6">
        <h3 class="text-sm font-semibold uppercase tracking-wider cursor-pointer transition-all duration-300" style="color: var(--text-muted);"
            @click="showCancelled = !showCancelled">
            Cancelled ({{ $groups['cancelled']->count() }})
            <span class="text-xs" x-text="showCancelled ? '&#9660;' : '&#9654;'"></span>
        </h3>
        <div x-show="showCancelled" x-collapse class="space-y-3">
            @foreach($groups['cancelled'] as $tpl)
                @php $doc = $tpl->document; @endphp
                <div class="rounded-md p-4 opacity-75" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-start justify-between">
                        <div>
                            <h4 class="font-medium line-through" style="color: var(--text-muted);">{{ $doc->name ?? 'Untitled' }}</h4>
                            @if($doc && $doc->template)
                                <div class="text-[10px] mt-0.5" style="color: var(--text-muted);">{{ $doc->template->name }}</div>
                            @endif
                            <div class="text-xs mt-1" style="color: var(--text-muted);">
                                Cancelled {{ $tpl->updated_at->format('d M Y H:i') }}
                            </div>
                        </div>
                        @if($doc)
                        <a href="{{ route('docuperfect.signatures.audit', $doc) }}" class="text-xs hover:underline transition-all duration-300" style="color: var(--text-muted);">View</a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Empty state --}}
    @if($counts['draft'] === 0 && $counts['ready_to_sign'] === 0 && $counts['awaiting_signatures'] === 0 && $counts['completed'] === 0 && $counts['pending_approval'] === 0)
    <div class="ds-status-card p-8 text-center">
        <svg class="w-12 h-12 mx-auto mb-4" style="color: var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
        </svg>
        <h3 class="text-lg font-semibold mb-2" style="color: var(--text-secondary);">No e-sign documents yet</h3>
        <p class="text-sm mb-4" style="color: var(--text-muted);">Create your first e-sign flow to get started.</p>
        <a href="{{ route('docuperfect.esign.create') }}" class="corex-btn-primary inline-flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
            Create E-Sign Document
        </a>
    </div>
    @endif
    @endif {{-- end showOnlyAuthorisation --}}

    {{-- ===== CANCEL MODAL ===== --}}
    <div x-show="showCancelModal" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
         x-data="{ cancelReason: '', submitting: false }">
        <div class="rounded-md shadow-xl p-6 w-full max-w-md" style="background: var(--surface); border: 1px solid var(--border);" @click.away="showCancelModal = false">
            <h3 class="text-lg font-bold text-red-400 mb-4">Cancel Document</h3>
            <p class="text-sm mb-4" style="color: var(--text-secondary);">
                Cancel <strong x-text="cancelDocName"></strong>?
            </p>
            <p class="text-sm mb-4" style="color: var(--text-muted);">
                All pending signatures will be voided and waiting parties will be notified. This action cannot be undone.
            </p>

            <form method="POST" :action="'{{ url('docuperfect/esign/documents') }}/' + cancelTemplateId + '/cancel'"
                  @submit="submitting = true">
                @csrf

                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);">Reason for cancellation <span class="text-red-500">*</span></label>
                    <textarea name="cancellation_reason" x-model="cancelReason" rows="3" required
                              class="w-full rounded-md border text-sm px-3 py-2 focus:ring-red-500 focus:border-red-500"
                              style="background: var(--surface); border-color: var(--border); color: var(--text-primary);"
                              placeholder="e.g. Document contains errors, deal fell through, terms changed..."></textarea>
                    <p class="text-xs mt-1" style="color: var(--text-muted);">This reason will be shared with all waiting signers.</p>
                </div>

                <div class="flex justify-end gap-3">
                    <button type="button" @click="showCancelModal = false; cancelReason = ''"
                            class="px-4 py-2 text-sm transition-all duration-300" style="color: var(--text-secondary);">
                        Keep Document
                    </button>
                    <button type="submit"
                            :disabled="!cancelReason.trim() || submitting"
                            class="px-4 py-2 bg-red-600 text-white rounded-md text-sm font-medium hover:bg-red-700 transition-all duration-300"
                            :class="(!cancelReason.trim() || submitting) ? 'opacity-50 cursor-not-allowed' : ''">
                        <span x-show="!submitting">Cancel Document</span>
                        <span x-show="submitting" x-cloak>Cancelling...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>

{{-- Tile scroll-to-section --}}
<script>
function scrollToSection(sectionId) {
    var el = document.getElementById(sectionId);
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        el.classList.add('ring-2', 'ring-offset-2');
        el.style.setProperty('--tw-ring-color', 'var(--brand-icon, #0ea5e9)');
        setTimeout(function() {
            el.classList.remove('ring-2', 'ring-offset-2');
        }, 2000);
    }
}
</script>
@endsection
