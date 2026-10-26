@props(['letterhead' => null, 'agencyId' => null, 'branchId' => null, 'compact' => false])
{{--
    THE agency letterhead (header). One component for every document an agency's name goes on; the values come from the agency's
    settings through App\Support\Documents\AgencyLetterhead. Table layout only so DomPDF and a browser draw the same thing.
    Pass `letterhead` (already resolved), or `agency-id` (+ optional `branch-id`), or nothing for the document's / signed-in agency.
    Prints nothing when there is no agency. A blank setting prints no line at all. `compact` is the three-line form for a page with
    a fixed layout (the printable inspection form): name, address on one line, then tel / e-mail / website / VAT on one line.
--}}
@php
    $lh = $letterhead
        ?? ($agencyId !== null ? \App\Support\Documents\AgencyLetterhead::forAgencyId((int) $agencyId, $branchId !== null ? (int) $branchId : null) : \App\Support\Documents\AgencyLetterhead::current());
    $contactBits = $lh ? array_values(array_filter([...$lh->phoneLines, $lh->email, $lh->website, $lh->vatLine], fn ($v) => $v !== '')) : [];
@endphp
@if($lh)
<table class="agency-letterhead" style="width:100%; border-collapse:collapse; margin-bottom:{{ $compact ? 4 : 8 }}pt; border-bottom:0.75pt solid #444;"><tr>
    <td style="width:{{ $compact ? 30 : 45 }}%; vertical-align:middle; padding:0 0 {{ $compact ? 3 : 5 }}pt 0;">
        @if($lh->logoDataUri)
            <img src="{{ $lh->logoDataUri }}" alt="{{ $lh->name }}" style="max-height:{{ $compact ? 34 : 52 }}pt; max-width:{{ $compact ? 110 : 170 }}pt;">
        @else
            <span style="font-size:{{ $compact ? 11 : 15 }}pt; font-weight:bold;">{{ $lh->name }}</span>
        @endif
    </td>
    <td style="vertical-align:middle; text-align:right; font-size:{{ $compact ? 7 : 8 }}pt; line-height:1.35; color:#222; padding:0 0 {{ $compact ? 3 : 5 }}pt 0;">
        @if($compact)
            @if($lh->logoDataUri)<strong style="font-size:8.5pt;">{{ $lh->name }}</strong><br>@endif
            @if($lh->addressLines !== []){{ implode(', ', $lh->addressLines) }}<br>@endif
            {{ implode(' · ', $contactBits) }}
        @else
            @if($lh->logoDataUri)<strong style="font-size:10pt;">{{ $lh->name }}</strong><br>@endif
            @foreach($lh->addressLines as $line){{ $line }}<br>@endforeach
            @foreach($lh->phoneLines as $line){{ $line }}<br>@endforeach
            @if($lh->email !== ''){{ $lh->email }}<br>@endif
            @if($lh->website !== ''){{ $lh->website }}<br>@endif
            @if($lh->vatLine !== ''){{ $lh->vatLine }}@endif
        @endif
    </td>
</tr></table>
@endif
