<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    @php
        $headerColor = match($tone) {
            'final'  => '#9b2c2c',
            'firm'   => '#c05621',
            'manual' => '#1a365d',
            default  => '#1a365d',
        };
    @endphp

    <div style="background-color: {{ $headerColor }}; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">
            @if($tone === 'final')
                Final Reminder — Signature Needed
            @elseif($tone === 'firm')
                Reminder — Signature Needed
            @elseif($tone === 'manual')
                Reminder — Signature Needed
            @else
                Friendly Reminder
            @endif
        </h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $signerName }},</p>

        @if(!empty($customBody))
            {!! nl2br(e($customBody)) !!}
        @elseif($tone === 'final')
            <p>This is a <strong>final reminder</strong> — your signature on the document below is still outstanding and the link will expire soon.</p>
        @elseif($tone === 'firm')
            <p>Just a reminder that your signature is still needed on the following document:</p>
        @elseif($tone === 'manual')
            <p>{{ $agentFooter['name'] }} has sent you a reminder to sign the following document:</p>
        @else
            <p>Just a friendly reminder — the following document is waiting for your signature:</p>
        @endif

        <div style="background-color: #f7fafc; border-left: 4px solid {{ $headerColor }}; padding: 15px; margin: 20px 0;">
            <strong>{{ $documentName }}</strong>
        </div>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $signingUrl }}" style="display: inline-block; background-color: {{ $headerColor }}; color: #ffffff; padding: 14px 40px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
                SIGN NOW
            </a>
        </div>

        <p style="color: #666; font-size: 13px;">
            This signing link expires on <strong>{{ $expiresAt->format('d M Y') }}</strong>.
            If you have any questions, simply reply to this email.
        </p>

        @include('emails.signatures.partials.agent-footer')
    </div>

    <div style="text-align: center; padding: 15px; color: #999; font-size: 11px;">
        <p style="margin: 0;">This email was sent by Home Finders Coastal's document signing system.</p>
        <p style="margin: 5px 0 0;">If you did not expect this email, please disregard it.</p>
    </div>

</body>
</html>
