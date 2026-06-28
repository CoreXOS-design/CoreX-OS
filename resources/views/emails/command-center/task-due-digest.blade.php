<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 20px;">Tasks Due</h1>
        <p style="color: #a0aec0; margin: 4px 0 0; font-size: 13px;">{{ $dateLine }}</p>
    </div>

    <div style="padding: 24px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p style="margin: 0 0 16px;">Hi {{ $greeting }},</p>

        <p style="margin: 0 0 20px;">
            You have <strong>{{ $taskCount }}</strong> {{ $taskCount === 1 ? 'task' : 'tasks' }} due.
            Open your Today page to review and complete {{ $taskCount === 1 ? 'it' : 'them' }}.
        </p>

        <div style="border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden;">
            @foreach($tasks as $task)
                @php
                    $due = $task->due_date ? \Carbon\Carbon::parse($task->due_date) : null;
                    $dueStr = $due ? $due->format('d M Y H:i') : 'soon';
                    if ($due) {
                        if ($due->isPast()) {
                            $label = 'Overdue';
                            $labelColour = '#e53e3e';
                        } elseif ($due->isToday()) {
                            $label = 'Due today';
                            $labelColour = '#d69e2e';
                        } else {
                            $label = 'Due ' . $due->diffForHumans();
                            $labelColour = '#38a169';
                        }
                    } else {
                        $label = 'Due soon';
                        $labelColour = '#718096';
                    }
                @endphp
                <div style="padding: 12px 14px; border-bottom: 1px solid #edf2f7; font-size: 13px;">
                    <div style="font-weight: 600; color: #1a202c;">{{ $task->title }}</div>
                    <div style="color: #718096; font-size: 12px; margin-top: 2px;">
                        @if($task->property)
                            {{ $task->property->buildDisplayAddress() }} &bull;
                        @endif
                        {{ $dueStr }} &bull;
                        <strong style="color: {{ $labelColour }};">{{ $label }}</strong>
                    </div>
                </div>
            @endforeach
        </div>

        <div style="text-align: center; margin: 24px 0 16px;">
            <a href="{{ route('command-center.today') }}" style="display: inline-block; background-color: #1a365d; color: #ffffff; padding: 12px 32px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 14px;">
                Open Today
            </a>
        </div>

        <hr style="border: none; border-top: 1px solid #e0e0e0; margin: 20px 0;">

        <p style="color: #999; font-size: 12px; margin: 0;">
            You're receiving this because task due reminders are enabled in your
            notification settings. Adjust them in Command Center Settings.
        </p>
    </div>

    <div style="text-align: center; padding: 12px; color: #999; font-size: 11px;">
        <p style="margin: 0;">Sent by CoreX OS &mdash; Command Center</p>
    </div>

</body>
</html>
