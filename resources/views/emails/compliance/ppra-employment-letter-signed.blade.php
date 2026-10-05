<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; color:#1e293b; font-size:14px; line-height:1.6;">
    <p>Hi {{ $agent->name }},</p>

    <p>
        {{ $principal->name }} has signed your Confirmation of Employment letter.
        It is now fully signed and ready to download for your FFC renewal.
    </p>

    <p>
        <a href="{{ $downloadUrl }}" style="display:inline-block; padding:10px 18px; background:#0d9488; color:#fff; text-decoration:none; border-radius:6px;">
            View &amp; download the letter
        </a>
    </p>

    <p style="color:#64748b; font-size:12px;">
        If the button doesn't work, copy this link into your browser:<br>
        {{ $downloadUrl }}
    </p>
</body>
</html>
