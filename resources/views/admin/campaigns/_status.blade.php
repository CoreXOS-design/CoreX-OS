{{-- AT-534 — a status badge for a campaign, lead or send status. Receives: $status. --}}
@php
    $tone = match ($status) {
        'running', 'sent', 'replied' => 'ds-badge-success',
        'paused', 'queued', 'sending', 'unknown' => 'ds-badge-warning',
        'stopped', 'bounced', 'failed' => 'ds-badge-danger',
        'done', 'new' => 'ds-badge-info',
        default => 'ds-badge-muted',
    };
@endphp
<span class="ds-badge {{ $tone }}">{{ ucfirst($status) }}</span>
