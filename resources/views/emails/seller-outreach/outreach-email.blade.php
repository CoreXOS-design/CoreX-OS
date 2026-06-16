{{--
    Seller-outreach EMAIL channel — branded HTML body.
    Mirrors emails/signatures/signing-request.blade.php and reuses the SAME
    agency-branded signature partial (emails.signatures.partials.agent-footer).

    Props: $recipientName, $body (plaintext, merge fields + per-send links already
    substituted), $agentFooter (from BaseSignatureMail::getAgentFooter()).
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">{{ $agentFooter['agency_name'] ?? 'Home Finders Coastal' }}</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $recipientName }},</p>

        {{-- The merged outreach body. Links (opt-out/opt-in/tracking) are already
             substituted to real URLs; nl2br preserves the message's line breaks. --}}
        <div style="white-space: pre-wrap; color: #333;">{!! nl2br(e($body)) !!}</div>

        @include('emails.signatures.partials.agent-footer')
    </div>

    <div style="text-align: center; padding: 15px; color: #999; font-size: 11px;">
        <p style="margin: 0;">This email was sent by {{ $agentFooter['agency_name'] ?? 'Home Finders Coastal' }}.</p>
        <p style="margin: 5px 0 0;">If you did not expect this email, please disregard it.</p>
    </div>

</body>
</html>
