{{-- Property identity C1 (section 15): what this agency's e-mail alerts say about the property - price, changes, dates. Per agency, read from its own alert rows only. --}}
@if(!empty($alerts))
<div data-qa="alert-facts" style="margin-bottom: 14px; padding: 12px 14px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
    <div class="flex flex-wrap items-baseline justify-between gap-2" style="margin-bottom: 8px;">
        <div class="text-sm font-bold" style="color: var(--text-primary);">What the portal e-mail alerts say</div>
        <div class="text-xs" style="color: var(--text-muted);">From the alert e-mails your agency receives. Never shared with another agency.</div>
    </div>
    @foreach($alerts as $a)
        <div class="text-xs" data-qa="alert-row" style="padding: 6px 0; border-top: 1px solid var(--border); color: var(--text-secondary);">
            <div class="flex flex-wrap gap-x-6 gap-y-1">
                <div><strong style="color: var(--text-primary);">{{ $a['portal'] }}</strong>
                    @if($a['url'])<a href="{{ $a['url'] }}" target="_blank" rel="noopener" style="color: var(--accent, #2563eb);">{{ $a['number'] }}</a>@else{{ $a['number'] }}@endif
                </div>
                <div data-qa="alert-price">Asking <strong style="color: var(--text-primary);">R {{ number_format($a['price'], 0, '.', ',') }}</strong>
                    @if($a['change_percent'] !== null)
                        <span style="color: {{ $a['change_percent'] < 0 ? 'var(--ds-crimson, #dc2626)' : 'var(--text-muted)' }};">
                            ({{ $a['change_percent'] > 0 ? '+' : '' }}{{ $a['change_percent'] }}% since first seen at R {{ number_format($a['original_price'], 0, '.', ',') }})
                        </span>
                    @endif
                </div>
                <div>First seen {{ $a['first_seen'] ?? '-' }}@if($a['days_listed'] !== null) ({{ $a['days_listed'] }} {{ $a['days_listed'] === 1 ? 'day' : 'days' }} ago)@endif</div>
                <div>Last seen {{ $a['last_seen'] ?? '-' }} - in {{ $a['times_seen'] }} {{ $a['times_seen'] === 1 ? 'alert' : 'alerts' }}</div>
                @if($a['mandated'])<div><strong style="color: var(--text-primary);">Mandated</strong></div>@endif
                @if($a['status'] && $a['status'] !== 'active')<div>Status: {{ ucfirst($a['status']) }}</div>@endif
            </div>
            @if(count($a['changes']))
                <ul data-qa="alert-changes" style="margin-top: 4px; margin-left: 14px; list-style: disc;">
                    @foreach($a['changes'] as $c)
                        <li>{{ $c['date'] }}: R {{ number_format($c['old'], 0, '.', ',') }} to R {{ number_format($c['new'], 0, '.', ',') }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endforeach
</div>
@endif
