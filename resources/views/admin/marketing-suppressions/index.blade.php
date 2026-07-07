{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 — AT-49 Marketing Suppression register --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Marketing Suppressions</h1>
                <p class="text-sm text-white/60">
                    “One opt-out, suppressed everywhere.” Every email / number that has opted out of marketing —
                    blocked agency-wide, even on re-import. Lifting a row is an opt-in; nothing is ever hard-deleted.
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-semibold text-white"
                      style="background: color-mix(in srgb, white 15%, transparent);"
                      title="Records matching the current filter">
                    {{ number_format($suppressions->total()) }} {{ $status === 'lifted' ? 'lifted' : ($status === 'all' ? 'total' : 'active') }}
                </span>
            </div>
        </div>
    </div>

    {{-- Flash messages --}}
    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                 stroke="var(--ds-green, #059669)">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif

    {{-- Filter bar --}}
    <form method="GET" action="{{ route('admin.marketing-suppressions.index') }}"
          class="rounded-md p-3 flex flex-col sm:flex-row gap-2 sm:items-center"
          style="background: var(--surface); border: 1px solid var(--border);">
        <div class="relative flex-1">
            <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
                 fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="var(--text-muted, #9ca3af)">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
            </svg>
            <input type="text" name="q" value="{{ $search }}" placeholder="Search email or number…"
                   class="w-full rounded-md pl-9 pr-3 py-2 text-sm"
                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        </div>
        <select name="status" onchange="this.form.submit()" class="list-header-filter">
            <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
            <option value="lifted" {{ $status === 'lifted' ? 'selected' : '' }}>Lifted</option>
            <option value="all"    {{ $status === 'all' ? 'selected' : '' }}>All</option>
        </select>
        <button type="submit" class="corex-btn-primary">Search</button>
        @if($search !== '' || $status !== 'active')
            <a href="{{ route('admin.marketing-suppressions.index') }}" class="corex-btn-outline">Clear</a>
        @endif
    </form>

    {{-- Table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Identifier</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Source</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Contact</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Suppressed</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($suppressions as $s)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-3 font-mono" style="color: var(--text-primary);">{{ $s->identifier }}</td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ ucfirst($s->identifier_type) }}</td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ ucfirst(str_replace('_', ' ', $s->source)) }}</td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">
                                {{ $s->contact ? $s->contact->full_name : '—' }}
                            </td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">
                                {{ optional($s->suppressed_at)->format('Y-m-d H:i') ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                @if($s->isActive())
                                    <span class="ds-badge ds-badge-orange" title="Opted out of marketing — blocked agency-wide">Active</span>
                                @else
                                    <span class="ds-badge ds-badge-success" title="Suppression lifted — can receive marketing again">Lifted</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if($s->isActive())
                                    @permission('marketing_suppressions.manage')
                                    <form method="POST" action="{{ route('admin.marketing-suppressions.lift', $s) }}"
                                          class="inline-block"
                                          onsubmit="return confirm('Lift this suppression? This identifier will be able to receive marketing again.');">
                                        @csrf
                                        <button type="submit" class="corex-btn-outline">Lift</button>
                                    </form>
                                    @endpermission
                                @else
                                    <span class="text-xs" style="color: var(--text-muted);">
                                        {{ $s->liftedBy?->name ?? 'opted in' }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                No suppressions found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($suppressions->hasPages())
            <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                {{ $suppressions->links() }}
            </div>
        @endif
    </div>

</div>
@endsection
