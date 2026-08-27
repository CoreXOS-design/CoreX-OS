{{-- MIC funnel phase 2 (Johan 2026-08-13) — agency stale-claim WARN/RELEASE threshold settings. --}}
@extends('layouts.corex')

@section('corex-content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <a href="{{ route('settings.prospecting.index') }}" class="inline-flex items-center gap-1 text-xs no-underline" style="color: rgba(255,255,255,0.7);">← Back to Prospecting Setup</a>
        <h1 class="text-xl font-bold text-white leading-tight mt-1">Stale-claim rules</h1>
        <p class="text-sm text-white/60">How long a pitched/claimed property may sit unworked before the agent is warned, then it goes to your Stale claims review for a move-or-keep decision. Working the property resets the timer.</p>
    </div>

    @if(session('status'))
        <div class="rounded-md px-3 py-2 text-sm" style="background:#f0fdf4; color:#166534;">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-3 py-2 text-sm" style="background:#fef2f2; color:#991b1b;">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('settings.prospecting.stale-rules.update') }}"
          class="rounded-md p-6 space-y-4" style="background:var(--surface); border:1px solid var(--border);">
        @csrf @method('PUT')
        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">Warn the agent after (days)</label>
            <input type="number" name="claim_warn_days" min="1" max="365" required value="{{ old('claim_warn_days', $warnDays) }}"
                   class="w-32 px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <p class="text-xs mt-1" style="color:var(--text-muted);">The agent on it is notified so they can react. Suggested: 7.</p>
        </div>
        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">Send to manager review after (days)</label>
            <input type="number" name="claim_release_days" min="1" max="365" required value="{{ old('claim_release_days', $releaseDays) }}"
                   class="w-32 px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <p class="text-xs mt-1" style="color:var(--text-muted);">Must be ≥ warn days. The BM/admin then reassigns or keeps it — agents can't grab it. Suggested: 10.</p>
        </div>
        {{-- Audit 2026-08-27 — the MIC tile-count cache window was shipped as a
             column + allow-list entry with no control anywhere, so nothing could
             ever set it. Same MIC settings surface, same form, same save. --}}
        <div class="pt-4 mt-2" style="border-top:1px solid var(--border);">
            <h2 class="text-sm font-bold mb-1" style="color:var(--text-primary);">Market Intelligence tile counts</h2>
            <p class="text-xs mb-3" style="color:var(--text-muted);">
                How long the count on each Market Intelligence tile (My Claims, Log outcomes, Expiring) may be reused
                before it is recalculated. Claiming, releasing or closing a claim always updates the tiles straight
                away regardless of these — they only bound how quickly other changes show up. Leave them alone unless
                the tiles feel slow.
            </p>
        </div>
        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">Reuse a count for (seconds)</label>
            <input type="number" name="mic_counts_cache_fresh_seconds" min="1" max="3600" required value="{{ old('mic_counts_cache_fresh_seconds', $countsFresh) }}"
                   class="w-32 px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <p class="text-xs mt-1" style="color:var(--text-muted);">Within this window the tile shows its last figure without recalculating. Suggested: 60.</p>
        </div>
        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">Stop reusing it after (seconds)</label>
            <input type="number" name="mic_counts_cache_stale_seconds" min="1" max="3600" required value="{{ old('mic_counts_cache_stale_seconds', $countsStale) }}"
                   class="w-32 px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <p class="text-xs mt-1" style="color:var(--text-muted);">Must be ≥ the reuse window. Past this the tile always recalculates before it is shown. Suggested: 300.</p>
        </div>
        <div>
            <button type="submit" class="text-sm font-semibold px-5 py-2 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Save rules</button>
        </div>
    </form>
</div>
@endsection
