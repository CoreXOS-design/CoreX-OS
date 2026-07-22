{{--
    DR2 deal documents (AT-226) — reusable partial. @include('dr2._deal-documents', ['deal' => $deal])
    on the DR2 deal view (m1's dr2/pipeline.blade or the deal detail). Optionally pass
    ['stepId' => $s->id] to scope an upload to a pipeline step (gas CoC → gas step).

    Uploads funnel through Dr2\DealDocumentController@store → DealDocumentService::fileDealDocumentFromDeal,
    which files the doc to the DEAL (via the deals_v2 twin), its PROPERTY, and the property's CONTACTS.
    Document types are the SHARED PDF-splitter list (document_types) — one type truth.

    AT-331 — LAYOUT-ONLY: the "Send documents to a party" block moved OUT of this card into
    its own tab (dr2/_email-parties.blade.php). This partial is now the "Documents" tab only.
    A house-standard collapse/expand header was added; the list/upload logic is unchanged.
--}}
@php
    $stepId   = $stepId ?? null;
    $dealDocs = \App\Models\Document::query()
        ->where(function ($q) use ($deal) {
            $q->where(fn ($w) => $w->where('source_type', 'deal')->where('source_id', $deal->id));
            if ($deal->deal_v2_id) { $q->orWhere('deal_id', $deal->deal_v2_id); }
        })
        ->with(['documentType', 'properties:id', 'contacts:id'])
        ->latest()->get();
    $docTypes = \App\Http\Controllers\Dr2\DealDocumentController::typeOptions();
@endphp

<div class="corex-card" style="padding:1rem;" data-tour="dr2-deal-documents"
     x-data="{ openDocs: true }">

    {{-- Standard collapse/expand section header (house convention). --}}
    <button type="button" @click="openDocs = !openDocs" class="dr2-sect-head">
        <span class="dr2-chev" :class="openDocs ? '' : 'dr2-chev-closed'">▾</span>
        <span class="dr2-sect-title">Documents{{ $stepId ? ' — this step' : '' }}</span>
    </button>

    <div x-show="openDocs" x-cloak style="margin-top:.6rem;">
        {{-- Filed documents, with their 3-pillar reach --}}
        @if($dealDocs->isEmpty())
            <p style="font-size:.85rem;color:var(--text-muted,#9ca3af);margin-bottom:.75rem;">No documents filed on this deal yet.</p>
        @else
            <div style="display:flex;flex-direction:column;gap:.4rem;margin-bottom:.85rem;">
                @foreach($dealDocs as $doc)
                <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.5rem .65rem;border:1px solid var(--border,rgba(0,0,0,.08));border-radius:8px;">
                    <div style="min-width:0;">
                        <a href="{{ route('deals-dr2.documents.download', [$deal, $doc]) }}" style="font-size:.85rem;font-weight:600;color:var(--brand-default,#0b2a4a);">
                            {{ $doc->original_name }}
                        </a>
                        <div style="font-size:.7rem;color:var(--text-muted,#9ca3af);">
                            {{ $doc->documentType->label ?? 'Unclassified' }}
                            · filed to deal
                            @if($doc->properties->isNotEmpty()) · property @endif
                            @if($doc->contacts->isNotEmpty()) · {{ $doc->contacts->count() }} contact(s) @endif
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        @endif

        {{-- Upload — files itself to deal + property + contacts --}}
        <form method="POST" action="{{ route('deals-dr2.documents.store', $deal) }}" enctype="multipart/form-data"
              style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;">
            @csrf
            @if($stepId)<input type="hidden" name="pipeline_step_id" value="{{ $stepId }}">@endif
            <input type="file" name="file" required class="corex-input" style="flex:1 1 220px;font-size:.8rem;">
            <select name="document_type_id" class="corex-input" style="flex:0 1 200px;font-size:.8rem;">
                <option value="">Document type…</option>
                @foreach($docTypes as $t)
                    <option value="{{ $t->id }}">{{ $t->label }}</option>
                @endforeach
            </select>
            <button type="submit" class="corex-btn-primary" style="font-size:.8rem;padding:.4rem .9rem;">Upload &amp; file</button>
        </form>
        <p style="font-size:.7rem;color:var(--text-muted,#9ca3af);margin-top:.5rem;">
            Uploads file automatically to the deal, its property, and the linked contacts.
        </p>
    </div>
</div>
