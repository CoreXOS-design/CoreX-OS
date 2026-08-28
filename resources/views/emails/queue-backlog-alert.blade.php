@component('mail::message')
# Queue backlog on {{ $host }}

Checked at {{ $checkedAt }}.

The **{{ $lane }}** lane is not draining. Its oldest waiting job is **{{ $ageSeconds }}s** old, past this lane's
**{{ $maxAge }}s** threshold. Currently waiting on this lane: **{{ $backlog }}** job{{ $backlog === 1 ? '' : 's' }}.

Only this lane is affected — every other lane was judged separately and is within its own limits.

To investigate: `sudo supervisorctl status` then `sudo supervisorctl restart {{ $supervisor }}`

Thanks,
{{ config('app.name') }}
@endcomponent
