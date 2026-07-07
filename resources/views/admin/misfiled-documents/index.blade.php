{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 — AT-167 Misfiled Documents register --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Misfiled Documents</h1>
                <p class="text-sm text-white/60">
                    Contact-only documents (ID, FICA, POR) that were filed to a property or left unfiled with
                    <strong>no contact assigned</strong>. Refile each to the correct person — the wrong property link is
                    removed per the document type's Save-to rule. Nothing is ever hard-deleted.
                </p>
            </div>
        </div>
    </div>

    {{-- KPI tiles (below the header — never inside it, per §3.1) --}}
    @if($total > 0)
    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="To refile" :value="number_format($total)" />
        @foreach($summary as $label => $n)
            <x-corex-kpi-card :title="$label" :value="number_format($n)" />
        @endforeach
    </div>
    @endif

    {{-- Flash / validation --}}
    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Document (from split)</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Current filing location</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Filed</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Refile to contact</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($docs as $doc)
                        @php($src = $sourceProps->get($doc->source_id))
                        @php($linked = $doc->properties)
                        <tr style="border-top: 1px solid var(--border); vertical-align: top;">
                            <td class="px-4 py-3" style="color: var(--text-primary);">
                                <a href="{{ \Illuminate\Support\Facades\Storage::disk($doc->disk ?? 'public')->url($doc->storage_path) }}"
                                   target="_blank" rel="noopener" class="font-medium underline"
                                   style="color: var(--brand-icon, #0ea5e9);">{{ $doc->original_name }}</a>
                                <div class="text-xs" style="color: var(--text-secondary);">#{{ $doc->id }}</div>
                            </td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">
                                {{ $doc->documentType?->label ?? $doc->documentType?->slug ?? '—' }}
                            </td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">
                                @if($linked->isNotEmpty())
                                    @foreach($linked as $p)
                                        <div>Property: <span style="color: var(--text-primary);">{{ $p->buildDisplayAddress() ?: ('Property #'.$p->id) }}</span> (#{{ $p->id }})</div>
                                    @endforeach
                                    <div class="text-xs" style="color: var(--ds-amber, #f59e0b);">No contact assigned — misfiled to the property.</div>
                                @else
                                    <span style="color: var(--ds-amber, #f59e0b);">Neither — unfiled (no property, no contact).</span>
                                @endif
                            </td>
                            <td class="px-4 py-3" style="color: var(--text-secondary);">
                                <div>{{ optional($doc->created_at)->format('Y-m-d H:i') }}</div>
                                <div class="text-xs">by {{ $doc->uploader?->name ?? '—' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                @if($src && $src->contacts->isNotEmpty())
                                    @permission('misfiled_documents.refile')
                                    <form method="POST" action="{{ route('admin.misfiled-documents.refile', $doc) }}"
                                          class="flex flex-col gap-2" style="min-width: 220px;">
                                        @csrf
                                        <select name="contact_ids[]" multiple size="{{ min(4, max(2, $src->contacts->count())) }}"
                                                required class="rounded-md px-2 py-1 text-sm"
                                                style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                            @foreach($src->contacts as $c)
                                                <option value="{{ $c->id }}">
                                                    {{ trim(($c->first_name ?? '').' '.($c->last_name ?? '')) ?: ('Contact #'.$c->id) }}@if($c->pivot?->role) — {{ ucfirst($c->pivot->role) }}@endif
                                                </option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="corex-btn-primary text-sm justify-center">Refile</button>
                                    </form>
                                    @endpermission
                                @else
                                    <span class="text-xs" style="color: var(--text-secondary);">
                                        No contacts on the property.
                                        @if($src)
                                            <a href="{{ route('corex.properties.show', $src) }}" class="underline" style="color: var(--brand-icon, #0ea5e9);">Add one</a>
                                        @endif
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                            No misfiled documents. Every contact-only document is on a contact.
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($docs->hasPages())
        <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
            {{ $docs->links() }}
        </div>
        @endif
    </div>

</div>
@endsection
