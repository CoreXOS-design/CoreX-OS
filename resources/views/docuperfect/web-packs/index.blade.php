@extends('layouts.corex')

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Web Packs</h2>
            <div class="text-sm text-white/60">Group web templates into reusable packs.</div>
        </div>
        @if($canManage)
        <a href="{{ route('docuperfect.web-packs.create') }}" class="corex-btn-primary text-sm" style="background:rgba(255,255,255,0.15);">
            + New Web Pack
        </a>
        @endif
    </div>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if($webPacks->isEmpty())
        <div class="ds-status-card p-6 text-center">
            <div class="text-sm text-slate-500">No web packs created yet.</div>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-xs text-slate-500 uppercase tracking-wider">
                        <th class="py-3 px-4">Name</th>
                        <th class="py-3 px-4">Templates</th>
                        <th class="py-3 px-4">Created By</th>
                        <th class="py-3 px-4">Date</th>
                        @if($canManage)
                        <th class="py-3 px-4 text-right">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($webPacks as $webPack)
                    <tr class="hover:bg-slate-50">
                        <td class="py-3 px-4">
                            <div class="font-semibold text-slate-900 flex items-center gap-2">
                                {{ $webPack->name }}
                                @php
                                    $hasCds = $webPack->items->contains(fn($item) => $item->template && $item->template->template_type === 'cds');
                                    $hasWeb = $webPack->items->contains(fn($item) => $item->template && $item->template->template_type !== 'cds');
                                @endphp
                                @if($hasCds && $hasWeb)
                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-blue-100 text-blue-600 font-semibold">Web + CDS</span>
                                @elseif($hasCds)
                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-teal-100 text-teal-700 font-semibold">CDS</span>
                                @else
                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-blue-100 text-blue-600 font-semibold">Web</span>
                                @endif
                            </div>
                            @if($webPack->description)
                            <div class="text-xs text-slate-400 mt-0.5">{{ Str::limit($webPack->description, 60) }}</div>
                            @endif
                        </td>
                        <td class="py-3 px-4 text-slate-600">
                            <div>{{ $webPack->items->count() }} template{{ $webPack->items->count() !== 1 ? 's' : '' }}</div>
                            @php
                                $hasSelectable = $webPack->items->contains(fn($i) => ($i->slot_type ?? 'required') === 'selectable');
                                $hasOptional = $webPack->items->contains(fn($i) => ($i->slot_type ?? 'required') === 'optional');
                            @endphp
                            @if($hasSelectable || $hasOptional)
                            <div class="flex gap-1 mt-0.5">
                                @if($hasSelectable)
                                <span class="text-[9px] px-1 py-0 rounded bg-amber-100 text-amber-700">Selectable</span>
                                @endif
                                @if($hasOptional)
                                <span class="text-[9px] px-1 py-0 rounded bg-gray-100 text-gray-500">Optional</span>
                                @endif
                            </div>
                            @endif
                        </td>
                        <td class="py-3 px-4 text-slate-600">
                            {{ $webPack->createdBy?->name ?? '—' }}
                        </td>
                        <td class="py-3 px-4 text-slate-400 text-xs">
                            {{ $webPack->created_at->format('d M Y') }}
                        </td>
                        @if($canManage)
                        <td class="py-3 px-4 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <a href="{{ route('docuperfect.web-packs.edit', $webPack->id) }}" class="corex-btn-outline text-xs px-3 py-1.5">Edit</a>
                                <form method="POST" action="{{ route('docuperfect.web-packs.destroy', $webPack->id) }}" class="inline" onsubmit="return confirm('Archive this web pack?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-xs text-red-400 hover:text-red-600">Archive</button>
                                </form>
                            </div>
                        </td>
                        @endif
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</div>
@endsection
