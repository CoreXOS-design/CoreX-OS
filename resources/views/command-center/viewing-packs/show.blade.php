{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-6">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">{{ $pack->title ?: ('Viewing Pack #' . $pack->id) }}</h1>
                <p class="text-sm text-white/60">
                    Buyer: {{ optional($pack->contact)->full_name ?? '—' }}
                    · Agent: {{ optional($pack->agent)->name ?? '—' }}
                    · Status: {{ ucfirst($pack->status) }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('corex.viewing-packs.index') }}" class="corex-btn-outline" style="color:#fff; border-color: rgba(255,255,255,0.3);">&larr; All packs</a>
                @if(optional($pack->contact))
                    <a href="{{ route('command-center.buyers.show', $pack->contact_id) }}" class="corex-btn-outline" style="color:#fff; border-color: rgba(255,255,255,0.3);">Open buyer</a>
                @endif
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- LEFT: selected properties (skeleton — Step 3 fills this in) --}}
        <div class="lg:col-span-2 rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Selected properties</h3>
                <span class="text-xs" style="color: var(--text-muted);">{{ $pack->viewingPackProperties->count() }} selected</span>
            </div>

            @if($pack->viewingPackProperties->isEmpty())
                <div class="rounded-md py-10 px-6 text-center" style="background: var(--surface-2); border: 1px dashed var(--border);">
                    <p class="text-sm font-medium" style="color: var(--text-secondary);">No properties selected yet.</p>
                    <p class="text-xs mt-1" style="color: var(--text-muted);">Property selection (Core Matches + ad-hoc search) arrives in the next step.</p>
                </div>
            @else
                <ol class="space-y-2">
                    @foreach($pack->viewingPackProperties as $vpp)
                        <li class="flex items-center gap-3 rounded-md px-3 py-2" style="background: var(--surface-2); border: 1px solid var(--border);">
                            <span class="text-sm font-semibold" style="color: var(--text-muted);">{{ $vpp->sort_order }}.</span>
                            <span class="flex-1 text-sm" style="color: var(--text-primary);">{{ optional($vpp->property)->address ?? ('Property #' . $vpp->property_id) }}</span>
                            <span class="ds-badge ds-badge-default">{{ str_replace('_', ' ', $vpp->source) }}</span>
                            <span class="text-xs" style="color: var(--text-muted);">{{ $vpp->viewingPackDocuments->count() }} docs</span>
                        </li>
                    @endforeach
                </ol>
            @endif

            <div class="mt-4 rounded-md px-3 py-2 text-xs" style="background: var(--surface-2); color: var(--text-muted);">
                Coming next: drag-to-order, document selection (buyer-pack-eligible only), redaction, and the buyer pack + agent sheet PDFs.
            </div>
        </div>

        {{-- RIGHT: pack settings (title + status) --}}
        <div class="lg:col-span-1 rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <h3 class="text-lg font-semibold mb-3" style="color: var(--text-primary);">Pack details</h3>
            <form method="POST" action="{{ route('corex.viewing-packs.update', $pack) }}" class="space-y-3">
                @csrf
                @method('PUT')
                <div>
                    <label for="vp-title" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Title</label>
                    <input id="vp-title" type="text" name="title" value="{{ old('title', $pack->title) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label for="vp-status" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
                    <select id="vp-status" name="status" class="w-full rounded-md px-3 py-2 text-sm"
                            style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        @foreach(\App\Models\ViewingPack::STATUSES as $s)
                            <option value="{{ $s }}" @selected($pack->status === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="corex-btn-primary w-full">Save</button>
            </form>

            <hr class="my-4" style="border-color: var(--border);">

            <form method="POST" action="{{ route('corex.viewing-packs.destroy', $pack) }}"
                  onsubmit="return confirm('Archive this viewing pack? You can recover it later.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="corex-btn-outline w-full" style="color: var(--ds-crimson); border-color: var(--ds-crimson);">Archive pack</button>
            </form>
        </div>
    </div>
</div>
@endsection
