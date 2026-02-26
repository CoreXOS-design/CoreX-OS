<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #c05621; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">Signatures Missing — Action Needed</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $signerName }},</p>

        <p>Your uploaded document for <strong>{{ $documentName }}</strong> was reviewed and some signatures appear to be missing or unclear.</p>

        @if($rejectionNote)
            <div style="background-color: #fff5f5; border-left: 4px solid #c05621; padding: 15px; margin: 20px 0;">
                <p style="margin: 0; font-weight: bold; font-size: 13px;">Reviewer note:</p>
                <p style="margin: 5px 0 0;">{{ $rejectionNote }}</p>
            </div>
        @endif

        <p>Please re-print the document, sign all marked areas, and upload a new scan:</p>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $signingUrl }}" style="display: inline-block; background-color: #c05621; color: #ffffff; padding: 14px 40px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
                RE-UPLOAD SIGNED DOCUMENT
            </a>
        </div>

        <p style="color: #666; font-size: 13px;">
            If you have any questions, simply reply to this email.
        </p>

        @include('emails.signatures.partials.agent-footer')
    </div>

    <div style="text-align: center; padding: 15px; color: #999; font-size: 11px;">
        <p style="margin: 0;">This email was sent by Home Finders Coastal's document signing system.</p>
    </div>

</body>
</html>
