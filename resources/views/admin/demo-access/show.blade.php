{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Demo grant detail — telemetry, acceptances, revoke/archive. Owner-only.
    Spec: .ai/specs/demo-access-control.md §9
--}}
@extends('layouts.corex')

@section('title', 'Demo grant — ' . $grant->company_name)

@section('corex-content')
@php
    // Plain-English chips, per STANDARDS F.8. §1.5 semantics: orange = invitation sent
    // but unanswered ("Not used yet"); amber = a passive lapse ("Expired"); crimson only
    // for the genuinely-blocked state. Never red for a non-danger state (strict rule 3).
    // NOT ds-badge-info — it resolves to --ds-navy, the same colour as the branded
    // header this badge sits on, and would disappear into it in light mode.
    $badgeVariant = match ($grant->status()) {
        'active'   => 'ds-badge-success',
        'pending'  => 'ds-badge-orange',
        'expired'  => 'ds-badge-warning',
        'revoked'  => 'ds-badge-danger',
        'archived' => 'ds-badge-muted',
        default    => 'ds-badge-default',
    };
@endphp
<div class="w-full space-y-5">

    {{-- Back link --}}
    <a href="{{ route('admin.demo-access.index') }}"
       class="inline-flex items-center gap-1.5 text-sm no-underline"
       style="color:var(--text-secondary);">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/>
        </svg>
        Back to Demo Access
    </a>

    {{-- Page header — §2.4 Pattern A --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <h1 class="text-xl font-bold text-white leading-tight">{{ $grant->company_name }}</h1>
                    <span class="ds-badge {{ $badgeVariant }}">{{ $grant->statusLabel() }}</span>
                </div>
                <p class="text-sm text-white/60">
                    {{ $grant->contact_email }}
                    @if ($grant->contact_name) · {{ $grant->contact_name }} @endif
                </p>
            </div>

            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('admin.demo-access.edit', $grant) }}" class="corex-btn-outline corex-btn-on-brand text-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/>
                    </svg>
                    Edit
                </a>

                @if (!$grant->revoked_at && !$grant->archived_at)
                    {{-- The confirm text states the REAL latency. The gate caches primary's
                         verdict for the TTL, so a revoke bites within that window — not
                         instantly. Promising an instant kill we cannot deliver would be a
                         lie the first time someone tested it. --}}
                    <form method="POST" action="{{ route('admin.demo-access.revoke', $grant) }}"
                          onsubmit="return confirm('Revoke access for {{ addslashes($grant->company_name) }}?\n\nThey will be locked out within {{ $cacheTtl }} seconds — not instantly. If they are mid-page right now, they may finish that page.');">
                        @csrf
                        <button type="submit" class="corex-btn-danger text-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636"/>
                            </svg>
                            Revoke
                        </button>
                    </form>
                @endif

                @if (!$grant->archived_at)
                    <form method="POST" action="{{ route('admin.demo-access.destroy', $grant) }}"
                          onsubmit="return confirm('Archive this grant?\n\nIt is hidden from the list but kept permanently as a record of who accepted which terms. Nothing is deleted.');">
                        @csrf @method('DELETE')
                        <button type="submit" class="corex-btn-outline corex-btn-on-brand text-sm">
                            Archive
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.demo-access.restore', $grant) }}">
                        @csrf
                        <button type="submit" class="corex-btn-outline corex-btn-on-brand text-sm">
                            Restore
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    @if (session('status'))
        <div role="status" class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- THE ONLY TIME THE PLAINTEXT CODE EXISTS OUTSIDE THE EMAIL.
         The DB holds bcrypt(code); after this page it is unrecoverable. That is the
         correct property for a credential, and the copy says so plainly so nobody
         goes looking for a "show code" button that cannot exist. §3.9 success alert. --}}
    @if ($plainCode)
        <div role="status" class="rounded-md px-4 py-4 text-sm"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <p class="font-semibold mb-2">Invitation sent to {{ $grant->contact_email }}. Here is the access code:</p>
            <p class="font-mono text-xl font-bold tracking-widest mb-2" style="color: var(--text-primary);">{{ $plainCode }}</p>
            <p class="text-xs" style="color: var(--text-secondary);">
                <strong>This will not be shown again.</strong> We store only a hash of it, so it
                cannot be looked up later. If it's lost, issue a new grant.
            </p>
        </div>
    @endif

    {{-- Facts — §3.2 KPI tiles. --}}
    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Access length" :value="number_format($grant->expiry_hours) . ' hours'" />
        <x-corex-kpi-card title="First sign-in" :value="$grant->first_login_at?->format('j M Y, H:i') ?? 'Not used yet'" />
        <x-corex-kpi-card title="Expires" :value="$grant->expires_at?->format('j M Y, H:i') ?? 'Starts at first sign-in'" />
        <x-corex-kpi-card title="Issued by" :value="$grant->issuer?->name ?? '—'" />
    </div>

    {{-- Secondary facts — §3.3 cards. --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xs font-medium mb-1" style="color: var(--text-secondary);">Issued</div>
            <div class="text-sm" style="color: var(--text-primary);">{{ $grant->created_at?->format('j M Y') ?? '—' }}</div>
        </div>
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xs font-medium mb-1" style="color: var(--text-secondary);">Revoked</div>
            <div class="text-sm" style="color: var(--text-primary);">{{ $grant->revoked_at?->format('j M Y, H:i') ?? '—' }}</div>
        </div>
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xs font-medium mb-1" style="color: var(--text-secondary);">Notes</div>
            <div class="text-sm whitespace-pre-wrap" style="color: var(--text-primary);">{{ $grant->notes ?: '—' }}</div>
        </div>
    </div>

    {{-- Terms accepted. Renders the body AS ACCEPTED — DemoTncVersion is immutable,
         so this is the exact text that was on their screen, even after v2 ships. --}}
    <div class="space-y-3">
        <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Terms accepted</h2>

        @forelse ($grant->acceptances as $acceptance)
            <details class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                <summary class="cursor-pointer text-sm" style="color: var(--text-primary);">
                    <strong>Version {{ $acceptance->version->version }}</strong>
                    accepted {{ $acceptance->accepted_at->format('j M Y, H:i') }}
                    @if ($acceptance->ip_address)
                        <span style="color: var(--text-muted);">from {{ $acceptance->ip_address }}</span>
                    @endif
                    @unless ($acceptance->version->isCurrent())
                        <span style="color: var(--text-muted);">· superseded</span>
                    @endunless
                </summary>
                <div class="mt-3 pt-3 text-xs leading-relaxed whitespace-pre-wrap"
                     style="color: var(--text-secondary); border-top: 1px solid var(--border);">{{ $acceptance->version->body }}</div>
            </details>
        @empty
            {{-- §3.10 empty state. No CTA: nobody can accept terms on this prospect's
                 behalf, so offering an action here would be offering a lie. --}}
            <div class="rounded-md py-8 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">Not accepted yet</h3>
                <p class="text-sm" style="color: var(--text-muted);">
                    They accept the terms the first time they sign in to the demo. Nothing to do until then.
                </p>
            </div>
        @endforelse
    </div>

    {{-- Telemetry --}}
    <div class="space-y-3">
        <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Sessions &amp; pages viewed</h2>

        @forelse ($grant->sessions as $session)
            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="px-4 py-2.5 text-xs" style="background: var(--surface-2); color: var(--text-secondary);">
                    {{ $session->started_at->format('j M Y, H:i') }}
                    · last seen {{ $session->last_seen_at->diffForHumans() }}
                    · {{ number_format($session->pageViews->count()) }} pages
                    @if ($session->ip_address) · {{ $session->ip_address }} @endif
                </div>
                @if ($session->pageViews->isNotEmpty())
                    <ul class="px-4 py-3 space-y-1">
                        @foreach ($session->pageViews as $view)
                            <li class="flex items-baseline gap-2 text-xs">
                                <span class="font-mono" style="color: var(--text-primary);">{{ $view->path }}</span>
                                <span style="color: var(--text-muted);">— {{ $view->viewed_at->format('H:i') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @empty
            <div class="rounded-md py-8 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">They haven't signed in yet</h3>
                <p class="text-sm" style="color: var(--text-muted);">
                    Once they use their code, every session and page they open is listed here.
                </p>
            </div>
        @endforelse
    </div>
</div>
@endsection
