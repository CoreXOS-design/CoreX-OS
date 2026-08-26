{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Create a webinar registration link. Owner-only.
    Spec: .ai/specs/webinar-registration.md §7.2
--}}
@extends('layouts.corex')

@section('title', 'New webinar')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">New webinar</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    We'll give you a registration link to hand to whoever builds the page on the
                    CoreX website. Everyone who signs up gets one email with their joining details,
                    a calendar invite and a demo login.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.webinars.index') }}" class="corex-btn-outline corex-btn-on-brand text-xs">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/>
                    </svg>
                    Back to Webinars
                </a>
            </div>
        </div>
    </div>

    @if ($errors->any())
        <div role="alert" class="rounded-md px-4 py-3 text-sm max-w-3xl"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Constrained column — a text field the width of a 27" monitor is not a better
         text field. --}}
    <form method="POST" action="{{ route('admin.webinars.store') }}" class="max-w-3xl space-y-4">
        @csrf

        @include('admin.webinars._form', ['webinar' => null])

        <div class="flex items-center gap-2">
            <button type="submit" class="corex-btn-primary text-xs">Create webinar</button>
            <a href="{{ route('admin.webinars.index') }}" class="corex-btn-outline text-xs">Cancel</a>
        </div>
    </form>

</div>
@endsection
