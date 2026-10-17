{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — Dev Settings → Article Library → Settings (AT-456 Part C). --}}
@extends('layouts.corex')

@section('corex-content')
@php $input = 'background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);'; @endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Article Library — settings</h1>
                <p class="text-sm text-white/60">How often the library refreshes by itself, and how many new articles one run may write.</p>
            </div>
            <a href="{{ route('admin.dev-settings.index') }}" class="corex-btn-outline">← Dev Settings</a>
        </div>
    </div>

    @include('admin.partials.platform-flash')
    <div>@include('admin.article-library._tabs')</div>

    <form method="POST" action="{{ route('admin.article-library.settings.update') }}" class="rounded-md p-5 space-y-5 max-w-2xl" style="background:var(--surface); border:1px solid var(--border);">
        @csrf @method('PUT')

        <fieldset class="space-y-2">
            <legend class="text-sm font-semibold mb-1" style="color:var(--text-primary);">Refresh automatically</legend>
            @foreach($schedules as $value => $label)
                <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                    <input type="radio" name="schedule" value="{{ $value }}" @checked(old('schedule', $settings['schedule']) === $value)>
                    {{ $label }}
                </label>
            @endforeach
            <p class="text-xs" style="color:var(--text-muted);">The nightly run happens at 02:30 server time. “Run now” on the Sources tab always works, whatever this says. Every run uses platform AI (never an agency's) and counts toward the platform's AI Spend Safety limits.</p>
        </fieldset>

        <label class="block">
            <span class="block text-sm font-semibold mb-1" style="color:var(--text-primary);">Most new articles per run</span>
            <input type="number" name="max_per_run" min="1" max="{{ $maxLimit }}" value="{{ old('max_per_run', $settings['max_per_run']) }}"
                   class="w-28 rounded-md px-3 py-2 text-sm" style="{{ $input }}">
            <span class="block text-xs mt-1" style="color:var(--text-muted);">1 to {{ $maxLimit }}. This caps the AI spend of a single run (roughly R0.20–R0.40 per article).</span>
        </label>

        <div><button type="submit" class="corex-btn-primary">Save settings</button></div>
    </form>
</div>
@endsection
