<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PPRA Confirmation of Employment — {{ $agent->name }}</title>
    <style>
        {{-- Reproduces the agency's Word "letter of employment" (.ai/specs/ppra-ffc-employment-letter.md §18).
             The letterhead is the shared company-header component (Company Settings), not drawn here. --}}
        @page {
            size: A4;
            margin: 14mm 18mm 20mm 18mm;
            @bottom-right { content: "Page " counter(page) " of " counter(pages); font-family: Arial, Helvetica, sans-serif; font-size: 11pt; color: #000; }
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 11pt; color: #000; line-height: 1.35; }
        .letter-date { margin-top: 14pt; margin-bottom: 14pt; }
        .ppra-address { margin-bottom: 14pt; }
        .letter-re { font-weight: 700; margin-bottom: 14pt; }
        .letter-body { margin-bottom: 14pt; }
        .mentor-heading { font-weight: 700; margin-bottom: 6pt; }
        table.mentor-table { width: 100%; border-collapse: collapse; margin-bottom: 22pt; }
        table.mentor-table td { border: 1px solid #000; padding: 4pt 6pt; text-align: left; vertical-align: top; width: 50%; }
        table.sign-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.sign-table td { vertical-align: bottom; padding: 0; }
        table.sign-table td.gap { width: 8%; }
        table.sign-table td.col { width: 46%; }
        .sign-heading { font-weight: 700; }
        .sign-img-cell { height: 62pt; border-bottom: 1px dashed #000; }
        .sign-img-cell img { max-height: 58pt; max-width: 100%; display: block; }
        .sign-name { padding-top: 3pt; vertical-align: top; }
        .sign-title { font-weight: 700; vertical-align: top; }
    </style>
</head>
<body>
    {{-- The agency's own letterhead, exactly as Company Settings builds it (branch overrides when the letter is branch-bound). --}}
    @if($branch)
        @include('docuperfect.web-templates.components.company-header', ['branch' => $branch, 'logo_url' => $logoData])
    @else
        @include('docuperfect.web-templates.components.company-header', ['previewAgency' => $agency, 'logo_url' => $logoData])
    @endif

    <div class="letter-date">Date {{ $letterDate }}</div>

    <div class="ppra-address">{!! nl2br(e(trim($ppraAddressBlock))) !!}</div>

    <div class="letter-re">RE: CONFIRMATION OF EMPLOYMENT FOR A {{ $ppraCategory }}</div>

    <div class="letter-body">
        This serves to confirm that ({{ strtoupper(trim($agentFirstNames . ' ' . $agentSurname)) }}), ID number ({{ $agentIdNumber }})
        seven digit reference number ({{ $agentFfcNumber }}) is in the employ of
        (<strong>{{ $companyLine }}</strong>) (<strong>{{ $agencyPpraNumber }}</strong>).
    </div>

    <div class="mentor-heading">Mentor's details:</div>
    <table class="mentor-table">
        <tr><td>Name</td><td>{{ $principalFirstNames }}</td></tr>
        <tr><td>Surname</td><td>{{ $principalSurname }}</td></tr>
        <tr><td>Seven digit reference number:</td><td>{{ $principalFfcNumber }}</td></tr>
    </table>

    <table class="sign-table">
        <tr>
            <td class="col sign-heading">Yours faithfully</td>
            <td class="gap"></td>
            <td class="col sign-heading">Employment accepted by</td>
        </tr>
        <tr>
            <td class="col sign-img-cell">@if($principalSignatureImage)<img src="{{ $principalSignatureImage }}" alt="Principal signature">@endif</td>
            <td class="gap"></td>
            <td class="col sign-img-cell">@if($agentSignatureImage)<img src="{{ $agentSignatureImage }}" alt="Practitioner signature">@endif</td>
        </tr>
        <tr>
            <td class="col sign-name">{{ $principal ? trim($principal->letterFirstNames() . ' ' . $principal->letterSurname()) : '' }}</td>
            <td class="gap"></td>
            <td class="col sign-name">{{ trim($agentFirstNames . ' ' . $agentSurname) }}</td>
        </tr>
        <tr>
            <td class="col sign-title">Principal Estate Agent</td>
            <td class="gap"></td>
            <td class="col sign-title">{{ $ppraCategoryLabel }}</td>
        </tr>
    </table>
</body>
</html>
