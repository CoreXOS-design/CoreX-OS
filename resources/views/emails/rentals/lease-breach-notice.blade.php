<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">{{ $agencyName }}</h1>
    </div>
    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Dear {{ $recipientName }},</p>
        <p>Please find attached a <strong>{{ strtolower($noticeLabel) }}</strong> regarding {{ $propertyAddress }}. You will also find it in your portal, under Documents.</p>
        @if($breach->kind === 'payment' && $breach->amount !== null && $notice->notice_type !== 'notice_to_vacate')
            <p>The amount outstanding is <strong>{{ \App\Services\Rentals\RentalNoticeService::money((float) $breach->amount) }}</strong>.</p>
        @endif
        @if($notice->notice_type !== 'notice_to_vacate' && $breach->remedy_deadline)
            <p>Please put this right by <strong>{{ $breach->remedy_deadline->format('j F Y') }}</strong>.</p>
        @endif
        <p>Please contact us straight away if you have any questions.</p>
        <p>Regards,<br>{{ $senderName }}<br>{{ $agencyName }}</p>
    </div>
</body>
</html>
