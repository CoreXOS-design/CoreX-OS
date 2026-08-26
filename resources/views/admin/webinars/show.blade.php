{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    A webinar and everyone who registered for it. Owner-only.
    Spec: .ai/specs/webinar-registration.md §7.2

    THIS IS THE ONLY PLACE REGISTRANTS EXIST. Johan's decision (§0 A5): webinar
    registrants deliberately do not become Contacts, so this list and its CSV export
    are the whole sales record. The empty state and the export button matter more here
    than they would on a screen that had a CRM behind it.
--}}
@extends('layouts.corex')

@section('title', $webinar->title)

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $webinar->title }}</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    {{ $webinar->starts_at->format('l, j F Y') }} at {{ $webinar->starts_at->format('H:i') }}
                    &middot; {{ $webinar->statusLabel() }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.webinars.index') }}" class="corex-btn-outline corex-btn-on-brand text-xs">Back</a>
                <a href="{{ route('admin.webinars.edit', $webinar) }}" class="corex-btn-outline corex-btn-on-brand text-xs">Edit</a>
                @if ($registrations->total() > 0)
                    <a href="{{ route('admin.webinars.export', $webinar) }}" class="corex-btn-outline corex-btn-on-brand text-xs">
                        Export CSV
                    </a>
                @endif
                @if ($webinar->isArchived())
                    <form method="POST" action="{{ route('admin.webinars.restore', $webinar) }}">
                        @csrf
                        <button type="submit" class="corex-btn-outline corex-btn-on-brand text-xs">Restore</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.webinars.destroy', $webinar) }}"
                          onsubmit="return confirm('Archive this webinar? The registration link stops working immediately — nobody else can sign up or be given demo access. Everyone who already registered keeps theirs.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="corex-btn-outline corex-btn-on-brand text-xs">Archive</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    @if (session('status'))
        <div role="status" class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-emerald) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-emerald) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    {{-- KPI row — §3.8 --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <x-corex-kpi-card title="Registered" :value="number_format($registrations->total())" />
        <x-corex-kpi-card title="Demo access ends" :value="$webinar->demoAccessEndsAt()->format('j M Y')" />
        <x-corex-kpi-card title="Reminder goes out" :value="$webinar->reminderDueAt()->format('j M, H:i')" />
    </div>

    {{-- The link to hand over. This is the single thing this screen exists to produce
         for the website team, so it gets its own card rather than a line of small
         print somewhere below the fold. --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Registration link</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">
            Give this to whoever builds the registration page on the CoreX website. Their form
            posts to it; we do the rest.
        </p>
        <div class="rounded-md px-3 py-2 text-xs font-mono break-all"
             style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            {{ $webinar->registrationEndpoint() }}
        </div>
        @if (! $webinar->isOpenForRegistration())
            <p class="mt-2 text-xs" style="color: var(--ds-amber);">
                This link is closed — the webinar has started or been archived. Sign-ups are
                turned away and no new demo logins are issued.
            </p>
        @endif
    </div>

    {{-- Registrations --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        @if ($registrations->isEmpty())
            <div class="px-6 py-12 text-center">
                <p class="text-sm font-medium" style="color: var(--text-primary);">Nobody has registered yet</p>
                <p class="mt-1 text-xs max-w-md mx-auto" style="color: var(--text-muted);">
                    Sign-ups appear here as they come in. Remember these people aren't added to
                    Contacts — this list is the record.
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr style="background: var(--surface-2); border-bottom: 1px solid var(--border);">
                            <th class="text-left font-medium px-4 py-3 text-xs" style="color: var(--text-secondary);">Name</th>
                            <th class="text-left font-medium px-4 py-3 text-xs" style="color: var(--text-secondary);">Company</th>
                            <th class="text-left font-medium px-4 py-3 text-xs" style="color: var(--text-secondary);">Contact</th>
                            <th class="text-left font-medium px-4 py-3 text-xs" style="color: var(--text-secondary);">Registered</th>
                            <th class="text-left font-medium px-4 py-3 text-xs" style="color: var(--text-secondary);">Demo access</th>
                            <th class="text-left font-medium px-4 py-3 text-xs" style="color: var(--text-secondary);">Reminder</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($registrations as $r)
                            <tr style="border-bottom: 1px solid var(--border);">
                                <td class="px-4 py-3" style="color: var(--text-primary);">{{ $r->name }}</td>
                                <td class="px-4 py-3" style="color: var(--text-primary);">{{ $r->company_name }}</td>
                                <td class="px-4 py-3">
                                    <div style="color: var(--text-primary);">{{ $r->email }}</div>
                                    @if ($r->phone)
                                        <div class="text-xs" style="color: var(--text-muted);">{{ $r->phone }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">
                                    {{ $r->created_at?->format('j M Y, H:i') }}
                                </td>
                                <td class="px-4 py-3">
                                    @php
                                        $label = $r->accessStatusLabel();
                                        $tone  = match ($label) {
                                            'Active'      => 'var(--ds-emerald)',
                                            'Not used yet'=> 'var(--corex-accent)',
                                            'Expired'     => 'var(--text-muted)',
                                            'Revoked'     => 'var(--ds-crimson)',
                                            default       => 'var(--text-muted)',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium"
                                          style="background: color-mix(in srgb, {{ $tone }} 12%, transparent); color: {{ $tone }};">
                                        {{ $label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">
                                    {{ $r->reminder_sent_at ? $r->reminder_sent_at->format('j M, H:i') : 'Not yet' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if ($registrations->hasPages())
        <div>{{ $registrations->links() }}</div>
    @endif

</div>
@endsection
