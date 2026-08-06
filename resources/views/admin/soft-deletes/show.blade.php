{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Archived {{ $label }}</h1>
                <p class="text-xs" style="color: var(--text-muted);">Restore any record below to bring it back into CoreX.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.soft-deletes.index') }}" class="corex-btn-outline text-xs">&larr; Soft Deletes</a>
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
                        @php
                            // AT-278 — a User carries the 30-day seat reinstatement hold.
                            // Show it before the click, not after a refused submit.
                            $release  = ($isUserModel ?? false) ? ($seatReleases[$record->getKey()] ?? null) : null;
                            $blocking = $release && $release->isBlocking();
                        @endphp
                        <tr class="transition-colors" style="border-top: 1px solid var(--border);"
                            onmouseover="this.style.background='var(--surface-2)'"
                            onmouseout="this.style.background=''">
                            <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">{{ $registry->recordLabel($record) }}</td>
                            <td class="px-4 py-3 font-mono text-xs" style="color: var(--text-muted);">#{{ $record->getKey() }}</td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">
                                {{ optional($record->deleted_at)->format('d M Y, H:i') ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if($blocking)
                                    <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md text-xs font-semibold"
                                          style="background: color-mix(in srgb, var(--ds-amber) 12%, transparent); color: var(--ds-amber); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);"
                                          title="Released on {{ $release->released_at->format('j M Y') }}. Restore this agent from Archived Agents — a CoreX Dev can lift the hold early there.">
                                        Locked until {{ $release->reinstatable_at->format('j M Y') }}
                                        ({{ $release->remainingDays() }} {{ \Illuminate\Support\Str::plural('day', $release->remainingDays()) }})
                                    </span>
                                    <a href="{{ route('admin.users.archived') }}" class="text-xs font-semibold ml-2" style="color: var(--brand-icon);">
                                        Archived Agents &rarr;
                                    </a>
                                @else
                                    {{-- Custom CoreX confirm — a native browser confirm() reads
                                         as a stray Chrome/OS dialog, not part of the product. --}}
                                    <div x-data="{ open: false }" class="inline-block">
                                        <button type="button" @click="open = true" class="text-xs font-semibold" style="color: var(--ds-green);">
                                            Restore
                                        </button>

                                        <div x-show="open" x-cloak
                                             class="fixed inset-0 z-50 flex items-center justify-center px-4"
                                             style="background: rgba(0,0,0,0.6);"
                                             @keydown.escape.window="open = false">
                                            <div class="w-full max-w-md rounded-md p-5 text-left"
                                                 style="background: var(--surface); border:1px solid var(--border);"
                                                 @click.outside="open = false">
                                                <h3 class="text-base font-bold mb-2" style="color: var(--text-primary);">
                                                    Restore {{ $registry->recordLabel($record) }}?
                                                </h3>
                                                <p class="text-sm mb-4" style="color: var(--text-secondary);">
                                                    It will reappear in CoreX.
                                                </p>
                                                <div class="flex items-center justify-end gap-2">
                                                    <button type="button" @click="open = false"
                                                            class="px-3 py-1.5 rounded-md text-xs font-semibold"
                                                            style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);">
                                                        Cancel
                                                    </button>
                                                    <form method="POST" action="{{ route('admin.soft-deletes.restore', [$key, $record->getKey()]) }}">
                                                        @csrf
                                                        <button type="submit" class="corex-btn-primary text-xs">Restore</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endif
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
        @if($records->hasPages())
            <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                {{ $records->links() }}
            </div>
        @endif
    </div>

</div>
@endsection
