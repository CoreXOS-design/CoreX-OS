{{--
    Evaluation Certificate — dompdf render view (Phase 2, cc6).
    Spec: .ai/specs/EVALUATION_CERTIFICATE_REDESIGN.md
    Rendered via Barryvdh\DomPDF\Facade\Pdf::loadView('tools.evaluation-certificate.pdf', [...]).

    TERMINOLOGY (legal, non-negotiable): "evaluation", never "valuation".

    Data contract (build against App\Models\EvaluationCertificate — cc3 Phase 1):
      $certificate  EvaluationCertificate  (address, property_type, analysis_date,
                    estimated_market_value, bedrooms, bathrooms, parking, key_features,
                    contact(), property(), signedBy(), authorisedBy())

    SIGNATURE-BLOCK SLOTS (cc1 Phase-4 bakes the saved-signature PNG data-URIs in at
    sign time for an immutable filed artifact — pass these to loadView):
      $signatureImage  ?string  data:image/png;base64,...  (signer's signature)  — null when unsigned
      $initialImage    ?string  data:image/png;base64,...  (signer's initials)   — null when unsigned
    Both are OPTIONAL: the view renders a blank ruled line when a slot is null (preview/unsigned).
--}}
@php
    /** @var \App\Models\EvaluationCertificate $certificate */
    $signatureImage           = $signatureImage           ?? null;   // "Evaluated & signed by" mark
    $authoriserSignatureImage = $authoriserSignatureImage ?? null;   // "Authorised by" mark (candidate flow)
    $logoData       = $logoData       ?? null;                                   // agency logo, base64 data-URI (cc1)
    $showAuthoriser = $showAuthoriser ?? (bool) $certificate->authorised_by_user_id; // candidate flow only

    $fmtMoney = static function ($v) {
        return $v === null || $v === '' ? '—' : 'R ' . number_format((int) $v);
    };
    $analysisDate = $certificate->analysis_date
        ? $certificate->analysis_date->format('j F Y')
        : null;
    $contactName = $certificate->contact?->full_name
        ?? $certificate->contact?->name
        ?? null;
    $signerName            = $certificate->signedBy?->name;
    $signerDesignation     = $certificate->signedBy?->designation;      // staff/user title (e.g. Candidate Property Practitioner)
    $authoriserName        = $certificate->authorisedBy?->name;
    $authoriserDesignation = $certificate->authorisedBy?->designation;
    $agencyName            = $certificate->agency?->name ?? config('app.name');

    // key_features: column is a plain string; tolerate a JSON array too.
    $features = [];
    if (!empty($certificate->key_features)) {
        $decoded = json_decode((string) $certificate->key_features, true);
        $features = is_array($decoded)
            ? array_values(array_filter(array_map('trim', $decoded), 'strlen'))
            : array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $certificate->key_features)), 'strlen'));
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Evaluation Certificate</title>
<style>
    @page { margin: 22mm 18mm 20mm 18mm; }
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; color: #1f2430; font-size: 11px; line-height: 1.45; margin: 0; }
    .doc-title { font-size: 22px; font-weight: 700; letter-spacing: .5px; color: #14315a; margin: 0; }
    .doc-sub { font-size: 10px; color: #6b7280; margin: 2px 0 0; text-transform: uppercase; letter-spacing: 1px; }
    .rule { border: 0; border-top: 2px solid #14315a; margin: 10px 0 14px; }
    table { border-collapse: collapse; width: 100%; }
    .header-tbl td { vertical-align: top; }
    .agency { font-size: 13px; font-weight: 700; color: #14315a; }
    .meta { text-align: right; font-size: 10px; color: #6b7280; }
    .section-h { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px;
                 color: #14315a; border-bottom: 1px solid #d5dbe5; padding-bottom: 3px; margin: 16px 0 8px; }
    .kv td { padding: 3px 0; vertical-align: top; }
    .kv .k { color: #6b7280; width: 34%; }
    .kv .v { color: #1f2430; font-weight: 600; }
    .value-box { background: #f3f6fb; border: 1px solid #d5dbe5; border-radius: 4px; padding: 12px 14px; margin: 4px 0 2px; }
    .value-box .lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; }
    .value-box .amt { font-size: 24px; font-weight: 700; color: #14315a; }
    .attr-tbl td { width: 33%; padding: 6px 4px; text-align: center; border: 1px solid #e3e8f0; }
    .attr-tbl .an { font-size: 18px; font-weight: 700; color: #14315a; }
    .attr-tbl .al { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; }
    ul.features { margin: 4px 0 0; padding-left: 16px; }
    ul.features li { margin: 2px 0; }
    /* Full e-sign company letterhead reused verbatim (components/company-header),
       adapted for dompdf: it was authored for the Chromium renderer, so override the
       CSS grid (contact strip) to a table and constrain the logo — dompdf ignores
       object-fit (AT-367), which would otherwise stretch/distort it. Scoped to the
       certificate only; the shared component is untouched. */
    .ec-letterhead { margin-bottom: 10px; }
    .ec-letterhead .company-header-contact-grid { display: table !important; width: 100% !important; }
    .ec-letterhead .company-header-contact-grid > div { display: table-cell !important; width: 50% !important; vertical-align: top; }
    .ec-letterhead img { width: auto !important; height: auto !important; max-height: 74px !important; max-width: 280px !important; }
    /* Signature block — a 2-row TABLE so both columns align in dompdf. Row 1 = the
       signature BOXES: fixed-height table cells with a bottom border (the ruled line),
       which dompdf keeps at an IDENTICAL baseline whether a box holds a signature image
       or is empty (unlike bottom-aligned divs, which collapse + drift). Row 2 = the
       captions, top-aligned so both labels + names sit on the same level too. */
    .sign-tbl { margin-top: 34px; width: 100%; }
    .sign-tbl .sig-box { width: 50%; height: 52px; vertical-align: bottom; border-bottom: 1px solid #333; padding: 0 10px 3px; }
    .sign-tbl .sig-cap { width: 50%; vertical-align: top; padding: 5px 10px 0; }
    .sig-img { max-height: 50px; max-width: 220px; }
    .sig-role { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; }
    .sig-name { font-weight: 700; }
    .sig-desig { font-size: 9px; color: #6b7280; }
    .foot { margin-top: 22px; font-size: 8.5px; color: #9aa3b2; text-align: center; line-height: 1.5; }
</style>
</head>
<body>

    {{-- FULL company letterhead — the SAME block the e-sign documents use (shared
         component). previewAgency ties it to THIS certificate's agency (correct on the
         public/client render, no auth); logo_url embeds the base64 logo for a
         self-contained dompdf render. --}}
    <div class="ec-letterhead">
        @include('docuperfect.web-templates.components.company-header', [
            'previewAgency' => $certificate->agency,
            'logo_url'      => $logoData,
        ])
    </div>

    <table class="header-tbl" style="margin-bottom:4px;">
        <tr>
            <td>@if($contactName)<span style="font-size:10px;color:#6b7280;">Prepared for {{ $contactName }}</span>@endif</td>
            <td class="meta">
                @if($analysisDate)Evaluation date: {{ $analysisDate }}<br>@endif
                Ref: EC-{{ $certificate->id ?? 'DRAFT' }}
            </td>
        </tr>
    </table>

    <hr class="rule">
    <h1 class="doc-title">Evaluation Certificate</h1>
    <p class="doc-sub">Property Evaluation Summary</p>

    <div class="section-h">Property</div>
    <table class="kv">
        <tr><td class="k">Address</td><td class="v">{{ $certificate->address ?: '—' }}</td></tr>
        <tr><td class="k">Property type</td><td class="v">{{ $certificate->property_type ?: '—' }}</td></tr>
        @if($analysisDate)<tr><td class="k">Date of evaluation</td><td class="v">{{ $analysisDate }}</td></tr>@endif
    </table>

    <div class="section-h">Estimated Market Value</div>
    <div class="value-box">
        <div class="lbl">Estimated market value</div>
        <div class="amt">{{ $fmtMoney($certificate->estimated_market_value) }}</div>
    </div>

    @if($certificate->bedrooms !== null || $certificate->bathrooms !== null || $certificate->parking !== null)
        <div class="section-h">Accommodation</div>
        <table class="attr-tbl">
            <tr>
                <td><div class="an">{{ $certificate->bedrooms ?? '—' }}</div><div class="al">Bedrooms</div></td>
                <td><div class="an">{{ $certificate->bathrooms ?? '—' }}</div><div class="al">Bathrooms</div></td>
                <td><div class="an">{{ $certificate->parking ?? '—' }}</div><div class="al">Parking</div></td>
            </tr>
        </table>
    @endif

    @if(count($features))
        <div class="section-h">Key features</div>
        <ul class="features">
            @foreach($features as $feature)<li>{{ $feature }}</li>@endforeach
        </ul>
    @endif

    {{-- SIGNATURE BLOCK — two-row table so both columns stay LEVEL in dompdf.
         Row 1 (sig-box): each column's signature sits above its ruled line (the cell's
         bottom border); fixed cell height keeps both lines on the same baseline whether a
         box holds a signature or is empty. Row 2 (sig-cap): the labels + names, top-aligned.
         $signatureImage = the candidate/direct signer's mark (baked at sign, or the
         candidate's snapshot on a pending preview); $authoriserSignatureImage = the
         full-status authoriser's mark. The "Authorised by" column renders only for the
         candidate flow ($showAuthoriser). --}}
    <table class="sign-tbl">
        <tr>
            <td class="sig-box">@if($signatureImage)<img class="sig-img" src="{{ $signatureImage }}" alt="signature">@endif</td>
            @if($showAuthoriser)
            <td class="sig-box">@if($authoriserSignatureImage)<img class="sig-img" src="{{ $authoriserSignatureImage }}" alt="authoriser signature">@endif</td>
            @endif
        </tr>
        <tr>
            <td class="sig-cap">
                <span class="sig-role">Evaluated &amp; signed by</span><br>
                <span class="sig-name">{{ $signerName ?: '' }}</span>
                @if($signerDesignation)<br><span class="sig-desig">{{ $signerDesignation }}</span>@endif
            </td>
            @if($showAuthoriser)
            <td class="sig-cap">
                <span class="sig-role">Authorised by</span><br>
                <span class="sig-name">{{ $authoriserName ?: '' }}</span>
                @if($authoriserDesignation)<br><span class="sig-desig">{{ $authoriserDesignation }}</span>@endif
            </td>
            @endif
        </tr>
    </table>

    <div class="foot">
        This evaluation certificate reflects a registered property practitioner's opinion of market value. It is not a
        formal sworn property assessment and should not be relied upon as one. Generated by {{ $agencyName }} via CoreX OS.
    </div>

</body>
</html>
