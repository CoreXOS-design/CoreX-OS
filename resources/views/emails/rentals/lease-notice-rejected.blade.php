<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: {{ $agencyColor }}; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        @if(!empty($agencyLogoUrl))
            <img src="{{ $agencyLogoUrl }}" alt="{{ $agencyName }}" style="max-height:48px; max-width:220px;">
        @else
            <h1 style="color: #ffffff; margin: 0; font-size: 22px;">{{ $agencyName }}</h1>
        @endif
    </div>
    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $recipientName }},</p>
        <p>The notice you gave for {{ $propertyAddress }} on {{ $notice->notice_date->format('j F Y') }} (ending the lease on {{ $notice->intended_end_date->format('j F Y') }}) has not been accepted as it stands.</p>
        <p style="background:#fff8e6; border-left:4px solid #d69e2e; padding:12px 14px;"><strong>Why:</strong> {{ $notice->decision_note }}</p>
        <p>Nothing has been sent to anyone else. Please contact us, or give your notice again from your portal with the details corrected.</p>
        <p>Thanks,<br>{{ $agencyName }}</p>
        @php
            $details = array_filter([trim((string) ($agency->address ?? '')), trim((string) ($agency->phone ?? '')), trim((string) ($agency->email ?? ''))]);
        @endphp
        @if($details)
            <p style="font-size: 12px; color: #888;">{{ implode(' · ', $details) }}</p>
        @endif
    </div>
</body>
</html>
