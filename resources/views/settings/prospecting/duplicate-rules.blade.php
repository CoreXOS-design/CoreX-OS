{{-- Deeds-capture duplicate-match take rule (Johan, 2026-08-21) — agency-configurable off-market-age bands. --}}
@extends('layouts.corex')

@section('corex-content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <a href="{{ route('settings.prospecting.index') }}" class="inline-flex items-center gap-1 text-xs no-underline" style="color: rgba(255,255,255,0.7);">← Back to Prospecting Setup</a>
        <h1 class="text-xl font-bold text-white leading-tight mt-1">Duplicate-property take rules</h1>
        <p class="text-sm text-white/60">When a deeds capture matches a property already in the system, how old that property's off-market age must be before an agent may take it. Active stock is never affected — this only applies once a property is off the market.</p>
    </div>

    @if(session('status'))
        <div class="rounded-md px-3 py-2 text-sm" style="background:#f0fdf4; color:#166534;">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-3 py-2 text-sm" style="background:#fef2f2; color:#991b1b;">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('settings.prospecting.duplicate-rules.update') }}"
          class="rounded-md p-6 space-y-4" style="background:var(--surface); border:1px solid var(--border);">
        @csrf @method('PUT')
        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">No go under (days)</label>
            <input type="number" name="deeds_duplicate_no_go_days" min="1" max="365" required value="{{ old('deeds_duplicate_no_go_days', $noGoDays) }}"
                   class="w-32 px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <p class="text-xs mt-1" style="color:var(--text-muted);">A property off the market for fewer days than this is refused outright. Suggested: 7.</p>
        </div>
        <div>
            <label class="block text-sm font-semibold mb-1" style="color:var(--text-secondary);">Automatic take at (days)</label>
            <input type="number" name="deeds_duplicate_auto_take_days" min="1" max="365" required value="{{ old('deeds_duplicate_auto_take_days', $autoTakeDays) }}"
                   class="w-32 px-3 py-2 text-sm rounded-md" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <p class="text-xs mt-1" style="color:var(--text-muted);">Must be ≥ no-go days. At or older than this the agent takes it straight away. Between the two thresholds, an admin or branch manager must approve. Suggested: 14.</p>
        </div>
        <div>
            <button type="submit" class="text-sm font-semibold px-5 py-2 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Save duplicate-take rules</button>
        </div>
    </form>
</div>
@endsection
