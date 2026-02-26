<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #276749; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">All Signatures Complete</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $recipientName }},</p>

        <p>All parties have signed <strong>{{ $documentName }}</strong>.</p>

        @if(!empty($progress))
            <div style="background-color: #f7fafc; border: 1px solid #e0e0e0; border-radius: 6px; padding: 15px; margin: 20px 0;">
                <p style="margin: 0 0 10px; font-weight: bold; font-size: 13px;">Signed by:</p>
                @foreach($progress as $role => $party)
                    <div style="padding: 4px 0; font-size: 13px;">
                        &#10004;&#65039; {{ $party['name'] }} ({{ ucfirst(str_replace('_', ' ', $role)) }})
                        @if($party['completed_at'])
                            — {{ $party['completed_at']->format('d M Y') }}
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if($pdfPath)
            <div style="background-color: #f0fff4; border-left: 4px solid #276749; padding: 15px; margin: 20px 0;">
                <p style="margin: 0;">Your signed copy is attached to this email. It includes a Certificate of Authenticity with the full audit trail.</p>
            </div>
        @else
            <div style="background-color: #f0fff4; border-left: 4px solid #276749; padding: 15px; margin: 20px 0;">
                <p style="margin: 0;">The document is now fully executed. You can view or download a copy below.</p>
            </div>
        @endif

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $envelopeUrl }}" style="display: inline-block; background-color: #276749; color: #ffffff; padding: 14px 40px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
                VIEW DOCUMENT
            </a>
        </div>

        <p style="color: #666; font-size: 12px;">
            This document was signed in accordance with the Electronic Communications and Transactions Act 25 of 2002.
        </p>

        @include('emails.signatures.partials.agent-footer')
    </div>

    <div style="text-align: center; padding: 15px; color: #999; font-size: 11px;">
        <p style="margin: 0;">This email was sent by Home Finders Coastal's document signing system.</p>
    </div>

</body>
</html>
