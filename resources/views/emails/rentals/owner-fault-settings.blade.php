<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">How you want faults handled</h1>
    </div>
    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Dear {{ $recipientName }},</p>
        <p>For {{ $propertyAddress }}, this is what is recorded about what your agent may do when something goes wrong, without asking you first:</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin: 12px 0;" data-owner-settings-values>
            @foreach($lines as $label => $value)
                <tr>
                    <td style="padding: 8px 10px; border-bottom: 1px solid #e6e9ef; color: #555;">{{ $label }}</td>
                    <td style="padding: 8px 10px; border-bottom: 1px solid #e6e9ef; font-weight: bold; text-align: right; white-space: nowrap;">{{ $value }}</td>
                </tr>
            @endforeach
        </table>
        <p data-owner-settings-captured>{{ $capturedLine }}</p>
        <p>Until you confirm it, your agent will use these as recorded. If anything is wrong, change it or tell us you do not agree &mdash; if you do not agree, your agent will ask you before every decision.</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;">
            <tr>
                <td width="50%" style="padding-right: 6px;" align="center"><a href="{{ $openUrl }}" data-open-my-settings style="display: block; box-sizing: border-box; background-color: #1a365d; color: #ffffff; border: 2px solid #1a365d; padding: 12px 8px; border-radius: 6px; text-decoration: none; font-weight: bold; text-align: center;">Open my settings</a></td>
                <td width="50%" style="padding-left: 6px;" align="center"><a href="{{ $disagreeUrl }}" data-i-do-not-agree style="display: block; box-sizing: border-box; background-color: #ffffff; color: #1a365d; border: 2px solid #1a365d; padding: 12px 8px; border-radius: 6px; text-decoration: none; font-weight: bold; text-align: center;">I do not agree with this</a></td>
            </tr>
        </table>
        <p style="color: #666; font-size: 13px;">&ldquo;Open my settings&rdquo; asks you to sign in first. &ldquo;I do not agree with this&rdquo; asks you one question before anything is recorded.</p>
    </div>
</body>
</html>
