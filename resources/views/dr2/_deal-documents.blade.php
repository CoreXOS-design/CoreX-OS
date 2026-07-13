{{--
    DR2 deal documents (AT-226) — reusable partial. @include('dr2._deal-documents', ['deal' => $deal])
    on the DR2 deal view (m1's dr2/pipeline.blade or the deal detail). Optionally pass
    ['stepId' => $s->id] to scope an upload to a pipeline step (gas CoC → gas step).

    Uploads funnel through Dr2\DealDocumentController@store → DealDocumentService::fileDealDocumentFromDeal,
    which files the doc to the DEAL (via the deals_v2 twin), its PROPERTY, and the property's CONTACTS.
    Document types are the SHARED PDF-splitter list (document_types) — one type truth.
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

<div class="corex-card" style="padding:1rem;" data-tour="dr2-deal-documents">
    <h3 style="font-size:.9rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted,#6b7280);margin-bottom:.75rem;">
        Documents{{ $stepId ? ' — this step' : '' }}
    </h3>

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

    {{-- AT-228 — party-first document distribution (send buttons + deal comms grouping) --}}
    @if(auth()->user()?->hasPermission('deals_v2.distribute_documents'))
        @php
            $distParties = app(\App\Services\DealV2\Dr2DistributionComposer::class)->parties($deal);
            $sentDist = $deal->deal_v2_id
                ? \App\Models\DealV2\DealDocumentDistribution::withoutGlobalScopes()->where('deal_id', $deal->deal_v2_id)->with('document')->latest()->take(12)->get()
                : collect();
        @endphp
        <div style="margin-top:1rem;border-top:1px solid var(--border,rgba(0,0,0,.08));padding-top:.75rem;">
            <h4 style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted,#6b7280);margin-bottom:.5rem;">Send documents to a party</h4>
            <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                @foreach($distParties as $p)
                    @if($p['sendable'])
                        <a href="{{ route('deals-dr2.distribute.compose', ['deal'=>$deal,'party'=>$p['role']]) }}" class="corex-btn-outline" style="font-size:.78rem;padding:.3rem .7rem;">
                            Send to {{ $p['label'] }}{{ count($p['default_documents']) ? ' · '.count($p['default_documents']).' default' : '' }}
                        </a>
                    @else
                        <span title="{{ $p['note'] }}" style="font-size:.78rem;padding:.3rem .7rem;color:#9ca3af;border:1px dashed var(--border,#ddd);border-radius:8px;">{{ $p['label'] }} — unavailable</span>
                    @endif
                @endforeach
            </div>

            @if($sentDist->isNotEmpty())
                <h4 style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted,#6b7280);margin:.9rem 0 .4rem;">Sent — what went to whom</h4>
                <div style="display:flex;flex-direction:column;gap:.3rem;">
                    @foreach($sentDist as $d)
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:.6rem;font-size:.78rem;padding:.35rem .5rem;border:1px solid var(--border,rgba(0,0,0,.06));border-radius:6px;">
                            <span style="min-width:0;">{{ $d->document?->original_name ?? 'Document' }} <span style="color:#9ca3af;">→ {{ ucwords(str_replace('_',' ',$d->party_role)) }} · {{ $d->recipient_email ?: 'recipient' }}</span></span>
                            <span style="white-space:nowrap;color:#9ca3af;">{{ $d->channel }}/{{ $d->delivery_mode==='secure_link'?'link':'attach' }}{{ $d->part_of>1 ? ' · pt '.$d->part_no.'/'.$d->part_of : '' }} · {{ $d->status }} · {{ $d->sent_at?->format('d M') }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
