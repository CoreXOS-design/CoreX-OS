@component('mail::message')
# Queue worker{{ count($downWorkers) === 1 ? '' : 's' }} down on {{ $host }}

Checked at {{ $checkedAt }}. The following supervisor-managed queue worker process{{ count($downWorkers) === 1 ? ' is' : 'es are' }} not running:

@component('mail::table')
| Worker | State | Detail |
| :----- | :---- | :----- |
@foreach($downWorkers as $w)
| {{ $w['process'] }} | {{ $w['state'] }} | {{ $w['detail'] ?: '—' }} |
@endforeach
@endcomponent

Jobs on the affected queue(s) will not be processed until the worker is restarted.

@component('mail::button', ['url' => url('/admin/system-health')])
Open Server Health
@endcomponent

To restart manually: `sudo supervisorctl start <worker>:*`

Thanks,
{{ config('app.name') }}
@endcomponent
