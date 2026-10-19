{{--
    AT-465 - "What the crew found": what the crew wrote on each "Send to office" (what they found / what is needed), newest first.
    Shown on the job card (office screen) and on the work order. Read-only; renders nothing when the crew has not reported.
    Receives $jobCard (RentalJobCard) and optional $tz.
--}}
@php
    $crTz = $tz ?? ($screen['tz'] ?? (config('app.timezone') ?: 'Africa/Johannesburg'));
    $crReports = $jobCard->crewReports->filter(fn ($r) => trim((string) $r->findings) !== '');
@endphp
@if($crReports->isNotEmpty())
    <div class="jc-card" id="jc-crew-reports" data-crew-reports style="background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 12px 16px;">
        <h2 style="font-size: 13px; font-weight: 700; margin: 0 0 8px;">What the crew found</h2>
        @foreach($crReports as $r)
            <div class="text-sm" style="padding: 8px 0; {{ $loop->first ? '' : 'border-top: 1px solid var(--border);' }}" data-crew-report>
                <div class="text-xs" style="color: var(--text-muted);">
                    {{ \App\Support\AppDate::dateTime($r->created_at, $crTz) }}
                    @if($r->sent_label) &middot; {{ ucfirst(trim(str_replace('via crew', '', $r->sent_label), ' —-')) ?: 'Crew' }}@endif
                    &middot; {{ $r->line_count }} {{ $r->line_count === 1 ? 'line' : 'lines' }}
                </div>
                <div style="white-space: pre-line;">{{ $r->findings }}</div>
            </div>
        @endforeach
    </div>
@endif
