<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">{{ $agencyName }}</h1>
    </div>
    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $recipientName }},</p>
        <p>Your CoreX portal is ready. In it you can:</p>
        <ul style="margin: 0 0 16px 18px; padding: 0;">
            @foreach($offers as $offer)
                <li>{{ ucfirst($offer) }}.</li>
            @endforeach
        </ul>
        @include('emails.rentals.partials.portal-link-button', ['url' => $url])
        <p>Thanks,<br>{{ $senderName }}<br>{{ $agencyName }}</p>
    </div>
</body>
</html>
