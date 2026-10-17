{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
    $maxSource = collect($bySource)->max('cost') ?: 1;
    $maxUser   = collect($byUser)->max('cost') ?: 1;
@endphp
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $agency->name }}</h1>
                <p class="text-xs" style="color: var(--text-muted);">Where the AI spend came from and who drove it — {{ $monthLabel }}.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.ai-usage.index', ['month' => $month]) }}" class="corex-btn-outline text-xs">&larr; AI Usage &amp; Cost</a>
                <form method="GET" class="flex items-center gap-2">
                    <label class="text-xs font-medium" style="color: var(--text-muted);">Month</label>
                    <input type="month" name="month" value="{{ $month }}" class="rounded-md px-3 py-1.5 text-xs"
                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <button type="submit" class="corex-btn-primary text-xs">View</button>
                </form>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background:color-mix(in srgb, var(--ds-green,#059669) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green,#059669) 30%, transparent); color:var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    {{-- AI Spend Safety (AT-456 §A.5) --}}
    @php
        $statusBadge = [
            'active'  => ['ds-badge-success', 'Active'],
            'alerted' => ['ds-badge-warning', 'Alerted'],
            'paused'  => ['ds-badge-danger',  'Paused'],
        ];
        $eventLabel = [
            'alert'  => ['ds-badge-warning', 'Alert email sent'],
            'pause'  => ['ds-badge-danger',  'AI paused'],
            'resume' => ['ds-badge-success', 'AI turned back on'],
            'template_limit' => ['ds-badge-info', 'Template limit changed'],
        ];
    @endphp
    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="px-4 py-3 border-b flex flex-wrap items-center justify-between gap-3" style="border-color:var(--border);">
            <div>
                <h2 class="text-sm font-semibold" style="color:var(--text-primary);">
                    AI Spend Safety
                    <span class="ds-badge {{ $statusBadge[$guardSnapshot['status']][0] }} ml-1">{{ $statusBadge[$guardSnapshot['status']][1] }}</span>
                </h2>
                <p class="text-xs mt-1" style="color:var(--text-muted);">
                    R {{ number_format($guardSnapshot['spend_zar'], 2) }} counted since {{ $guardSnapshot['window_start']->format('d M H:i') }}
                    @if($guardSnapshot['since_resume']) (when AI was turned back on) @endif
                    — alert at R {{ number_format($guard['alert_zar'], 2) }}, switched off at R {{ number_format($guard['cutout_zar'], 2) }}.
                </p>
                @if($guardSnapshot['status'] === 'paused' && $agency->ai_paused_reason)
                    <p class="text-xs mt-1" style="color:var(--ds-crimson,#c41e3a);">Paused {{ $agency->ai_paused_at?->format('d M Y H:i') }} — {{ $agency->ai_paused_reason }}</p>
                @endif
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.dev-settings.index', ['s' => 'ai_spend']) }}" class="corex-btn-outline text-xs">Change thresholds</a>
                @if($guardSnapshot['status'] === 'paused')
                    <form method="POST" action="{{ route('admin.ai-usage.resume', ['agency' => $agency->id]) }}"
                          onsubmit="return confirm(@js('Turn AI back on for ' . $agency->name . '? Spend will be counted afresh from now.'));">
                        @csrf
                        <button type="submit" class="corex-btn-primary text-xs">Turn AI back on</button>
                    </form>
                @endif
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">When</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">What happened</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Spend at the time</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">By</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($guardHistory as $h)
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-4 py-3 whitespace-nowrap" style="color:var(--text-secondary);">{{ $h->occurred_at->format('d M Y H:i') }}</td>
                            <td class="px-4 py-3">
                                <span class="ds-badge {{ $eventLabel[$h->type][0] ?? 'ds-badge-default' }}">{{ $eventLabel[$h->type][1] ?? ucfirst($h->type) }}</span>
                                @if($h->type === 'template_limit')
                                    <span class="text-xs ml-1" style="color:var(--text-secondary);">{{ $h->details['old'] ?? '?' }} → {{ $h->details['new'] ?? '?' }} a month</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-mono" style="color:var(--text-primary);">{{ $h->type === 'template_limit' ? '—' : 'R ' . number_format((float) $h->spend_zar, 2) }}</td>
                            <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $h->user?->name ?? 'CoreX (automatic)' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-sm" style="color:var(--text-muted);">No alerts or pauses yet for {{ $agency->name }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- AI Template Builder allowance (AT-456 Part B §B.4). Owner-only page; the limit is Johan's per-agency dial. --}}
    <div class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-sm font-semibold" style="color:var(--text-primary);">AI template generations</h2>
                <p class="text-xs mt-1" style="color:var(--text-muted);">
                    {{ $templateUsage['used'] }} of {{ $templateUsage['limit'] }} used this month
                    (resets {{ $templateUsage['resets_at']->format('j F Y') }}) · {{ $templatesSaved }} AI template{{ $templatesSaved === 1 ? '' : 's' }} saved in total.
                    One generation = one idea submitted; a failed attempt is not counted.
                </p>
            </div>
            <form method="POST" action="{{ route('admin.ai-usage.template-limit', ['agency' => $agency->id]) }}" class="flex items-end gap-2">
                @csrf
                <label class="block">
                    <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Generations per month</span>
                    <input type="number" name="limit" min="0" max="1000" step="1" required value="{{ old('limit', $templateUsage['limit']) }}"
                           class="w-32 rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                </label>
                <button type="submit" class="corex-btn-primary text-xs px-4 py-2">Save limit</button>
            </form>
        </div>
        @error('limit')
            <p class="text-xs mt-2" style="color:var(--ds-crimson,#c41e3a);">{{ $message }}</p>
        @enderror
    </div>

    {{-- KPI tiles --}}
    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Total spend" :value="'R ' . number_format($totals['cost'], 2)" />
        <x-corex-kpi-card title="Generations" :value="number_format($totals['gens'])" />
        <x-corex-kpi-card title="Input tokens" :value="number_format($totals['inp'])" />
        <x-corex-kpi-card title="Output tokens" :value="number_format($totals['outp'])" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {{-- WHERE — by source --}}
        <div class="rounded-md" style="background:var(--surface); border:1px solid var(--border);">
            <div class="px-4 py-3 border-b" style="border-color:var(--border);">
                <h2 class="text-sm font-semibold" style="color:var(--text-primary);">Where it comes from <span class="font-normal" style="color:var(--text-muted);">— by source</span></h2>
            </div>
            <div class="p-4">
                @forelse($bySource as $s)
                <div class="mb-3 last:mb-0">
                    <div class="flex justify-between text-xs mb-1">
                        <span style="color:var(--text-primary);">{{ ucwords(str_replace('_', ' ', $s['source'])) }}</span>
                        <span class="font-mono" style="color:var(--text-secondary);">R {{ number_format($s['cost'], 2) }} · {{ number_format($s['gens']) }}</span>
                    </div>
                    <div class="ds-progress-track">
                        <div class="ds-progress-bar" style="width: {{ max(2, ($s['cost'] / $maxSource) * 100) }}%; background: var(--brand-icon);"></div>
                    </div>
                </div>
                @empty
                <p class="text-sm" style="color:var(--text-muted);">No AI usage recorded this month.</p>
                @endforelse
            </div>
        </div>

        {{-- WHO — by user --}}
        <div class="rounded-md" style="background:var(--surface); border:1px solid var(--border);">
            <div class="px-4 py-3 border-b" style="border-color:var(--border);">
                <h2 class="text-sm font-semibold" style="color:var(--text-primary);">Who it comes from <span class="font-normal" style="color:var(--text-muted);">— by user</span></h2>
            </div>
            <div class="p-4">
                @forelse($byUser as $u)
                <div class="mb-3 last:mb-0">
                    <div class="flex justify-between text-xs mb-1">
                        <span style="color:var(--text-primary);">{{ $u['name'] }}</span>
                        <span class="font-mono" style="color:var(--text-secondary);">R {{ number_format($u['cost'], 2) }} · {{ number_format($u['gens']) }}</span>
                    </div>
                    <div class="ds-progress-track">
                        <div class="ds-progress-bar ds-bar-amber" style="width: {{ max(2, ($u['cost'] / $maxUser) * 100) }}%;"></div>
                    </div>
                </div>
                @empty
                <p class="text-sm" style="color:var(--text-muted);">No AI usage recorded this month.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Recent calls --}}
    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="px-4 py-3 border-b" style="border-color:var(--border);">
            <h2 class="text-sm font-semibold" style="color:var(--text-primary);">Recent calls <span class="font-normal" style="color:var(--text-muted);">— latest 100</span></h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Time</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">User</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Source</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Surface</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Model</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Tokens (in/out)</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recent as $r)
                    <tr style="border-top:1px solid var(--border);">
                        <td class="px-4 py-3 whitespace-nowrap" style="color:var(--text-secondary);">{{ \Illuminate\Support\Carbon::parse($r['occurred_at'])->format('d M H:i') }}</td>
                        <td class="px-4 py-3" style="color:var(--text-primary);">{{ $r['user'] }}</td>
                        <td class="px-4 py-3">
                            <span class="ds-badge ds-badge-info">{{ ucwords(str_replace('_', ' ', $r['source'])) }}</span>
                            @if($r['fallback'])<span class="ds-badge ds-badge-warning">Fallback</span>@elseif($r['cache_hit'])<span class="ds-badge ds-badge-default">Cache</span>@endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs" style="color:var(--text-muted);">{{ $r['surface'] ?: '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs" style="color:var(--text-secondary);">{{ $r['model'] }}</td>
                        <td class="px-4 py-3 text-right font-mono" style="color:var(--text-secondary);">{{ number_format($r['input']) }} / {{ number_format($r['output']) }}</td>
                        <td class="px-4 py-3 text-right font-mono" style="color:var(--text-primary);">R {{ number_format($r['cost'], 2) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-sm" style="color:var(--text-muted);">No AI calls recorded for {{ $agency->name }} in {{ $monthLabel }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
