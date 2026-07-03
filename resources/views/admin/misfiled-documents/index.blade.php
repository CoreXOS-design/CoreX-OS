{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — AT-167 Misfiled Documents register --}}
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
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-semibold text-white"
                      style="background: color-mix(in srgb, white 15%, transparent);">
                    {{ number_format($total) }} to refile
                </span>
                @foreach($summary as $label => $n)
                    <span class="inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-semibold text-white/90"
                          style="background: color-mix(in srgb, white 10%, transparent);">{{ $label }}: {{ $n }}</span>
                @endforeach
            </div>
        </div>
    </div>

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
    <div class="rounded-md overflow-x-auto" style="border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2, #f3f4f6);">
                    <th class="text-left px-4 py-2 font-semibold" style="color: var(--text-secondary);">Document (from split)</th>
                    <th class="text-left px-4 py-2 font-semibold" style="color: var(--text-secondary);">Type</th>
                    <th class="text-left px-4 py-2 font-semibold" style="color: var(--text-secondary);">Current filing location</th>
                    <th class="text-left px-4 py-2 font-semibold" style="color: var(--text-secondary);">Filed</th>
                    <th class="text-left px-4 py-2 font-semibold" style="color: var(--text-secondary);">Refile to contact</th>
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
                               style="color: var(--ds-blue, #2563eb);">{{ $doc->original_name }}</a>
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
                                <div class="text-xs" style="color: var(--ds-crimson, #dc2626);">No contact assigned — misfiled to the property.</div>
                            @else
                                <span style="color: var(--ds-crimson, #dc2626);">Neither — unfiled (no property, no contact).</span>
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
                                    <button type="submit" class="rounded-md px-3 py-1.5 text-sm font-semibold text-white"
                                            style="background: var(--ds-green, #16a34a);">Refile</button>
                                </form>
                                @endpermission
                            @else
                                <span class="text-xs" style="color: var(--text-secondary);">
                                    No contacts on the property.
                                    @if($src)
                                        <a href="{{ route('corex.properties.show', $src) }}" class="underline" style="color: var(--ds-blue, #2563eb);">Add one</a>
                                    @endif
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center" style="color: var(--text-secondary);">
                        No misfiled documents. Every contact-only document is on a contact. 🎉
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $docs->links() }}</div>
</div>
@endsection
