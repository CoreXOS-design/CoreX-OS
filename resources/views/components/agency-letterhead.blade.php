@props(['letterhead' => null, 'agencyId' => null, 'branchId' => null])
{{--
    THE agency letterhead (header). One component for every document an agency's name goes on; the values come from the agency's
    settings through App\Support\Documents\AgencyLetterhead. Table layout only so DomPDF and a browser draw the same thing.
    Pass `letterhead` (already resolved), or `agency-id` (+ optional `branch-id`), or nothing for the document's / signed-in agency.
    Prints nothing when there is no agency. A blank setting prints no line at all.
--}}
@php
    $lh = $letterhead
        ?? ($agencyId !== null ? \App\Support\Documents\AgencyLetterhead::forAgencyId((int) $agencyId, $branchId !== null ? (int) $branchId : null) : \App\Support\Documents\AgencyLetterhead::current());
@endphp
@if($lh)
<table class="agency-letterhead" style="width:100%; border-collapse:collapse; margin-bottom:8pt; border-bottom:0.75pt solid #444;"><tr>
    <td style="width:45%; vertical-align:middle; padding:0 0 5pt 0;">
        @if($lh->logoDataUri)
            <img src="{{ $lh->logoDataUri }}" alt="{{ $lh->name }}" style="max-height:52pt; max-width:170pt;">
        @else
            <span style="font-size:15pt; font-weight:bold;">{{ $lh->name }}</span>
        @endif
    </td>
    <td style="vertical-align:middle; text-align:right; font-size:8pt; line-height:1.35; color:#222; padding:0 0 5pt 0;">
        @if($lh->logoDataUri)<strong style="font-size:10pt;">{{ $lh->name }}</strong><br>@endif
        @foreach($lh->addressLines as $line){{ $line }}<br>@endforeach
        @foreach($lh->phoneLines as $line){{ $line }}<br>@endforeach
        @if($lh->email !== ''){{ $lh->email }}<br>@endif
        @if($lh->website !== ''){{ $lh->website }}<br>@endif
        @if($lh->vatLine !== ''){{ $lh->vatLine }}@endif
    </td>
</tr></table>
@endif
