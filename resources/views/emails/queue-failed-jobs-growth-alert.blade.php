@component('mail::message')
# failed_jobs is growing on {{ $host }}

Checked at {{ $checkedAt }}. **{{ $newFailures }}** job{{ $newFailures === 1 ? '' : 's' }} failed in the last {{ $windowMinutes }} minutes. Total rows in `failed_jobs`: **{{ $totalFailedJobs }}**.

The worker is likely running and draining `jobs` normally — that check alone reports healthy, because a job that fails is removed from `jobs` the moment it fails. This alert exists specifically to catch that blind spot: jobs failing fast still means something is broken, even though nothing looks stalled.

Check the per-job-class digest emails (or `failed_jobs` directly) for which job class is failing and why.

Thanks,
{{ config('app.name') }}
@endcomponent
