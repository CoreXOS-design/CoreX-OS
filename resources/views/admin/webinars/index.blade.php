{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Webinars list. Owner-only.
    Spec: .ai/specs/webinar-registration.md §7.2
--}}
@extends('layouts.corex')

@section('title', 'Webinars')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header — §2.4 Pattern A --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Webinars</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    Registration links for the CoreX website. Everyone who signs up gets their joining
                    details and a demo login in one email.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.demo-access.index') }}" class="corex-btn-outline corex-btn-on-brand text-xs">
                    Demo Access
                </a>
                <a href="{{ route('admin.webinars.create') }}" class="corex-btn-primary text-xs">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    New webinar
                </a>
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

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        @if ($webinars->isEmpty())
            {{-- Empty state — §4.2: say what this screen is for and offer the one action. --}}
            <div class="px-6 py-12 text-center">
                <p class="text-sm font-medium" style="color: var(--text-primary);">No webinars yet</p>
                <p class="mt-1 text-xs max-w-md mx-auto" style="color: var(--text-muted);">
                    Create one to get a registration link. You give that link to whoever builds the
                    page on the CoreX website, and sign-ups appear here.
                </p>
                <a href="{{ route('admin.webinars.create') }}" class="corex-btn-primary text-xs mt-4 inline-flex">
                    New webinar
                </a>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr style="background: var(--surface-2); border-bottom: 1px solid var(--border);">
                            <th class="text-left font-medium px-4 py-3 text-xs" style="color: var(--text-secondary);">Webinar</th>
                            <th class="text-left font-medium px-4 py-3 text-xs" style="color: var(--text-secondary);">When</th>
                            <th class="text-left font-medium px-4 py-3 text-xs" style="color: var(--text-secondary);">Demo access ends</th>
                            <th class="text-left font-medium px-4 py-3 text-xs" style="color: var(--text-secondary);">Status</th>
                            <th class="text-right font-medium px-4 py-3 text-xs" style="color: var(--text-secondary);">Registered</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($webinars as $webinar)
                            <tr style="border-bottom: 1px solid var(--border);">
                                <td class="px-4 py-3">
                                    <a href="{{ route('admin.webinars.show', $webinar) }}"
                                       class="font-medium hover:underline" style="color: var(--corex-accent);">
                                        {{ $webinar->title }}
                                    </a>
                                    <div class="text-xs mt-0.5" style="color: var(--text-muted);">/{{ $webinar->slug }}</div>
                                </td>
                                <td class="px-4 py-3" style="color: var(--text-primary);">
                                    {{ $webinar->starts_at->format('j M Y') }}
                                    <div class="text-xs" style="color: var(--text-muted);">{{ $webinar->starts_at->format('H:i') }}</div>
                                </td>
                                <td class="px-4 py-3" style="color: var(--text-primary);">
                                    {{ $webinar->demoAccessEndsAt()->format('j M Y') }}
                                </td>
                                <td class="px-4 py-3">
                                    {{-- Plain-English chip — §F.8, never a raw flag. --}}
                                    @php
                                        $open = $webinar->isOpenForRegistration();
                                        $tone = $webinar->isArchived() ? 'var(--text-muted)' : ($open ? 'var(--ds-emerald)' : 'var(--ds-amber)');
                                    @endphp
                                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium"
                                          style="background: color-mix(in srgb, {{ $tone }} 12%, transparent);
                                                 color: {{ $tone }};">
                                        {{ $webinar->statusLabel() }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right font-medium" style="color: var(--text-primary);">
                                    {{ number_format($webinar->registrations_count) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if ($webinars->hasPages())
        <div>{{ $webinars->links() }}</div>
    @endif

</div>
@endsection
