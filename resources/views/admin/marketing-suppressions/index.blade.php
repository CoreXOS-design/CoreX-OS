{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — AT-49 Marketing Suppression register --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Marketing Suppressions</h1>
                <p class="text-sm text-white/60">
                    "One opt-out, suppressed everywhere." Every email / number that has opted out of marketing —
                    blocked agency-wide, even on re-import. Lifting a row is an opt-in; nothing is ever hard-deleted.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-semibold text-white"
                      style="background: color-mix(in srgb, white 15%, transparent);"
                      title="Active suppressions">
                    {{ number_format($suppressions->total()) }} {{ $status === 'lifted' ? 'lifted' : ($status === 'all' ? 'total' : 'active') }}
                </span>
            </div>
        </div>
    </div>

    {{-- Flash messages --}}
    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif

    {{-- Filters --}}
    <form method="GET" action="{{ route('admin.marketing-suppressions.index') }}"
          class="flex flex-col sm:flex-row gap-2">
        <input type="text" name="q" value="{{ $search }}" placeholder="Search email or number…"
               class="flex-1 rounded-md px-3 py-2 text-sm"
               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        <select name="status" class="rounded-md px-3 py-2 text-sm"
                style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
            <option value="lifted" {{ $status === 'lifted' ? 'selected' : '' }}>Lifted</option>
            <option value="all"    {{ $status === 'all' ? 'selected' : '' }}>All</option>
        </select>
        <button type="submit" class="rounded-md px-4 py-2 text-sm font-semibold text-white"
                style="background: var(--brand-default, #0b2a4a);">Filter</button>
    </form>

    {{-- Table --}}
    <div class="rounded-md overflow-hidden" style="border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2, #f3f4f6);">
                    <th class="text-left px-4 py-2 font-semibold" style="color: var(--text-secondary);">Identifier</th>
                    <th class="text-left px-4 py-2 font-semibold" style="color: var(--text-secondary);">Type</th>
                    <th class="text-left px-4 py-2 font-semibold" style="color: var(--text-secondary);">Source</th>
                    <th class="text-left px-4 py-2 font-semibold" style="color: var(--text-secondary);">Contact</th>
                    <th class="text-left px-4 py-2 font-semibold" style="color: var(--text-secondary);">Suppressed</th>
                    <th class="text-left px-4 py-2 font-semibold" style="color: var(--text-secondary);">Status</th>
                    <th class="text-right px-4 py-2 font-semibold" style="color: var(--text-secondary);">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($suppressions as $s)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2 font-mono" style="color: var(--text-primary);">{{ $s->identifier }}</td>
                        <td class="px-4 py-2" style="color: var(--text-secondary);">{{ ucfirst($s->identifier_type) }}</td>
                        <td class="px-4 py-2" style="color: var(--text-secondary);">{{ str_replace('_', ' ', $s->source) }}</td>
                        <td class="px-4 py-2" style="color: var(--text-secondary);">
                            {{ $s->contact ? $s->contact->full_name : '—' }}
                        </td>
                        <td class="px-4 py-2" style="color: var(--text-secondary);">
                            {{ optional($s->suppressed_at)->format('Y-m-d H:i') }}
                        </td>
                        <td class="px-4 py-2">
                            @if($s->isActive())
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold"
                                      style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); color: var(--ds-crimson, #dc2626);">Active</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold"
                                      style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green, #16a34a);">Lifted</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            @if($s->isActive())
                                @permission('marketing_suppressions.manage')
                                <form method="POST" action="{{ route('admin.marketing-suppressions.lift', $s) }}"
                                      onsubmit="return confirm('Lift this suppression? This identifier will be able to receive marketing again.');">
                                    @csrf
                                    <button type="submit" class="px-3 py-1 rounded text-xs font-semibold text-white"
                                            style="background: var(--brand-default, #0b2a4a);">Lift</button>
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
                        <td colspan="7" class="px-4 py-6 text-center" style="color: var(--text-muted);">
                            No suppressions found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $suppressions->links() }}
    </div>

</div>
@endsection
