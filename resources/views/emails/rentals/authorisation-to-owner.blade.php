<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">{{ $capturedForOwner ? 'A decision was recorded for you' : 'Your agent sent someone to look' }}</h1>
    </div>
    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Dear {{ $recipientName }},</p>
        <p>At {{ $propertyAddress }}:</p>
        <p><strong>{{ $title }}</strong></p>
        <p>{{ $sentence }}</p>
        @if($note)
            <p style="color: #555;">What your agent wrote down: &ldquo;{{ $note }}&rdquo;</p>
        @endif
        <p>If this is not right, you can stop it, or ask your agent a question. Asking a question does not stop anything.</p>
        @if($stopUrl && $queryUrl)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 20px 0 8px;">
                <tr>
                    <td width="50%" style="padding-right: 6px;" align="center"><a href="{{ $stopUrl }}" data-stop-this style="display: block; box-sizing: border-box; background-color: #9b1c1c; color: #ffffff; border: 2px solid #9b1c1c; padding: 12px 8px; border-radius: 6px; text-decoration: none; font-weight: bold; text-align: center;">Stop this</a></td>
                    <td width="50%" style="padding-left: 6px;" align="center"><a href="{{ $queryUrl }}" data-query-this style="display: block; box-sizing: border-box; background-color: #ffffff; color: #1a365d; border: 2px solid #1a365d; padding: 12px 8px; border-radius: 6px; text-decoration: none; font-weight: bold; text-align: center;">Query this</a></td>
                </tr>
            </table>
            <p style="color: #666; font-size: 13px; margin-top: 0;">&ldquo;Stop this&rdquo; is possible until the work has started. Each button opens a page that asks before anything is recorded.</p>
        @endif
        <p style="text-align: center; margin: 24px 0;"><a href="{{ $portalUrl }}" style="background-color: #1a365d; color: #ffffff; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold; display: inline-block;">See the details</a></p>
        <p style="color: #666; font-size: 13px;">You will be asked to sign in first.</p>
    </div>
</body>
</html>
