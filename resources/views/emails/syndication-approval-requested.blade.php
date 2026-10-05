{{--
    Layer 3 — "Approval needed" email to a chosen syndication approver.
    Spec: .ai/specs/syndication-approval-gate.md §5.4 (D4).
    Styling mirrors emails/fica-request.blade.php (inline styles only — the
    mail clients CoreX sends to strip <style> blocks).
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">Approval needed before this listing goes out</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">

        <p>{{ $agentName }} has finished a listing and is asking for your approval before it goes to the portals and your website.</p>

        <div style="background-color: #f7fafc; border-left: 4px solid #1a365d; padding: 15px; margin: 20px 0;">
            <div style="font-size: 17px; font-weight: bold; color: #1a365d;">{{ $addressLine }}</div>
            <div style="margin-top: 6px;">{{ $priceLine }}</div>
            <div style="margin-top: 6px; color: #555;">Agent: {{ $agentName }}</div>
            <div style="margin-top: 6px; color: #2f855a;">{{ $complianceLine }}</div>
        </div>

        @if($note)
        <div style="background-color: #fffaf0; border-left: 4px solid #dd6b20; padding: 15px; margin: 20px 0;">
            <strong>Note from {{ $agentName }}:</strong>
            <div style="margin-top: 6px;">{{ $note }}</div>
        </div>
        @endif

        <p>Nothing goes to Property24, Private Property or your website until you approve it.</p>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $propertyUrl }}" style="display: inline-block; background-color: #1a365d; color: #ffffff; padding: 14px 40px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
                OPEN THE PROPERTY
            </a>
        </div>

        <p style="text-align: center; margin: 20px 0;">
            <a href="{{ $queueUrl }}" style="color: #1a365d; font-weight: bold;">See everything waiting for approval</a>
        </p>

        <p style="color: #718096; font-size: 13px; border-top: 1px solid #e0e0e0; padding-top: 15px; margin-top: 30px;">
            You are receiving this because {{ $agencyName }} has chosen you to approve listings before they are syndicated.
        </p>
    </div>

</body>
</html>
