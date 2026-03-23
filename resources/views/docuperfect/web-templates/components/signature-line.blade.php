{{--
    Inline Signature Line — simple signature placeholder within document body.
    Used by CDS-generated templates for inline signature points (not the full
    signature block at the end of the document).

    Usage:
        @include('docuperfect.web-templates.components.signature-line')
        @include('docuperfect.web-templates.components.signature-line', ['party' => 'seller'])

    When $recipients_by_role is available (from e-sign wizard), renders one
    signature line per recipient of the given party role (e.g. 2 sellers = 2 lines).
--}}
@php
    $inlineParty = $party ?? null;
    $roleAliases = ['lessor' => 'landlord', 'lessee' => 'tenant'];
    $lookupKey = $inlineParty ? ($roleAliases[$inlineParty] ?? $inlineParty) : null;
    $recipientsForParty = ($lookupKey && isset($recipients_by_role) && is_array($recipients_by_role))
        ? ($recipients_by_role[$lookupKey] ?? $recipients_by_role[$inlineParty] ?? [])
        : [];
@endphp
@if(count($recipientsForParty) > 1)
    {{-- Multiple recipients: render side-by-side signature lines --}}
    <span style="display:inline-flex; gap:12pt;">
    @foreach($recipientsForParty as $idx => $r)
        @php $markerKey = $idx === 0 ? $lookupKey : $lookupKey . '_' . ($idx + 1); @endphp
        <span class="sig-inline-line" style="display:inline-block; min-width:120pt; border-bottom:1px solid #333; min-height:14pt; vertical-align:bottom; text-align:center;"
            data-marker-party="{{ $markerKey }}" data-marker-type="signature" data-name="{{ $r['name'] ?? '' }}">
            <span style="font-size:7pt; color:#999;">{{ $r['name'] ?? '' }}</span>
        </span>
    @endforeach
    </span>
@else
    {{-- Single or no recipient: original single line --}}
    <span class="sig-inline-line" style="display:inline-block; min-width:160pt; border-bottom:1px solid #333; min-height:14pt; vertical-align:bottom;"
        @if($inlineParty) data-marker-party="{{ $lookupKey }}" data-marker-type="signature" @if(!empty($recipientsForParty[0]['name'])) data-name="{{ $recipientsForParty[0]['name'] }}" @endif @endif>&nbsp;</span>
@endif
