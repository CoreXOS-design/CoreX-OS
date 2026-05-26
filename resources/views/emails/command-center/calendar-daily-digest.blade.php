<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 20px;">Calendar Digest</h1>
        <p style="color: #a0aec0; margin: 4px 0 0; font-size: 13px;">{{ $dateLine }}</p>
    </div>

    <div style="padding: 24px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p style="margin: 0 0 16px;">Hi {{ $greeting }},</p>

        <p style="margin: 0 0 20px;">
            Your calendar digest:
            @if($redCount)<strong style="color: #e53e3e;">{{ $redCount }} red</strong>@endif
            @if($redCount && ($amberCount || $greenCount)), @endif
            @if($amberCount)<strong style="color: #d69e2e;">{{ $amberCount }} amber</strong>@endif
            @if($amberCount && $greenCount), @endif
            @if($greenCount)<strong style="color: #38a169;">{{ $greenCount }} green</strong>@endif
            {{ ($redCount + $amberCount + $greenCount) === 1 ? 'item' : 'items' }} requiring attention.
        </p>

        @foreach(['red' => ['#e53e3e', '#fff5f5', '#feb2b2'], 'amber' => ['#d69e2e', '#fffff0', '#fefcbf'], 'green' => ['#38a169', '#f0fff4', '#c6f6d5']] as $colour => [$textColour, $bgColour, $borderColour])
            @if(!empty($groupedEvents[$colour]))
                <div style="margin-bottom: 20px;">
                    <div style="background-color: {{ $textColour }}; color: #fff; padding: 8px 14px; border-radius: 4px 4px 0 0; font-size: 13px; font-weight: bold; text-transform: uppercase;">
                        {{ ucfirst($colour) }} &mdash; {{ count($groupedEvents[$colour]) }} {{ count($groupedEvents[$colour]) === 1 ? 'item' : 'items' }}
                    </div>
                    <div style="background-color: {{ $bgColour }}; border: 1px solid {{ $borderColour }}; border-top: none; border-radius: 0 0 4px 4px;">
                        @foreach($groupedEvents[$colour] as $item)
                            @php
                                $evt = $item['event'];
                                $eventDate = $evt->event_date ? \Carbon\Carbon::parse($evt->event_date) : null;
                                $daysUntil = $eventDate ? (int) now()->startOfDay()->diffInDays($eventDate->copy()->startOfDay(), false) : null;
                                $dateStr = $eventDate ? $eventDate->format('d M Y') : '—';
                                $daysLabel = $daysUntil === null ? '' : ($daysUntil < 0 ? abs($daysUntil) . 'd overdue' : ($daysUntil === 0 ? 'today' : $daysUntil . 'd'));
                            @endphp
                            <div style="padding: 10px 14px; border-bottom: 1px solid {{ $borderColour }}; font-size: 13px;">
                                <div style="font-weight: 600; color: #1a202c;">{{ $evt->title }}</div>
                                <div style="color: #718096; font-size: 12px; margin-top: 2px;">
                                    {{ $item['class_label'] }} &bull; {{ $dateStr }}
                                    @if($daysLabel) &bull; <strong style="color: {{ $textColour }};">{{ $daysLabel }}</strong> @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach

        <div style="text-align: center; margin: 24px 0 16px;">
            <a href="{{ url('/corex/command-center/calendar') }}" style="display: inline-block; background-color: #1a365d; color: #ffffff; padding: 12px 32px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 14px;">
                View Calendar
            </a>
        </div>

        <hr style="border: none; border-top: 1px solid #e0e0e0; margin: 20px 0;">

        <p style="color: #999; font-size: 12px; margin: 0;">
            This digest is sent daily based on your event class configuration.
            Adjust what appears here in Command Center Settings.
        </p>
    </div>

    <div style="text-align: center; padding: 12px; color: #999; font-size: 11px;">
        <p style="margin: 0;">Sent by CoreX OS &mdash; Calendar Event Classes</p>
    </div>

</body>
</html>
