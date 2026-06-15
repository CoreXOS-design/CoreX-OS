{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Web Packs</h1>
                <p class="text-sm text-white/60">Group web templates into reusable packs.</p>
            </div>
            @if($canManage)
            <div class="flex items-center gap-2">
                <a href="{{ route('docuperfect.web-packs.create') }}" class="corex-btn-primary">
                    + New Web Pack
                </a>
            </div>
            @endif
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    @if($webPacks->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No web packs yet</h3>
            <p class="text-sm mb-4" style="color: var(--text-muted);">Group web templates into a reusable pack to speed up document creation.</p>
            @if($canManage)
            <a href="{{ route('docuperfect.web-packs.create') }}" class="corex-btn-primary">+ New Web Pack</a>
            @endif
        </div>
    @else
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Name</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Templates</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Created By</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Date</th>
                            @if($canManage)
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($webPacks as $webPack)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-3">
                                <div class="font-semibold flex items-center gap-2" style="color: var(--text-primary);">
                                    {{ $webPack->name }}
                                    <span class="ds-badge ds-badge-info">Web</span>
                                </div>
                                @if($webPack->description)
                                <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ Str::limit($webPack->description, 60) }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">
                                <div>{{ number_format($webPack->items->count()) }} template{{ $webPack->items->count() !== 1 ? 's' : '' }}</div>
                                @php
                                    $hasSelectable = $webPack->items->contains(fn($i) => ($i->slot_type ?? 'required') === 'selectable');
                                    $hasOptional = $webPack->items->contains(fn($i) => ($i->slot_type ?? 'required') === 'optional');
                                @endphp
                                @if($hasSelectable || $hasOptional)
                                <div class="flex gap-1 mt-1">
                                    @if($hasSelectable)
                                    <span class="ds-badge ds-badge-warning">Selectable</span>
                                    @endif
                                    @if($hasOptional)
                                    <span class="ds-badge ds-badge-default">Optional</span>
                                    @endif
                                </div>
                                @endif
                            </td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">
                                {{ $webPack->createdBy?->name ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">
                                {{ $webPack->created_at?->format('d M Y') ?? '—' }}
                            </td>
                            @if($canManage)
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('docuperfect.web-packs.edit', $webPack->id) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Edit</a>
                                    <form method="POST" action="{{ route('docuperfect.web-packs.destroy', $webPack->id) }}" class="inline" onsubmit="return confirm('Archive this web pack?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson);">Archive</button>
                                    </form>
                                </div>
                            </td>
                            @endif
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

</div>
@endsection
