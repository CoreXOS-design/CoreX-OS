{{--
    §49 — the PDF download on a party's signing page. Johan, 8 Oct 2026: a tenant / landlord can download the report as a PDF
    ONLY once every party has signed (and it stays available after). Until then there is no download — the party reads the
    report on screen, and is told when the PDF becomes available. The server refuses the download too (RentalInspectionSigningController::pdf()).
    Expects $signing (with pdf_available / pdf_url) and optional $cls (the button's classes).
--}}
@if(! empty($signing['pdf_available']))
    <a href="{{ $signing['pdf_url'] }}" class="mt-3 inline-block text-sm font-semibold px-4 py-2 rounded-md {{ $cls ?? 'bg-slate-800 text-white' }}" data-qa="download-report">Download the report (PDF)</a>
@else
    <p class="mt-3 text-sm text-slate-500" data-qa="download-not-yet">A PDF copy of the report becomes available once everyone has signed it. You can read the whole report on this page in the meantime, and come back to this link to download the PDF later.</p>
@endif
