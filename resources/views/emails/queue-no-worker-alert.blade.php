@component('mail::message')
# No worker for the {{ $queue }} queue on {{ $host }}

Checked at {{ $checkedAt }}.

**{{ $waiting }}** job{{ $waiting === 1 ? '' : 's' }} {{ $waiting === 1 ? 'is' : 'are' }} waiting on the **{{ $queue }}** queue, the oldest for **{{ $oldestAgeSeconds }}s**,
and no worker process on this machine is listening to that queue, so they will never run.

Nothing is broken in the code: this queue was never given a worker on this machine. Install one the way
docs/DEPLOY.md ("Queue lanes") describes. Every other queue was checked separately.

Thanks,
{{ config('app.name') }}
@endcomponent
