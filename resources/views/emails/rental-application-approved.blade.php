<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #059669; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">Congratulations!</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Dear {{ $applicantName }},</p>

        <p>Great news — your rental application through {{ $agencyName }} has been approved, for a monthly rental amount of <strong>R{{ $amount }}</strong>.</p>

        <p>Your agent will be in touch shortly to help you find the right property.</p>

        @if($properties->isNotEmpty())
            <p style="margin-top: 24px; font-weight: bold;">Properties that could work for you, within your approved amount:</p>
            @foreach($properties as $property)
                <div style="border: 1px solid #e0e0e0; border-radius: 6px; padding: 12px 16px; margin-bottom: 10px;">
                    <div style="font-weight: bold;">{{ $property->buildDisplayAddress() }}</div>
                    <div style="color: #555; font-size: 14px;">R{{ number_format($property->effectivePrice(), 0) }} per month</div>
                </div>
            @endforeach
        @else
            <p style="margin-top: 24px; color: #666;">We don't currently have a matching property in our own stock, but your agent will keep an eye out within your approved amount.</p>
        @endif

        <p style="color: #666; font-size: 13px;">
            If you have any questions, please contact {{ $agencyName }} directly.
        </p>
    </div>

</body>
</html>
