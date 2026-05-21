<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    {{-- Header --}}
    <div style="background-color: #92400e; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">
            @if($reviewType === 'final_signoff')
                Final sign-off needed
            @else
                Supervisor approval needed
            @endif
        </h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $supervisorFirstName }},</p>

        <p>
            <strong>{{ $candidateName }}</strong> has prepared
            @if($documentTypeLabel)
                a <strong>{{ $documentTypeLabel }}</strong>
            @else
                a document
            @endif
            that needs your
            @if($reviewType === 'final_signoff')
                final sign-off
            @else
                supervisor approval
            @endif
            before it can be dispatched
            @if($contactName)
                to <strong>{{ $contactName }}</strong>@if(!$propertyAddress).@endif
            @endif
            @if($propertyAddress)
                for <strong>{{ $propertyAddress }}</strong>.
            @elseif(!$contactName)
                .
            @endif
        </p>

        <div style="background-color: #f7fafc; border-left: 4px solid #92400e; padding: 15px; margin: 20px 0;">
            <strong>Document:</strong> {{ $documentName }}<br>
            @if($propertyAddress)
                <strong>Property:</strong> {{ $propertyAddress }}<br>
            @endif
            @if($contactName)
                <strong>Recipient:</strong> {{ $contactName }}<br>
            @endif
            <strong>Prepared by:</strong> {{ $candidateName }}
        </div>

        {{-- Statutory reminder — Property Practitioners Act s.35 supervision --}}
        <div style="background-color: #fffbeb; border-left: 4px solid #d69e2e; padding: 15px; margin: 20px 0; font-size: 13px;">
            <strong>Why this needs your sign-off</strong><br>
            Section 35 of the Property Practitioners Act 22 of 2019 requires
            candidate practitioners to operate under principal supervision.
            Material agreements prepared by a candidate must be reviewed and
            approved by you before they are released to the parties.
        </div>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $reviewUrl }}" style="display: inline-block; background-color: #92400e; color: #ffffff; padding: 14px 40px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
                REVIEW &amp; APPROVE
            </a>
        </div>

        @if($candidatePhone)
            <p style="font-size: 13px; color: #555;">
                If you have questions, {{ $candidateFirstName }} can be reached on
                <a href="tel:{{ $candidatePhone }}">{{ $candidatePhone }}</a>.
            </p>
        @endif

        <p style="color: #666; font-size: 13px;">
            This approval link expires on <strong>{{ $expiresAt->format('d M Y') }}</strong>.
            Any eligible authoriser in the branch can action this — first one in wins.
        </p>

        @include('emails.signatures.partials.agent-footer')
    </div>

    <div style="text-align: center; padding: 15px; color: #999; font-size: 11px;">
        <p style="margin: 0;">Sent by Home Finders Coastal's document signing system.</p>
        <p style="margin: 5px 0 0;">If you did not expect this email, please disregard it.</p>
    </div>

</body>
</html>
