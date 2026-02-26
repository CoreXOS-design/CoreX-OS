<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #2b6cb0; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">Wet Ink Upload — Review Needed</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p><strong>{{ $signerName }}</strong> has uploaded a wet-ink signed copy of <strong>{{ $documentName }}</strong>.</p>

        <p>Please review the upload to verify all signatures are present and legible.</p>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $inspectUrl }}" style="display: inline-block; background-color: #2b6cb0; color: #ffffff; padding: 14px 40px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
                REVIEW NOW
            </a>
        </div>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
            <p style="margin: 0; color: #999; font-size: 12px;">Home Finders Coastal — Document Signing System</p>
        </div>
    </div>

</body>
</html>
