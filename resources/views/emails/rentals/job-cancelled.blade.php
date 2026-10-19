<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #9b1c1c; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">@if($audience === 'tenant')Repair cancelled @else Job cancelled &mdash; do not attend @endif</h1>
    </div>
    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Dear {{ $recipientName }},</p>
        @if($audience === 'tenant')
            <p>The repair at {{ $propertyAddress }} &mdash; <strong>{{ $jobTitle }}</strong> &mdash; has been <strong>cancelled</strong>, so the appointment we arranged will not go ahead.</p>
            <p data-cancelled-line>You do not need to be home for it. If the problem is still there, please tell your agent and they will arrange it again.</p>
        @else
            <p>The job at {{ $propertyAddress }} &mdash; <strong>{{ $jobTitle }}</strong> &mdash; has been <strong>cancelled</strong>.</p>
            <p data-cancelled-line><strong>Please do not attend, and do not carry on with the work.</strong> The link we sent you for this job no longer works.</p>
            <p>If you have already done any of it, or you think this is a mistake, please contact us.</p>
        @endif
        <p>@if($agencyName)Thank you, {{ $agencyName }}.@else Thank you.@endif</p>
    </div>
</body>
</html>
