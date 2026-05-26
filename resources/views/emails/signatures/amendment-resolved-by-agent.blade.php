<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Your proposed amendments have been reviewed</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; max-width: 640px; margin: 0 auto; padding: 20px;">
    @php
        $resolutionLabel = match ($resolution) {
            'approved'           => 'accepted as-is',
            'approved_with_edit' => 'accepted with edits',
            'rejected_change'    => 'rejected',
            'rejected_document'  => 'declined',
            default              => 'reviewed',
        };
        $resolutionColour = match ($resolution) {
            'approved', 'approved_with_edit' => '#16a34a',
            'rejected_change', 'rejected_document' => '#dc2626',
            default => '#0ea5e9',
        };
    @endphp

    <p>Dear {{ $recipientName }},</p>

    <p><strong>{{ $agentName }}</strong> has reviewed your proposed amendments to <strong>{{ $documentName }}</strong>.</p>

    <h3 style="color: {{ $resolutionColour }}; margin-top:24px;">
        Clause {{ $clauseRef }} — {{ $resolutionLabel }}
    </h3>

    @if ($agentNote)
        <h4 style="color:#475569; margin-top:16px;">Agent's note</h4>
        <div style="border-left: 3px solid {{ $resolutionColour }}; padding: 12px 16px; background: #f8fafc; margin: 12px 0; white-space: pre-wrap;">{{ $agentNote }}</div>
    @endif

    @if ($finalText && in_array($resolution, ['approved', 'approved_with_edit']))
        <h4 style="color:#475569; margin-top:16px;">Final clause text</h4>
        <div style="border-left: 3px solid #16a34a; padding: 12px 16px; background: #f0fdf4; margin: 12px 0; white-space: pre-wrap;">{{ $finalText }}</div>
    @endif

    <p style="margin-top: 24px;">Please return to the signing link to review the resolution and complete the document.</p>

    <p style="margin: 28px 0;">
        <a href="{{ $signingUrl }}"
           style="display:inline-block; background:#00d4aa; color:#fff; padding:12px 24px; border-radius:4px; text-decoration:none; font-weight:600;">
            Return to the signing link
        </a>
    </p>

    <p>If anything is unclear, please contact {{ $agentName }} before continuing.</p>

    @include('emails.signatures.partials.agent-footer')
</body>
</html>
