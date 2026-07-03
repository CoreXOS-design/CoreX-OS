<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 20px;">New Property Matches</h1>
        <p style="color: #a0aec0; margin: 4px 0 0; font-size: 13px;">{{ $dateLine }}</p>
    </div>

    <div style="padding: 24px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p style="margin: 0 0 16px;">Hi {{ $greeting }},</p>

        <p style="margin: 0 0 20px;">
            <strong style="color: #1a365d;">{{ $matchCount }}</strong> new
            {{ $matchCount === 1 ? 'property matches' : 'properties match' }}
            {{ $contactCount === 1 ? 'one of your buyers' : 'your buyers' }}
            @if($contactCount > 1)
                (across <strong>{{ $contactCount }}</strong> contacts)
            @endif
            since your last digest.
        </p>

        @foreach($groups as $group)
            <div style="margin-bottom: 20px;">
                <div style="background-color: #2c5282; color: #fff; padding: 8px 14px; border-radius: 4px 4px 0 0; font-size: 13px; font-weight: bold;">
                    {{ $group['name'] }} &mdash; {{ count($group['items']) }}
                    {{ count($group['items']) === 1 ? 'match' : 'matches' }}
                </div>
                <div style="background-color: #f7fafc; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 4px 4px;">
                    @foreach($group['items'] as $item)
                        <div style="padding: 12px 14px; border-bottom: 1px solid #e2e8f0; font-size: 13px;">
                            <div style="display: flex; justify-content: space-between;">
                                <a href="{{ url('/corex/properties/' . $item['property_id'] . '?tab=matches') }}"
                                   style="font-weight: 600; color: #2b6cb0; text-decoration: none;">
                                    {{ $item['address'] }}
                                </a>
                            </div>
                            <div style="color: #718096; font-size: 12px; margin-top: 2px;">
                                R {{ number_format($item['price']) }}
                                @if($item['listing_type']) &bull; {{ ucfirst($item['listing_type']) }} @endif
                                &bull; <strong style="color: #2f855a;">{{ $item['score'] }}% match</strong>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div style="text-align: center; margin: 24px 0 16px;">
            <a href="{{ url('/corex/contacts') }}" style="display: inline-block; background-color: #1a365d; color: #ffffff; padding: 12px 32px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 14px;">
                View Your Buyers
            </a>
        </div>

        <hr style="border: none; border-top: 1px solid #e0e0e0; margin: 20px 0;">

        <p style="color: #999; font-size: 12px; margin: 0;">
            You receive one Core Matches digest per day. Turn match emails off in
            your notification settings.
        </p>
    </div>

    <div style="text-align: center; padding: 12px; color: #999; font-size: 11px;">
        <p style="margin: 0;">Sent by CoreX OS &mdash; Core Matches</p>
    </div>

</body>
</html>
