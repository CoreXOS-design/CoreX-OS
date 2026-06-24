{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5" data-tour="dp-packs-header" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Document Packs</h1>
                <p class="text-sm text-white/60">Launch a pack to create all its documents at once.</p>
            </div>
            @if($canManage)
            <div class="flex items-center gap-2">
                <a href="{{ route('docuperfect.packs.create') }}" class="corex-btn-primary">New Pack</a>
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

    @if($packs->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No document packs yet</h3>
            <p class="text-sm mb-4" style="color: var(--text-muted);">Create a pack to bundle multiple templates and launch them together.</p>
            @if($canManage)
            <a href="{{ route('docuperfect.packs.create') }}" class="corex-btn-primary">New Pack</a>
            @endif
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" data-tour="dp-packs-grid">
            @foreach($packs as $pack)
            <div class="pack-card rounded-md p-4 flex flex-col"
                 style="background: var(--surface); border: 1px solid var(--border); transition: all 300ms ease;">
                <div class="font-semibold text-sm leading-tight mb-1" style="color: var(--text-primary);">{{ $pack->name }}</div>
                @if($pack->description)
                <div class="text-xs mb-2" style="color: var(--text-muted);">{{ $pack->description }}</div>
                @endif
                <div class="text-xs mb-3 flex flex-wrap items-center gap-2" style="color: var(--text-muted);">
                    @if($pack->usesSlots())
                        <span>{{ number_format($pack->slots->count()) }} slot{{ $pack->slots->count() !== 1 ? 's' : '' }}</span>
                        <span>&middot;</span>
                        <span style="color: {{ $pack->creation_mode === 'linked' ? 'var(--brand-icon)' : 'var(--text-muted)' }};">{{ ucfirst($pack->creation_mode) }}</span>
                    @else
                        <span>{{ number_format($pack->templates->count()) }} template{{ $pack->templates->count() !== 1 ? 's' : '' }}</span>
                    @endif
                    <span>&middot;</span>
                    @if($pack->is_global)
                        <span class="ds-badge ds-badge-info">Global</span>
                    @else
                        <span>{{ $pack->branches->pluck('name')->join(', ') ?: 'No branches' }}</span>
                    @endif
                    @if($pack->esign_eligible)
                        <span class="ds-badge ds-badge-success">E-Sign</span>
                    @else
                        <span class="ds-badge ds-badge-default">No E-Sign</span>
                    @endif
                </div>

                {{-- Slot / Template list --}}
                @if($pack->usesSlots() && $pack->slots->isNotEmpty())
                <div class="flex-1 mb-3">
                    <div class="text-[0.6875rem] uppercase tracking-wider mb-1.5 font-semibold" style="color: var(--text-muted);">Pack contents</div>
                    <ul class="text-xs space-y-1" style="color: var(--text-secondary);">
                        @foreach($pack->slots as $slot)
                        <li class="flex items-center gap-1.5">
                            @if($slot->slot_type === 'required')
                                <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background: var(--ds-green);"></span>
                            @elseif($slot->slot_type === 'selectable')
                                <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background: var(--ds-amber);"></span>
                            @else
                                <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background: var(--brand-icon);"></span>
                            @endif
                            {{ $slot->label }}
                        </li>
                        @endforeach
                    </ul>
                </div>
                @elseif($pack->templates->isNotEmpty())
                <div class="flex-1 mb-3">
                    <div class="text-[0.6875rem] uppercase tracking-wider mb-1.5 font-semibold" style="color: var(--text-muted);">Templates included</div>
                    <ul class="text-xs space-y-1" style="color: var(--text-secondary);">
                        @foreach($pack->templates as $tpl)
                        <li class="flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background: var(--brand-icon);"></span>
                            {{ $tpl->name }}
                        </li>
                        @endforeach
                    </ul>
                </div>
                @endif

                <div class="flex flex-wrap items-center gap-2 mt-auto pt-3" style="border-top: 1px solid var(--border);">
                    <a href="{{ route('docuperfect.packs.showLaunch', $pack->id) }}" class="corex-btn-primary text-xs" @if($loop->first) data-tour="dp-packs-launch" @endif>Launch Pack</a>
                    @if($canManage)
                    <a href="{{ route('docuperfect.packs.edit', $pack->id) }}" class="corex-btn-outline text-xs">Edit</a>
                    <form method="POST" action="{{ route('docuperfect.packs.destroy', $pack->id) }}" class="inline ml-auto" onsubmit="return confirm('Delete this pack?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-xs font-semibold transition-colors" style="color: var(--ds-crimson);">Delete</button>
                    </form>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    @endif

</div>

<style>
    .pack-card:hover { border-color: var(--brand-icon); }
</style>
@endsection
