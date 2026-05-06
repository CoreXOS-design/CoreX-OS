@component('mail::message')
# {{ ucfirst($report->type) }}: {{ $report->title }}

@if($report->severity)
**Severity:** {{ ucfirst($report->severity) }}
@endif

**Submitted by:** {{ $submitter?->name ?? 'Unknown user' }}
**Date:** {{ \Carbon\Carbon::parse($report->submitted_at)->format('d M Y H:i') }}
**Module:** {{ $report->module_tag ?? '—' }}
**Page:** {{ $report->page_url ?? '—' }}

---

## Description

{{ $report->description }}

@if($report->steps_to_reproduce)
## Steps to Reproduce

{{ $report->steps_to_reproduce }}
@endif

@if($report->expected_behaviour)
## Expected Behaviour

{{ $report->expected_behaviour }}
@endif

@if($report->actual_behaviour)
## Actual Behaviour

{{ $report->actual_behaviour }}
@endif

---

**Browser:** {{ $report->browser ?? '—' }}
**Viewport:** {{ $report->viewport_width ?? '?' }}×{{ $report->viewport_height ?? '?' }}
**OS:** {{ $report->os ?? '—' }}

@if($feedbackAttachments->isNotEmpty())
**Attachments:** {{ $feedbackAttachments->count() }} file(s) attached to the report.
@endif

@component('mail::button', ['url' => url("/corex/command-center/feedback/{$report->id}")])
View in CoreX OS
@endcomponent

Thanks,
{{ config('app.name') }}
@endcomponent
