<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    {{-- AT-410d, 2026-09-16 — DRAFT COPY, awaiting Johan's sign-off before
         this is considered final (same review step as the decline reason
         templates). Johan's brief: honest about the condition, says what's
         still needed and how, never an unconditional "congratulations"
         that may have to be walked back. --}}
    <div style="background-color: {{ $isSubjectToFica ? '#b45309' : '#059669' }}; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">{{ $isSubjectToFica ? 'Almost there — one thing left to do' : 'Congratulations!' }}</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Dear {{ $applicantName }},</p>

        @if($isSubjectToFica)
            <p>Your rental application through {{ $agencyName }} has been approved for a monthly rental amount of <strong>R{{ $amount }}</strong>.</p>

            <p><strong>Before this can be finalised, we still need to verify your identity documents (this is called "FICA" — it's a legal requirement for every South African rental, not something specific to your application).</strong> Until that's done, this approval isn't final yet and the property isn't confirmed as yours.</p>

            @if($ficaContinueUrl)
                <p style="text-align: center; margin: 24px 0;">
                    <a href="{{ $ficaContinueUrl }}" style="background-color: #b45309; color: #ffffff; padding: 12px 28px; border-radius: 6px; text-decoration: none; font-weight: bold; display: inline-block;">Verify my identity now</a>
                </p>
                <p>This link is yours alone — it only takes a few minutes, and once it's done there's nothing further for you to do; we'll take it from there.</p>
            @else
                <p>Your agent will be in touch shortly with the link to do this.</p>
            @endif
        @else
            <p>Great news — your rental application through {{ $agencyName }} has been approved, for a monthly rental amount of <strong>R{{ $amount }}</strong>.</p>
        @endif

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
