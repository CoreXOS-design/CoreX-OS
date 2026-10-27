{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — System Developer → Article Library → Settings (AT-456 Part C). --}}
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

        <fieldset class="space-y-3 pt-4" style="border-top:1px solid var(--border);">
            <legend class="text-sm font-semibold" style="color:var(--text-primary);">Original wording</legend>
            <p class="text-xs" style="color:var(--text-muted);">Every brief is checked against the news text it was written from. One that repeats the source's own sentences is rewritten once, and if it still does, it is thrown away and the item is skipped. Names, figures and short phrases are fine; whole sentences are not.</p>

            <label class="block">
                <span class="block text-sm font-semibold mb-1" style="color:var(--text-primary);">Most wording allowed in common with the source (%)</span>
                <input type="number" name="originality_max_overlap_pct" min="0" max="100" step="1" value="{{ old('originality_max_overlap_pct', round($originality['max_overlap'] * 100, 2)) }}"
                       class="w-28 rounded-md px-3 py-2 text-sm" style="{{ $input }}">
                <span class="block text-xs mt-1" style="color:var(--text-muted);">What this changes: the share of the brief that may be the same six-word stretches as the source. Above this, the brief is rewritten or skipped.</span>
            </label>

            <label class="block">
                <span class="block text-sm font-semibold mb-1" style="color:var(--text-primary);">Longest stretch copied word-for-word (words)</span>
                <input type="number" name="originality_max_run_words" min="{{ \App\Services\Articles\ArticleLibraryService::ORIG_RUN_MIN }}" max="{{ \App\Services\Articles\ArticleLibraryService::ORIG_RUN_MAX }}" value="{{ old('originality_max_run_words', $originality['max_run_words']) }}"
                       class="w-28 rounded-md px-3 py-2 text-sm" style="{{ $input }}">
                <span class="block text-xs mt-1" style="color:var(--text-muted);">What this changes: a brief that repeats this many words in a row from the source fails, however little else it shares.</span>
            </label>

            <label class="block">
                <span class="block text-sm font-semibold mb-1" style="color:var(--text-primary);">Rewrite attempts before an item is skipped</span>
                <input type="number" name="originality_retries" min="0" max="{{ \App\Services\Articles\ArticleLibraryService::ORIG_RETRIES_MAX }}" value="{{ old('originality_retries', $originality['retries']) }}"
                       class="w-28 rounded-md px-3 py-2 text-sm" style="{{ $input }}">
                <span class="block text-xs mt-1" style="color:var(--text-muted);">What this changes: how many extra times the AI is asked to rewrite a brief that copied the source. Each costs about the same as a brief. 0 means skip straight away.</span>
            </label>
        </fieldset>

        <div><button type="submit" class="corex-btn-primary">Save settings</button></div>
    </form>
</div>
@endsection
