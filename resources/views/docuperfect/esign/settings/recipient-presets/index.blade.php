@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">E-Sign — Recipient Presets</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    Define how a company/entity recipient is phrased when it signs via a representative
                    — "{{ '{entity_name}' }}, herein represented by {{ '{rep_name}' }} ({{ '{capacity}' }})".
                    The esign wizard uses your default preset automatically.
                </p>
            </div>
            <a href="{{ route('docuperfect.esign.recipient-presets.create') }}" class="corex-btn-primary text-xs no-underline">+ New preset</a>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom: 1px solid var(--border); color: var(--text-muted);">
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide">Name</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide">Applies to</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide">Phrasing</th>
                    <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($presets as $preset)
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td class="px-4 py-3" style="color: var(--text-primary);">
                            <span class="font-semibold">{{ $preset->name }}</span>
                            @if($preset->is_default)
                                <span class="ml-1.5 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md"
                                      style="background:color-mix(in srgb, var(--ds-amber,#f59e0b) 18%, transparent); color:var(--ds-amber,#f59e0b);">Default</span>
                            @endif
                        </td>
                        <td class="px-4 py-3" style="color: var(--text-muted);">{{ ucfirst($preset->applies_to) }}</td>
                        <td class="px-4 py-3" style="color: var(--text-secondary); font-family: ui-monospace, monospace; font-size: 12px;">{{ $preset->phrasing_template }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('docuperfect.esign.recipient-presets.edit', $preset) }}" class="corex-btn-outline text-xs no-underline">Edit</a>
                            <form method="POST" action="{{ route('docuperfect.esign.recipient-presets.destroy', $preset) }}" class="inline"
                                  onsubmit="return confirm('Remove the preset &quot;{{ $preset->name }}&quot;?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded-md"
                                        style="color: var(--ds-crimson); border: 1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent);">Remove</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-sm" style="color: var(--text-muted);">No presets yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
