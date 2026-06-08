{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md --}}
@extends('layouts.corex')

@section('corex-content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <a href="{{ route('admin.soft-deletes.index') }}" class="text-xs font-semibold text-white/60 hover:text-white">&larr; Soft Deletes</a>
                <h1 class="text-xl font-bold text-white leading-tight mt-1">Archived {{ $label }}</h1>
                <p class="text-sm text-white/60">Restore any record below to bring it back into CoreX.</p>
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
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('error') }}
        </div>
    @endif

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Record</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">ID</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Archived</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($records as $record)
                        <tr class="transition-colors" style="border-top: 1px solid var(--border);"
                            onmouseover="this.style.background='var(--surface-2)'"
                            onmouseout="this.style.background=''">
                            <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">{{ $registry->recordLabel($record) }}</td>
                            <td class="px-4 py-3 font-mono text-xs" style="color: var(--text-muted);">#{{ $record->getKey() }}</td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">
                                {{ optional($record->deleted_at)->format('d M Y, H:i') ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <form method="POST" action="{{ route('admin.soft-deletes.restore', [$key, $record->getKey()]) }}"
                                      class="inline"
                                      onsubmit="return confirm('Restore this record? It will reappear in CoreX.');">
                                    @csrf
                                    <button type="submit" class="text-xs font-semibold" style="color: var(--ds-green);">Restore</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                Nothing archived here.
                                <a href="{{ route('admin.soft-deletes.index') }}" class="font-semibold" style="color: var(--brand-icon);">Back to Soft Deletes.</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($records->hasPages())
        <div>{{ $records->links() }}</div>
    @endif

</div>
@endsection
