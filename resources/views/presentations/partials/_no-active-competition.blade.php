{{-- "No active competition found" empty state for the presentation's Active Competition
     section (review screen 2b and analysis step 5). Agent screens only — the seller PDF and the
     public seller page print NO empty section. Johan 2026-10-07: the section used to vanish silently,
     which looked like a removal; the cause is almost always that no capture has re-sighted the
     suburb's listings within the stale window (prospecting:flag-stale-listings).
     Needs: $presentation. --}}
@php
    $_nacSuburb = trim((string) ($presentation->suburb ?? '')) ?: 'this suburb';
    $_nacAgencyId = (int) ($presentation->agency_id ?: (auth()->user()?->effectiveAgencyId() ?: 0));
    $_nacWindow = $_nacAgencyId
        ? (int) app(\App\Services\Prospecting\ProspectingConfigurationService::class)->getSuggestedActionThresholds($_nacAgencyId)->listing_off_market_days
        : 90;
    $_nacCanOpenPortal = auth()->user()?->hasPermission('access_my_portal') && \Illuminate\Support\Facades\Route::has('agent.portal');
@endphp
<div data-no-active-competition>
    <p style="margin:0 0 6px 0;font-size:13px;font-weight:700;color:var(--text-primary);">No active competition found for this area</p>
    <p style="margin:0;font-size:12px;line-height:1.5;color:var(--text-secondary);">
        CoreX has no active competing listings on record for <strong>{{ $_nacSuburb }}</strong> right now.
        A portal listing drops off after {{ $_nacWindow }} days without being seen again, so an empty list usually means
        the portal stock for {{ $_nacSuburb }} is out of date.
        <strong>Recommended:</strong> update the portal stock for {{ $_nacSuburb }} with the CoreX Chrome extension
        (search {{ $_nacSuburb }} for-sale listings on Property24 / Private Property and capture the results), then reopen this presentation.
        @if($_nacCanOpenPortal)
            <a href="{{ route('agent.portal') }}#tools" target="_blank" class="no-underline" style="color:var(--brand-icon);font-weight:600;" data-chrome-extension-link>Open My Portal &rarr; Tools &rarr; Chrome Extension</a>
        @endif
    </p>
</div>
