{{-- The agent's own copy of an inspection report - a STAFF mail (RentalInspectionAgentCopyMail), so it may say CoreX. Clients get inspection-signing-link, which never does. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #276749; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">Inspection report</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $recipientName }},</p>

        @if($reSign)
            <p style="background-color: #fffbeb; border-left: 4px solid #d97706; padding: 12px;">
                <strong>This report has been changed since you last signed it.</strong>
                Your earlier signature no longer counts, so please read the changed report and sign it again.
            </p>
        @endif

        <p>
            The {{ strtolower($inspectionLabel) }} report for <strong>{{ $propertyAddress }}</strong> is ready to read.
            You sign it inside CoreX with your own login and signing PIN: <strong>open the inspection in CoreX, find the Agent line and press &ldquo;Sign with PIN&rdquo;</strong>
            (it appears once everyone else has signed). The button below is for reading the report only.
        </p>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $signingUrl }}" style="display: inline-block; background-color: #276749; color: #ffffff; padding: 14px 40px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
                READ THE REPORT
            </a>
        </div>

        @if(! empty($openInCoreXUrl))
        <div style="text-align: center; margin: 0 0 24px;">
            <a href="{{ $openInCoreXUrl }}" style="display: inline-block; background-color: #ffffff; color: #276749; border: 2px solid #276749; padding: 12px 32px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 15px;">
                OPEN THE INSPECTION IN COREX
            </a>
        </div>
        @endif

        <p style="font-size: 13px; color: #666;">
            This link is personal to you — please do not forward it.
            It stops working on {{ $expiresOn }}.
            If the button does not work, copy this address into your browser:<br>
            <span style="word-break: break-all;">{{ $signingUrl }}</span>
        </p>

        @include('emails.signatures.partials.agent-footer')
    </div>

    <div style="text-align: center; padding: 15px; color: #999; font-size: 11px;">
        <p style="margin: 0;">This email was sent by {{ $agentFooter['agency_name'] ?? \App\Support\ClientBrand::NEUTRAL }}.</p>
    </div>

</body>
</html>
