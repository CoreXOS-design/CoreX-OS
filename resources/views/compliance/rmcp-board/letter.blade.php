<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Board approval of the RMCP - version {{ $letter->version?->version_number }}</title>
<style>
    @page { size: A4; margin: 18mm 16mm; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: 11pt; color: #1a2733; line-height: 1.55; }
    .logo { max-height: 18mm; margin-bottom: 6mm; }
    .kicker { text-transform: uppercase; letter-spacing: 1.5px; font-size: 9pt; color: {{ $accent }}; margin: 0 0 2mm; }
    h1 { font-size: 17pt; margin: 0 0 4mm; color: #0b2a4a; }
    .entity { margin: 0 0 6mm; }
    ol li { margin-bottom: 3mm; }
    .fingerprint { font-size: 8.5pt; color: #64748b; margin-top: 6mm; }
    table.sigs { width: 100%; border-collapse: collapse; margin-top: 8mm; }
    table.sigs td { width: 50%; vertical-align: top; padding: 0 6mm 8mm 0; page-break-inside: avoid; }
    .sigbox { border-bottom: 1px solid #1a2733; height: 20mm; display: flex; align-items: flex-end; }
    .sigbox img { max-height: 18mm; max-width: 100%; }
    .typed { font-family: 'Times New Roman', serif; font-style: italic; font-size: 17pt; }
    .who { margin-top: 1.5mm; font-size: 10pt; }
    .meta { font-size: 8.5pt; color: #64748b; }
    .pending { color: #b45309; font-size: 9pt; }
</style>
</head>
<body>
    @if(! empty($logo))<img class="logo" src="{{ $logo }}" alt="">@endif
    {!! $letter->letter_html !!}

    <table class="sigs">
        @foreach($letter->signatures->chunk(2) as $row)
        <tr>
            @foreach($row as $sig)
            <td>
                <div class="sigbox">
                    @if($sig->isSigned() && ! empty($sigImages[$sig->id]))
                        <img src="{{ $sigImages[$sig->id] }}" alt="Signature of {{ $sig->member_name }}">
                    @elseif($sig->isSigned())
                        <span class="typed">{{ $sig->typed_name ?: $sig->member_name }}</span>
                    @else
                        <span class="pending">Not signed yet</span>
                    @endif
                </div>
                <div class="who"><strong>{{ $sig->member_name }}</strong><br>{{ $sig->member_title }}</div>
                <div class="meta">
                    @if($sig->isSigned())Signed {{ $sig->signed_at->timezone(config('app.timezone'))->format('d F Y, H:i') }}@endif
                </div>
            </td>
            @endforeach
            @if($row->count() === 1)<td></td>@endif
        </tr>
        @endforeach
    </table>
</body>
</html>
