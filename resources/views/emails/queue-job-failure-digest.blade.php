@component('mail::message')
# Queue job failing on {{ $host }}

Checked at {{ $checkedAt }}. **{{ class_basename($jobClass) }}** has failed **{{ $recentCount }}** time{{ $recentCount === 1 ? '' : 's' }} in the last {{ $windowLabel }} on the `{{ $queueName }}` queue ({{ $queueConnection }} connection).

**Exception:** `{{ $exceptionClass }}`

{{ $exceptionMessage }}

This is a digest, not a per-failure alert — you will not be re-paged for the same job class for another 15 minutes while it keeps failing. Check `failed_jobs` for the full list:

```
select id, failed_at, exception from failed_jobs where payload like '%{{ class_basename($jobClass) }}%' order by id desc limit 20;
```

Thanks,
{{ config('app.name') }}
@endcomponent
