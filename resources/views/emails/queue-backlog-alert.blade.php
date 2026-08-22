@component('mail::message')
# Queue backlog on {{ $host }}

Checked at {{ $checkedAt }}. The oldest job waiting in the database queue is **{{ $ageSeconds }}s** old, past the
configured threshold. Current backlog: **{{ $backlog }}** job{{ $backlog === 1 ? '' : 's' }} waiting.

This means the queue worker is down or wedged — jobs are piling up unprocessed.

To restart manually: `sudo supervisorctl status` then `sudo supervisorctl restart corex-worker-live:*`

Thanks,
{{ config('app.name') }}
@endcomponent
