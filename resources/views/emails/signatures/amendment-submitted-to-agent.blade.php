<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Your proposed amendments are under review</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; max-width: 640px; margin: 0 auto; padding: 20px;">
    <p>Dear {{ $recipientName }},</p>

    <p>Thank you for reviewing <strong>{{ $documentName }}</strong>. We've received your proposed amendments and they are now under review by <strong>{{ $agentName }}</strong>.</p>

    <h3 style="color:#92400e; margin-top:24px;">Clause {{ $clauseRef }} — your proposed change</h3>
    <div style="border-left: 3px solid #92400e; padding: 12px 16px; background: #fef3c7; margin: 12px 0; white-space: pre-wrap;">{{ $suggestedChange }}</div>
    @if ($reason)
        <h4 style="color:#475569; margin-top:16px;">Your reason</h4>
        <div style="border-left: 3px solid #94a3b8; padding: 12px 16px; background: #f1f5f9; margin: 12px 0; white-space: pre-wrap;">{{ $reason }}</div>
    @endif

    <p style="margin-top: 24px;"><strong>Important — this document is not legally binding until:</strong></p>
    <ol>
        <li>The agent has resolved your proposed amendments (accept, edit, or reject).</li>
        <li>You have returned to the signing link and completed signing.</li>
    </ol>

    <p>You will receive a follow-up email when the agent has acted on your amendments. At that point you can return to the document via the link below.</p>

    <p style="margin: 28px 0;">
        <a href="{{ $signingUrl }}"
           style="display:inline-block; background:#00d4aa; color:#fff; padding:12px 24px; border-radius:4px; text-decoration:none; font-weight:600;">
            Return to the signing link
        </a>
    </p>

    <p>If you have any questions about your proposed amendments, please contact {{ $agentName }} directly.</p>

    @include('emails.signatures.partials.agent-footer')
</body>
</html>
