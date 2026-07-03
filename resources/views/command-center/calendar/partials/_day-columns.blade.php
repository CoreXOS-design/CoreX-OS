{{-- AT-164 — a run of day columns for the continuous WEEK strip (lazy prepend/append
     via /calendar/day-columns). Each column is the self-contained _day-column partial. --}}
@foreach($columns as $col)
    @include('command-center.calendar.partials._day-column', ['date' => $col['date'], 'events' => $col['events']])
@endforeach
