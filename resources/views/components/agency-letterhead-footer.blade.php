@props(['letterhead' => null, 'agencyId' => null, 'branchId' => null, 'fixed' => false])
{{--
    THE agency letterhead (footer line): "<legal name> | Reg No <reg> | PPRA Reg <ppra>". `fixed` pins it to the bottom of every
    page of a DomPDF document (inside the 36pt page margin); off, it is an ordinary block at the end of a web page.
--}}
@php
    $lh = $letterhead
        ?? ($agencyId !== null ? \App\Support\Documents\AgencyLetterhead::forAgencyId((int) $agencyId, $branchId !== null ? (int) $branchId : null) : \App\Support\Documents\AgencyLetterhead::current());
@endphp
@if($lh && $lh->footerLine !== '')
<div class="agency-letterhead-footer" style="{{ $fixed ? 'position:fixed; bottom:-24pt; left:0; right:0;' : 'margin-top:14pt;' }} text-align:center; font-size:7pt; color:#555; border-top:0.5pt solid #999; padding-top:3pt;">{{ $lh->footerLine }}</div>
@endif
