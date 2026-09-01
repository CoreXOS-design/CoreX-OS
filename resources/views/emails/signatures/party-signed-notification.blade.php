<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">Signature Review Required</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $agentName }},</p>

        <p>
            <strong>{{ $partyName }}</strong> ({{ ucfirst($partyRole) }}) has completed signing the following document:
        </p>

        <div style="background-color: #f7fafc; border-left: 4px solid #1a365d; padding: 15px; margin: 20px 0;">
            <strong>{{ $documentName }}</strong>
        </div>

        <div style="background-color: #fffbeb; border-left: 4px solid #d69e2e; padding: 15px; margin: 20px 0;">
            <p style="margin: 0; font-weight: bold; color: #92400e;">Action Required</p>
            <p style="margin: 5px 0 0; color: #78350f;">
                Please review the signatures and approve to advance the document to the next party, or complete the signing process.
            </p>
        </div>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $reviewUrl }}" style="display: inline-block; background-color: #d97706; color: #ffffff; padding: 14px 40px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
                REVIEW &amp; APPROVE
            </a>
        </div>

        <p style="color: #666; font-size: 13px;">
            The document will not advance until you review and approve. Simply reply to this email if you have any questions.
        </p>
    </div>

    <div style="text-align: center; padding: 15px; color: #999; font-size: 11px;">
        <p style="margin: 0;">This is an internal notification from {{ $agentFooter['agency_name'] ?? config('app.name') }}'s document signing system.</p>
    </div>

</body>
</html>
