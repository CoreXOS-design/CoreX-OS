{{-- AT-164 (single week-stream) — a run of week rows for a lazy prepend/append window
     of the continuous MONTH view. Rendered by CalendarController::weekRows through the
     SAME _week-row partial as the initial render. --}}
@foreach($weeks as $wk)
    @include('command-center.calendar.partials._week-row', [
        'weekStart'      => $wk['weekStart'],
        'byDate'         => $wk['byDate'],
        'deadlineGroups' => $wk['deadlineGroups'],
        'spanningBars'   => $wk['spanningBars'],
    ])
@endforeach
