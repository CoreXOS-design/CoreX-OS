{{--
    The two actions for the client's "Questions & answers" form: a PDF to keep on file and a print page.
    ONE partial, included in the PAGE HEADER ACTION AREA of BOTH review screens (compliance.fica.show and
    compliance.fica.compliance-review) so the controls sit in exactly one place on each and are named the same.
    (Johan, 7 Oct 2026: they used to sit inside the Questions & answers panel as well as under a header
    "Download PDF" — two PDF buttons in two places was confusing.)

    Shown by the SAME rule the two routes enforce (FicaQuestionsAnswersDocument::available): online intake the
    client has completed. A paper (wet-ink) form or an unfinished form shows nothing — the routes would 404.
    Needs only $submission. Access is already gated by both screens (access_compliance + own/branch/agency scope).
--}}
@if(app(\App\Services\Compliance\FicaQuestionsAnswersDocument::class)->available($submission))
    <div class="flex flex-wrap items-center gap-2" data-fica-qa-actions>
        <a href="{{ route('compliance.fica.questions-answers.pdf', $submission) }}" class="corex-btn-outline text-xs" data-fica-qa-pdf
           title="The questions the client was asked, with their answers and signature, as a PDF for the file">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
            Download questions &amp; answers (PDF)
        </a>
        <a href="{{ route('compliance.fica.questions-answers.print', $submission) }}" target="_blank" rel="noopener" class="corex-btn-outline text-xs" data-fica-qa-print
           title="Open the questions the client was asked, with their answers and signature, ready to print">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" /></svg>
            Print questions &amp; answers
        </a>
    </div>
@endif
