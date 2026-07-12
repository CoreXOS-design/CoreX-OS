{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Demo Access Control — grant list. Owner-only.
    Spec: .ai/specs/demo-access-control.md §9
--}}
@extends('layouts.corex')

@section('title', 'Demo Access')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header — §2.4 Pattern A (branded, full-bleed, one primary action). --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Demo Access</h1>
                <p class="text-sm text-white/60">
                    Time-boxed, company-attributed access to demo1.corexos.co.za.
                    Next demo reset {{ $nextReset->format('D j M, H:i') }} — the demo rebuilds every 3 days.
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('admin.demo-access.connection') }}" class="corex-btn-outline corex-btn-on-brand text-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244"/>
                    </svg>
                    Demo connection
                </a>
                <a href="{{ route('admin.demo-access.tnc') }}" class="corex-btn-outline corex-btn-on-brand text-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5A3.375 3.375 0 0 0 10.125 2.25H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
                    </svg>
                    Terms &amp; Conditions
                </a>
                <a href="{{ route('admin.demo-access.create') }}" class="corex-btn-primary text-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    New grant
                </a>
            </div>
        </div>
    </div>

    {{-- The three facts that decide whether the demo works at all. Buried in button
         chrome before; a status is only useful when it is visible (STANDARDS — Status
         Always Visible). §3.2 --}}
    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Grants issued" :value="number_format($grants->total())" />
        <x-corex-kpi-card title="Terms in use" :value="$tncVersion ? 'Version ' . $tncVersion->version : '—'" />
        <x-corex-kpi-card title="Demo connection" :value="$connector ? 'Connected' : 'Not set up'" />
    </div>

    {{-- No T&C published = the clickwrap has nothing to show and EVERY prospect is
         hard-blocked at the gate. Surface it loudly here rather than discovering it
         when a prospect calls. §3.9 danger alert — a genuine blocked state. --}}
    @unless ($tncVersion)
        <div role="alert" class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson, #c41e3a);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
            </svg>
            <div class="flex-1">
                <strong>No terms published.</strong> Until a version exists, nobody can get past the
                demo's terms screen — every prospect is blocked.
            </div>
            <a href="{{ route('admin.demo-access.tnc') }}" class="text-xs font-semibold flex-shrink-0"
               style="color: var(--ds-crimson, #c41e3a);">Publish version 1</a>
        </div>
    @endunless

    {{-- Same class of failure, same loudness: no connector = the demo cannot ask us
         whether a code is real, and the gate fails closed. §3.9 --}}
    @unless ($connector)
        <div role="alert" class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson, #c41e3a);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244"/>
            </svg>
            <div class="flex-1">
                <strong>No demo connection.</strong> The demo cannot reach this system, so nobody can
                sign in to it — the gate fails closed by design.
            </div>
            <a href="{{ route('admin.demo-access.connection') }}" class="text-xs font-semibold flex-shrink-0"
               style="color: var(--ds-crimson, #c41e3a);">Set it up</a>
        </div>
    @endunless

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

    {{-- Filter bar — §3.8. Search grows, filter is fixed width, count is always
         visible, Clear appears only when a filter is active. --}}
    <div class="rounded-md px-4 py-3" style="background:var(--surface); border:1px solid var(--border);">
        <form method="GET" action="{{ route('admin.demo-access.index') }}" class="flex flex-wrap items-center gap-3">

            <div class="relative flex-1 min-w-[180px] max-w-xs">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none"
                     style="color:var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text" name="q" value="{{ $search }}"
                       placeholder="Search company or email…"
                       class="w-full pl-10 pr-3 py-2 text-sm rounded-md transition-all duration-300"
                       style="border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); outline:none;">
            </div>

            <label class="list-header-filter inline-flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="archived" value="1" {{ $showArchived ? 'checked' : '' }}
                       onchange="this.form.submit()"
                       class="rounded" style="accent-color:var(--brand-button, #0ea5e9);">
                Show archived
            </label>

            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>

            @if ($search !== '' || $showArchived)
                <a href="{{ route('admin.demo-access.index') }}" class="text-xs underline transition-all duration-300"
                   style="color:var(--text-muted);">Clear</a>
            @endif

            <span class="ml-auto text-xs" style="color:var(--text-muted);">
                Showing {{ number_format($grants->count()) }} of {{ number_format($grants->total()) }}
            </span>
        </form>
    </div>

    @if ($grants->isEmpty())
        {{-- Empty state — §3.10. Distinct copy for "filtered to nothing" vs "none yet",
             because the next step is different in each case. --}}
        <div class="rounded-md py-12 px-6 text-center" style="background:var(--surface); border:1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z"/>
                </svg>
            </div>

            @if ($search !== '' || $showArchived)
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No grants match this search</h3>
                <p class="text-sm mb-4" style="color: var(--text-muted);">Nothing here matches what you typed. Clear the search to see every grant.</p>
                <a href="{{ route('admin.demo-access.index') }}" class="corex-btn-primary text-sm">Clear search</a>
            @else
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No demo grants yet</h3>
                <p class="text-sm mb-4" style="color: var(--text-muted);">Issue a grant to give a prospect time-boxed access to the demo. They get an emailed code; the clock starts when they first sign in.</p>
                <a href="{{ route('admin.demo-access.create') }}" class="corex-btn-primary text-sm">Issue the first grant</a>
            @endif
        </div>
    @else
        {{-- Table — §3.7. Container div carries the radius/border; pagination lives
             inside it, below the table, separated by a border-top (§3.16). --}}
        <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Company</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Email</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Status</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider hidden md:table-cell" style="color:var(--text-muted);">First login</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider hidden md:table-cell" style="color:var(--text-muted);">Expires</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Sessions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($grants as $grant)
                        @php
                            // Plain-English chips, per STANDARDS F.8 — never the raw enum.
                            // §1.5 semantics exactly: orange = invitation sent but unanswered
                            // ("Not used yet"); amber = a passive lapse ("Expired"); crimson only
                            // for the one genuinely-blocked state. Never red for a non-danger
                            // state (strict rule 3). NOT ds-badge-info here — it resolves to
                            // --ds-navy, the same colour as the branded header behind it.
                            $badgeVariant = match ($grant->status()) {
                                'active'   => 'ds-badge-success',
                                'pending'  => 'ds-badge-orange',
                                'expired'  => 'ds-badge-warning',
                                'revoked'  => 'ds-badge-danger',
                                'archived' => 'ds-badge-muted',
                                default    => 'ds-badge-default',
                            };
                        @endphp
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.demo-access.show', $grant) }}"
                                   class="font-semibold" style="color:var(--brand-icon, #0ea5e9);">
                                    {{ $grant->company_name }}
                                </a>
                            </td>
                            <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $grant->contact_email }}</td>
                            <td class="px-4 py-3">
                                <span class="ds-badge {{ $badgeVariant }}">{{ $grant->statusLabel() }}</span>
                            </td>
                            <td class="px-4 py-3 hidden md:table-cell" style="color:var(--text-secondary);">
                                {{ $grant->first_login_at?->format('j M Y, H:i') ?? '—' }}
                            </td>
                            <td class="px-4 py-3 hidden md:table-cell" style="color:var(--text-secondary);">
                                {{-- NULL until first login. "—" is the honest render; a date here
                                     would be a guess about when they will open the email. --}}
                                {{ $grant->expires_at?->format('j M Y, H:i') ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right" style="color:var(--text-secondary);">
                                {{ number_format($grant->sessions_count) }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if ($grants->hasPages())
                <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                    {{ $grants->links() }}
                </div>
            @endif
        </div>
    @endif
</div>
@endsection
