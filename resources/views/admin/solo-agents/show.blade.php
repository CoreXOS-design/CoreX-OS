{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md - Single agent detail (owner-only). Spec: solo-agent-accounts.md section 6. --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $st = $signup->displayStatus();
    $badge = ['awaiting_contract' => 'ds-badge-warning', 'active' => 'ds-badge-success', 'suspended' => 'ds-badge-danger', 'archived' => 'ds-badge-default'];
    $card = 'rounded-md p-5';
    $cardStyle = 'background: var(--surface); border: 1px solid var(--border);';
    $dt = 'text-xs font-medium'; $dd = 'text-sm';
    $docOpen = $doc && in_array($doc->status, ['sent', 'in_progress', 'expired'], true);
@endphp
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $signup->agency?->name }}
                    <span class="ds-badge {{ $badge[$st] }} ml-2 align-middle">{{ $signup->displayStatusLabel() }}</span></h1>
                <p class="text-xs" style="color: var(--text-muted);">Single agent: {{ $signup->user?->name }} · signed up {{ $signup->created_at?->format('j M Y') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.solo-agents.index') }}" class="corex-btn-outline text-xs">Back to Solo Agents</a>
                @if(! $signup->trashed())
                    @permission('solo_agents.manage')
                    <a href="{{ route('admin.solo-agents.edit', $signup->id) }}" class="corex-btn-outline text-xs">Edit details</a>
                    @endpermission
                @endif
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm font-medium" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ session('error') }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

        {{-- Sign-up details --}}
        <div class="{{ $card }}" style="{{ $cardStyle }}">
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Sign-up details</h2>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-3">
                <div><dt class="{{ $dt }}" style="color: var(--text-muted);">Agency name</dt><dd class="{{ $dd }}" style="color: var(--text-primary);">{{ $signup->agency?->name }}</dd></div>
                <div><dt class="{{ $dt }}" style="color: var(--text-muted);">Trading name</dt><dd class="{{ $dd }}" style="color: var(--text-primary);">{{ $signup->agency?->trading_name ?: '—' }}</dd></div>
                <div><dt class="{{ $dt }}" style="color: var(--text-muted);">Agent</dt><dd class="{{ $dd }}" style="color: var(--text-primary);">{{ $signup->user?->name }}</dd></div>
                <div><dt class="{{ $dt }}" style="color: var(--text-muted);">Email (login)</dt><dd class="{{ $dd }}" style="color: var(--text-primary);">{{ $signup->user?->email }}</dd></div>
                <div><dt class="{{ $dt }}" style="color: var(--text-muted);">Mobile</dt><dd class="{{ $dd }}" style="color: var(--text-primary);">{{ $signup->user?->cell ?: '—' }}</dd></div>
                <div><dt class="{{ $dt }}" style="color: var(--text-muted);">Does</dt><dd class="{{ $dd }}" style="color: var(--text-primary);">{{ ucfirst($signup->agency?->agency_type ?: 'both') }}</dd></div>
                <div><dt class="{{ $dt }}" style="color: var(--text-muted);">FFC number</dt><dd class="{{ $dd }}" style="color: var(--text-primary);">{{ $signup->ffc_number ?: '—' }}</dd></div>
                <div><dt class="{{ $dt }}" style="color: var(--text-muted);">FFC expires</dt><dd class="{{ $dd }}" style="color: var(--text-primary);">{{ $signup->ffc_expires_on?->format('j M Y') ?: '—' }}</dd></div>
            </dl>
        </div>

        {{-- Contract --}}
        <div class="{{ $card }}" style="{{ $cardStyle }}">
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Agreement</h2>
            <p class="text-sm mb-3" style="color: var(--text-secondary);">
                @if($signup->status === 'active')
                    Signed {{ $signup->contract_signed_at?->format('j M Y') }}. The login is open.
                @elseif($docOpen)
                    Sent {{ $signup->contract_sent_at?->format('j M Y') }} — waiting for {{ $signup->user?->name }} to sign.
                @elseif($doc)
                    The last agreement is {{ strtolower(\App\Models\PlatformEsign\Document::STATUSES[$doc->status] ?? $doc->status) }}. Send a new one.
                @else
                    Not sent yet. The login stays closed until the agreement is signed.
                @endif
            </p>
            @if($doc)
                <p class="text-xs mb-3"><a class="underline" style="color: var(--text-secondary);" href="{{ route('platform-esign.documents.show', $doc->id) }}">Open the agreement in Platform E-Sign</a></p>
            @endif

            @if(! $signup->trashed())
                @permission('solo_agents.manage')
                @if($signup->status !== 'active')
                    @if($templates->isEmpty() && ! $docOpen)
                        <p class="text-sm rounded-md px-3 py-2" style="background: var(--surface-2); color: var(--text-secondary);">
                            There is no single-agent agreement to send yet. Create it in
                            <a class="underline" href="{{ route('platform-esign.templates.create') }}">Platform E-Sign → Templates</a>
                            and choose the type “Single-agent agreement”. It states {{ \App\Support\Money\Zar::formatWhole((float) config('solo-agent.monthly_fee_zar', 450)) }} a month
                            (the wording can use the fields @{{ monthly_fee }}, @{{ agent_name }} and @{{ agent_ffc_number }}).
                        </p>
                    @else
                        <form method="POST" action="{{ route('admin.solo-agents.send-contract', $signup->id) }}" class="flex flex-wrap items-center gap-2">
                            @csrf
                            @if(! $docOpen && $templates->count() > 1)
                                <select name="template_id" class="list-header-filter" aria-label="Agreement">
                                    @foreach($templates as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                                </select>
                            @endif
                            <button class="corex-btn-primary text-sm">{{ $docOpen ? 'Resend the agreement' : 'Send the agreement' }}</button>
                        </form>
                    @endif
                @else
                    @if($signup->user?->isPendingInvite())
                        <form method="POST" action="{{ route('admin.solo-agents.resend-setup', $signup->id) }}">@csrf
                            <button class="corex-btn-outline text-sm">Resend the password-setup email</button>
                        </form>
                        <p class="text-xs mt-2" style="color: var(--text-muted);">They have not set a password yet.</p>
                    @else
                        <p class="text-xs" style="color: var(--text-muted);">They have set their password and can sign in.</p>
                    @endif
                @endif
                @endpermission
            @endif
        </div>

        {{-- Usage + price --}}
        <div class="{{ $card }}" style="{{ $cardStyle }}">
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Usage and price</h2>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-3">
                <div><dt class="{{ $dt }}" style="color: var(--text-muted);">Listings</dt><dd class="{{ $dd }}" style="color: var(--text-primary);">{{ number_format($listings) }}</dd></div>
                <div><dt class="{{ $dt }}" style="color: var(--text-muted);">Deals</dt><dd class="{{ $dd }}" style="color: var(--text-primary);">{{ number_format($deals) }}</dd></div>
                <div><dt class="{{ $dt }}" style="color: var(--text-muted);">AI templates this month</dt><dd class="{{ $dd }}" style="color: var(--text-primary);">{{ $aiUsed }} of {{ $aiLimit }}</dd></div>
                <div><dt class="{{ $dt }}" style="color: var(--text-muted);">Monthly price</dt><dd class="{{ $dd }}" style="color: var(--text-primary);">
                    @if($signup->status === 'active') {{ \App\Support\Money\Zar::formatWhole($quote->payableZar) }} <span class="text-xs" style="color: var(--text-muted);">({{ $quote->planLabel }})</span>
                    @else Starts when the agreement is signed @endif
                </dd></div>
            </dl>
            <p class="text-xs mt-3" style="color: var(--text-muted);">The AI template allowance and any custom price are changed by the owner under AI Usage and Agency Billing.</p>
        </div>

        {{-- Account actions --}}
        <div class="{{ $card }}" style="{{ $cardStyle }}">
            <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Account</h2>
            @if($signup->trashed())
                <p class="text-sm mb-3" style="color: var(--text-secondary);">This account is archived: the login is closed and billing has stopped. Nothing was deleted.</p>
                @permission('solo_agents.archive')
                <form method="POST" action="{{ route('admin.solo-agents.restore', $signup->id) }}" class="flex flex-wrap gap-2">@csrf
                    <input type="text" name="reason" class="list-header-filter flex-1" placeholder="Reason (optional)" maxlength="500">
                    <button class="corex-btn-primary text-sm">Restore</button>
                </form>
                @endpermission
            @else
                @permission('solo_agents.manage')
                @if($st === 'suspended')
                    <form method="POST" action="{{ route('admin.solo-agents.unsuspend', $signup->id) }}" class="flex flex-wrap gap-2 mb-3">@csrf
                        <input type="text" name="reason" class="list-header-filter flex-1" placeholder="Reason (optional)" maxlength="500">
                        <button class="corex-btn-primary text-sm">Unsuspend</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.solo-agents.suspend', $signup->id) }}" class="flex flex-wrap gap-2 mb-3" onsubmit="return confirm('Suspend this account? The agent will not be able to sign in.');">@csrf
                        <input type="text" name="reason" class="list-header-filter flex-1" placeholder="Reason (optional)" maxlength="500">
                        <button class="corex-btn-outline text-sm">Suspend</button>
                    </form>
                @endif
                @endpermission
                @permission('solo_agents.archive')
                <form method="POST" action="{{ route('admin.solo-agents.archive', $signup->id) }}" class="flex flex-wrap gap-2" onsubmit="return confirm('Archive this account? Their login closes and billing stops. Nothing is deleted and you can restore it.');">@csrf
                    <input type="text" name="reason" class="list-header-filter flex-1" placeholder="Reason (optional)" maxlength="500">
                    <button class="corex-btn-outline text-sm" style="color: var(--ds-crimson);">Archive</button>
                </form>
                @endpermission
            @endif
        </div>
    </div>

    {{-- Timeline --}}
    <div class="{{ $card }}" style="{{ $cardStyle }}">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Timeline</h2>
        <ul class="space-y-2">
            @forelse($events as $e)
                <li class="text-sm" style="color: var(--text-secondary);">
                    <span class="tabular-nums" style="color: var(--text-muted);">{{ $e->created_at?->format('j M Y H:i') }}</span>
                    — {{ ucfirst(str_replace('_', ' ', $e->event)) }}@if($e->actor) by {{ $e->actor->name }} @endif @if($e->reason) — {{ $e->reason }} @endif
                </li>
            @empty
                <li class="text-sm" style="color: var(--text-muted);">Nothing recorded yet.</li>
            @endforelse
        </ul>
    </div>
</div>
@endsection
