{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-6">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Viewing Packs</h1>
                <p class="text-sm text-white/60">Buyer-facing property packs assembled for viewings.</p>
            </div>
            <div class="flex items-center gap-2">
                @if($showArchived)
                    <a href="{{ route('corex.viewing-packs.index') }}" class="corex-btn-outline" style="color:#fff; border-color: rgba(255,255,255,0.3);">Active packs</a>
                @else
                    <a href="{{ route('corex.viewing-packs.index', ['archived' => 1]) }}" class="corex-btn-outline" style="color:#fff; border-color: rgba(255,255,255,0.3);">View archived</a>
                @endif
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        @if($packs->isEmpty())
            <div class="py-12 px-6 text-center">
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">
                    {{ $showArchived ? 'No archived packs' : 'No viewing packs yet' }}
                </h3>
                <p class="text-sm" style="color: var(--text-muted);">
                    {{ $showArchived ? 'Archived packs will appear here.' : 'Open a buyer in the Buyer Pipeline and click “Build Viewing Pack”.' }}
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Pack</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Buyer</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Properties</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Created</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($packs as $pack)
                            <tr style="border-top: 1px solid var(--border);">
                                <td class="px-4 py-3" style="color: var(--text-primary);">{{ $pack->title ?: ('Pack #' . $pack->id) }}</td>
                                <td class="px-4 py-3" style="color: var(--text-secondary);">{{ optional($pack->contact)->full_name ?? '—' }}</td>
                                <td class="px-4 py-3" style="color: var(--text-secondary);">{{ optional($pack->agent)->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-center" style="color: var(--text-secondary);">{{ $pack->viewing_pack_properties_count }}</td>
                                <td class="px-4 py-3">
                                    <span class="ds-badge ds-badge-default">{{ ucfirst($pack->status) }}</span>
                                </td>
                                <td class="px-4 py-3" style="color: var(--text-muted);">{{ optional($pack->created_at)->format('d M Y') }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if($showArchived)
                                        <form method="POST" action="{{ route('corex.viewing-packs.restore', $pack->id) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs font-semibold" style="color: var(--brand-icon);">Recover</button>
                                        </form>
                                    @else
                                        <a href="{{ route('corex.viewing-packs.show', $pack) }}" class="text-xs font-semibold no-underline" style="color: var(--brand-icon);">Open</a>
                                        <span style="color: var(--border);">·</span>
                                        <form method="POST" action="{{ route('corex.viewing-packs.destroy', $pack) }}" class="inline"
                                              onsubmit="return confirm('Archive this viewing pack? You can recover it later.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson);">Archive</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div>{{ $packs->links() }}</div>
</div>
@endsection
