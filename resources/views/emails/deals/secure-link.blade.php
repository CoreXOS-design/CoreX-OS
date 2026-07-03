<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #0b2a4a; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 20px;">A document is ready for you</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $recipientName }},</p>

        <p>A secure document — <strong>{{ $documentTitle }}</strong> — has been shared with you
            @if($propertyAddress) in respect of <strong>{{ $propertyAddress }}</strong>@endif
            (reference {{ $dealReference }}).</p>

        @if($messageLine)
            <p>{{ $messageLine }}</p>
        @endif

        <div style="text-align: center; margin: 28px 0;">
            <a href="{{ $secureUrl }}" style="background-color: #0b2a4a; color: #ffffff; padding: 12px 28px; border-radius: 6px; text-decoration: none; font-weight: bold; display: inline-block;">Open the document</a>
        </div>

        <div style="background-color: #f8fafc; border-left: 4px solid #0b2a4a; padding: 14px; margin: 20px 0; font-size: 13px;">
            <p style="margin: 0;">For your protection, opening the link asks for a one-time PIN sent to this
                email address before the document is shown. The link is unique to you — please don't forward it.</p>
        </div>

        <p style="font-size: 12px; color: #777;">If the button doesn't work, copy and paste this link:<br>
            <span style="word-break: break-all;">{{ $secureUrl }}</span></p>
    </div>

    @include('emails.signatures.partials.agent-footer', ['agentFooter' => $agentFooter])
</body>
</html>
