{{-- FICA Compliance tab body — extracted as its own partial (pre-existing
     bug fix, found while verifying Phase 4 in a real browser — NOT a Phase 4
     change): same class of Blade-compiler defect as the other _*.blade.php
     partials split out of show.blade.php today. $ficaSubmissions never got
     assigned, so the isNotEmpty() check reading it threw "Undefined
     variable" on every contact page. No logic changed here. --}}

{{-- FICA status indicator --}}
@php
    $ficaDocs = $contact->signedDocuments()
        ->wherePivot('document_type', 'fica')
        ->wherePivot('is_signed', true)
        ->orderByPivot('signed_at', 'desc')
        ->get();
    $ficaSubmissions = $contact->ficaSubmissions()
        ->whereIn('status', ['approved', 'submitted', 'under_review'])
        ->with('verifiedBy')
        ->get();
    $approvedFicaSubs = $ficaSubmissions->where('status', 'approved');
    $allSignedDocs = $contact->signedDocuments()
        ->wherePivot('is_signed', true)
        ->orderByPivot('signed_at', 'desc')
        ->get();
@endphp

<div class="rounded-md p-5" style="border: 1px solid var(--border); background: var(--surface-2);">
    <div class="flex items-center gap-4">
        @if($ficaStatus === 'complete')
            <div class="w-12 h-12 rounded-full flex items-center justify-center text-lg"
                 style="background: color-mix(in srgb, var(--ds-green) 15%, transparent); color: var(--ds-green);">
                &#10003;
            </div>
            <div>
                <h3 class="text-base font-bold" style="color:var(--text-primary);">FICA Complete</h3>
                <p class="text-sm" style="color:var(--text-secondary);">
                    @if($approvedFicaSubs->isNotEmpty())
                        {{ $approvedFicaSubs->count() }} approved FICA submission{{ $approvedFicaSubs->count() !== 1 ? 's' : '' }}.
                        Latest approved {{ $approvedFicaSubs->first()->verified_at?->format('d M Y') }}.
                    @elseif($ficaDocs->isNotEmpty())
                        {{ $ficaDocs->count() }} FICA document{{ $ficaDocs->count() !== 1 ? 's' : '' }} on file.
                        @if($ficaDocs->first()?->pivot?->signed_at)
                            Latest signed {{ \Carbon\Carbon::parse($ficaDocs->first()->pivot->signed_at)->format('d M Y') }}.
                        @endif
                    @endif
                </p>
            </div>
        @elseif($ficaStatus === 'expiring')
            <div class="w-12 h-12 rounded-full flex items-center justify-center text-lg"
                 style="background: color-mix(in srgb, var(--ds-amber) 15%, transparent); color: var(--ds-amber);">
                &#9888;
            </div>
            <div>
                <h3 class="text-base font-bold" style="color:var(--text-primary);">FICA Expiring Soon</h3>
                <p class="text-sm" style="color:var(--text-secondary);">FICA documents are nearing expiry. Consider requesting updated documentation.</p>
            </div>
        @else
            <div class="w-12 h-12 rounded-full flex items-center justify-center text-lg"
                 style="background: color-mix(in srgb, var(--ds-crimson) 15%, transparent); color: var(--ds-crimson);">
                &#10007;
            </div>
            <div>
                <h3 class="text-base font-bold" style="color:var(--text-primary);">No FICA on File</h3>
                <p class="text-sm" style="color:var(--text-secondary);">This contact has no signed FICA documents. FICA compliance is required before transacting.</p>
            </div>
        @endif
    </div>
</div>

{{-- FICA submissions (new system) --}}
@if($ficaSubmissions->isNotEmpty())
<div>
    <h4 class="text-sm font-bold uppercase tracking-wide mb-3" style="color:var(--text-muted);">FICA Submissions</h4>
    <div class="space-y-2">
        @foreach($ficaSubmissions as $sub)
        @php
            $subBadge = match($sub->status) {
                'approved' => 'ds-badge-success',
                'submitted' => 'ds-badge-info',
                'under_review' => 'ds-badge-warning',
                default => 'ds-badge-default',
            };
        @endphp
        <div class="flex items-center justify-between p-3 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex items-center gap-3">
                <svg class="w-5 h-5 flex-shrink-0" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                </svg>
                <div>
                    <p class="text-sm font-semibold" style="color:var(--text-primary);">
                        FICA Form — {{ ucfirst($sub->entity_type) }}
                        <span class="ds-badge {{ $subBadge }} ml-1">{{ $sub->status_label }}</span>
                    </p>
                    <p class="text-xs" style="color:var(--text-muted);">
                        Submitted {{ $sub->signed_at?->format('d M Y') }}
                        @if($sub->status === 'approved' && $sub->verifiedBy)
                            &middot; Approved by {{ $sub->verifiedBy->name }} on {{ $sub->verified_at?->format('d M Y') }}
                            @if($sub->risk_rating)
                                &middot; Risk: {{ [1 => 'Low', 2 => 'Medium', 3 => 'High'][$sub->risk_rating] ?? '' }}
                            @endif
                        @endif
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                @if($sub->status === 'approved')
                <a href="{{ route('compliance.fica.pdf', $sub) }}" target="_blank"
                   class="text-xs font-semibold px-3 py-1.5 rounded-md transition-all"
                   style="color:var(--text-muted); border:1px solid var(--border);" title="Download PDF">
                    PDF
                </a>
                @endif
                <a href="{{ route('compliance.fica.show', $sub) }}"
                   class="text-xs font-semibold px-3 py-1.5 rounded-md transition-all"
                   style="color:var(--brand-icon); border:1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent);">
                    View
                </a>
            </div>
        </div>
        @endforeach
    </div>
</div>
@endif

{{-- Legacy FICA documents (e-sign system) --}}
@if($ficaDocs->isNotEmpty())
<div>
    <h4 class="text-sm font-bold uppercase tracking-wide mb-3" style="color:var(--text-muted);">FICA Documents (E-Sign)</h4>
    <div class="space-y-2">
        @foreach($ficaDocs as $doc)
        <div class="flex items-center justify-between p-3 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex items-center gap-3">
                <svg class="w-5 h-5 flex-shrink-0" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
                <div>
                    <p class="text-sm font-semibold" style="color:var(--text-primary);">{{ $doc->name }}</p>
                    <p class="text-xs" style="color:var(--text-muted);">
                        {{ ucfirst(str_replace('_', ' ', $doc->pivot->party_role ?? '')) }}
                        &middot; Signed {{ $doc->pivot->signed_at ? \Carbon\Carbon::parse($doc->pivot->signed_at)->format('d M Y') : 'N/A' }}
                    </p>
                </div>
            </div>
            @if($doc->pivot->signed_pdf_path)
            <a href="{{ route('docuperfect.signatures.download', $doc) }}"
               class="text-xs font-semibold px-3 py-1.5 rounded-md transition-all"
               style="color:var(--brand-icon); border:1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent);">
                Download
            </a>
            @endif
        </div>
        @endforeach
    </div>
</div>
@endif

{{-- All signed documents for this contact --}}
@if($allSignedDocs->isNotEmpty())
<div>
    <h4 class="text-sm font-bold uppercase tracking-wide mb-3" style="color:var(--text-muted);">All Signed Documents</h4>
    <div class="space-y-2">
        @foreach($allSignedDocs as $doc)
        <div class="flex items-center justify-between p-3 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex items-center gap-3">
                <svg class="w-5 h-5 flex-shrink-0" style="color:var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
                <div>
                    <p class="text-sm font-semibold" style="color:var(--text-primary);">{{ $doc->name }}</p>
                    <p class="text-xs" style="color:var(--text-muted);">
                        {{ ucfirst(str_replace('_', ' ', $doc->pivot->party_role ?? '')) }}
                        &middot; {{ ucfirst($doc->pivot->document_type ?? 'document') }}
                        &middot; {{ $doc->pivot->signed_at ? \Carbon\Carbon::parse($doc->pivot->signed_at)->format('d M Y') : '' }}
                    </p>
                </div>
            </div>
            @if($doc->pivot->signed_pdf_path)
            <a href="{{ route('docuperfect.signatures.download', $doc) }}"
               class="text-xs font-semibold px-3 py-1.5 rounded-md transition-all"
               style="color:var(--brand-icon); border:1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent);">
                Download
            </a>
            @endif
        </div>
        @endforeach
    </div>
</div>
@endif
