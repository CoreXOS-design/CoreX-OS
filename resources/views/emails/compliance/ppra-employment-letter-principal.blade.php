<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; color:#1e293b; font-size:14px; line-height:1.6;">
    <p>Hi {{ $principal->name }},</p>

    <p>
        {{ $agent->name }} has signed their PPRA Confirmation of Employment letter
        for their FFC renewal, and it now awaits your signature as the agency's
        principal.
    </p>

    <p>
        <a href="{{ $signUrl }}" style="display:inline-block; padding:10px 18px; background:#0d9488; color:#fff; text-decoration:none; border-radius:6px;">
            Open &amp; sign the letter
        </a>
    </p>

    <p style="color:#64748b; font-size:12px;">
        If the button doesn't work, copy this link into your browser:<br>
        {{ $signUrl }}
    </p>
</body>
</html>
