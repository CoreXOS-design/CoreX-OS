<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Wet-ink signature scan</title>
<style>
    {{--
        Conductor brief 2026-09-29 — SignedDocumentDistributionService::
        fileToProperty() only ever writes a PDF (mime_type is hardcoded
        'application/pdf'). A wet-ink upload photographed as a jpg/png/heic
        is wrapped in this one-page PDF before filing so the property's
        Document store stays consistently PDF, the same way every other
        document this service files already is. An already-PDF upload is
        filed as its own raw bytes instead — this view is only used for the
        image case.
    --}}
    @page { margin: 24pt; size: A4 portrait; }
    body { margin: 0; padding: 0; font-family: Helvetica, Arial, sans-serif; color: #111; font-size: 9pt; }
    .muted { color: #666; }
    .heading { margin-bottom: 10pt; }
    .scan-img { max-width: 100%; max-height: 720pt; }
</style>
</head>
<body>
    <div class="heading">
        <p class="muted" style="text-transform:uppercase; font-size:7.5pt; letter-spacing:0.5pt;">Wet-ink signature — scan on file</p>
        <p><strong>{{ $propertyAddress }}</strong></p>
        <p class="muted">{{ $partyLabel }} &middot; uploaded {{ $uploadedAt }}</p>
    </div>
    <img class="scan-img" src="{{ $imageDataUri }}" alt="{{ $partyLabel }} wet-ink signature scan">
</body>
</html>
