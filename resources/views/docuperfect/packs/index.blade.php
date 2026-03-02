@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Document Packs</h2>
            <div class="text-sm text-white/60">Launch a pack to create all its documents at once.</div>
        </div>
        @if($canManage)
        <a href="{{ route('docuperfect.packs.create') }}" class="corex-btn-primary text-sm" style="background:rgba(255,255,255,0.15);">
            + New Pack
        </a>
        @endif
    </div>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if($packs->isEmpty())
        <div class="ds-status-card p-6 text-center">
            <div class="text-sm text-slate-500">No document packs available yet.</div>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($packs as $pack)
            <div class="ds-status-card p-4 flex flex-col">
                <div class="font-semibold text-slate-900 text-sm leading-tight mb-1">{{ $pack->name }}</div>
                @if($pack->description)
                <div class="text-xs text-slate-500 mb-2">{{ $pack->description }}</div>
                @endif
                <div class="text-xs text-slate-400 mb-1">
                    @if($pack->usesSlots())
                        {{ $pack->slots->count() }} slot{{ $pack->slots->count() !== 1 ? 's' : '' }}
                        &middot;
                        <span class="text-[10px] {{ $pack->creation_mode === 'linked' ? 'text-cyan-500' : 'text-slate-500' }}">{{ ucfirst($pack->creation_mode) }}</span>
                    @else
                        {{ $pack->templates->count() }} template{{ $pack->templates->count() !== 1 ? 's' : '' }}
                    @endif
                    &middot;
                    @if($pack->is_global)
                        <span class="ds-badge ds-badge-success text-[10px]">Global</span>
                    @else
                        {{ $pack->branches->pluck('name')->join(', ') ?: 'No branches' }}
                    @endif
                </div>

                {{-- Slot / Template list --}}
                @if($pack->usesSlots() && $pack->slots->isNotEmpty())
                <div class="flex-1 mb-3">
                    <div class="text-[11px] text-slate-400 uppercase tracking-wider mb-1">Pack contents</div>
                    <ul class="text-xs text-slate-600 space-y-0.5">
                        @foreach($pack->slots as $slot)
                        <li class="flex items-center gap-1">
                            @if($slot->slot_type === 'required')
                                <span class="w-1.5 h-1.5 rounded-full bg-green-500 flex-shrink-0"></span>
                            @elseif($slot->slot_type === 'selectable')
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 flex-shrink-0"></span>
                            @else
                                <span class="w-1.5 h-1.5 rounded-full bg-blue-500 flex-shrink-0"></span>
                            @endif
                            {{ $slot->label }}
                        </li>
                        @endforeach
                    </ul>
                </div>
                @elseif($pack->templates->isNotEmpty())
                <div class="flex-1 mb-3">
                    <div class="text-[11px] text-slate-400 uppercase tracking-wider mb-1">Templates included</div>
                    <ul class="text-xs text-slate-600 space-y-0.5">
                        @foreach($pack->templates as $tpl)
                        <li class="flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-cyan-500 flex-shrink-0"></span>
                            {{ $tpl->name }}
                        </li>
                        @endforeach
                    </ul>
                </div>
                @endif

                <div class="flex flex-wrap items-center gap-2 mt-auto pt-3 border-t border-slate-100">
                    <a href="{{ route('docuperfect.packs.showLaunch', $pack->id) }}" class="corex-btn-primary text-xs px-3 py-1.5 inline-block">Launch Pack</a>
                    @if($canManage)
                    <a href="{{ route('docuperfect.packs.edit', $pack->id) }}" class="corex-btn-outline text-xs px-3 py-1.5">Edit</a>
                    <form method="POST" action="{{ route('docuperfect.packs.destroy', $pack->id) }}" class="inline ml-auto" onsubmit="return confirm('Delete this pack? This cannot be undone.');">
                        @csrf
                        @method('DELETE')
                        <button class="text-xs text-red-400 hover:text-red-600">Delete</button>
                    </form>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    @endif

</div>
@endsection
