{{-- AT-534 spec 9.3 — HTML version of a campaign email (only sent when the sender has a signature image). $bodyHtml already escaped by
     CampaignHtmlRenderer; the token marks where the embedded picture goes. A missing file just drops the picture. --}}
@php
    $img = '';
    if (! empty($signatureImagePath) && is_file($signatureImagePath)) {
        $img = '<img src="' . e($message->embed($signatureImagePath)) . '" alt="' . e($fromName ?? '') . '" style="display:block;max-width:420px;height:auto;border:0;">';
    }
@endphp
<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;">
{!! str_replace(\App\Services\Campaigns\CampaignHtmlRenderer::IMAGE_TOKEN, $img, $bodyHtml) !!}
</body>
</html>
