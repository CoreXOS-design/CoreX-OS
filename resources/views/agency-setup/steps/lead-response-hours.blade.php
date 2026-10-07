{{-- Setup Wizard (Contacts step) — lead response counting hours. Same editor as Settings → Lead response. The target
     minutes is the step's generic number control (lead_response_target_minutes). --}}
@php
    $leadHours = \App\Models\AgencyContactSettings::forAgencyReadOnly((int) $agency->id)->leadResponseHours();
@endphp
<div class="rounded-md p-4 mb-4" style="background:var(--surface-2); border:1px solid var(--border);">
    <div class="text-sm font-semibold mb-1" style="color:var(--text-primary);">How fast should a new enquiry be answered?</div>
    <p class="text-xs mb-1" style="color:var(--text-muted);">Every enquiry that comes in from a portal, your website or a shared link starts a clock. It stops when an agent genuinely contacts the person: the "Contacted" action, a message sent, a link shared, or feedback on an appointment with them. Choose which hours count, so an enquiry at midnight is not held against the agent.</p>
    <p class="text-xs mb-3" style="color:var(--text-muted);"><strong>What this changes:</strong> the figures on the Lead Response report (Reports menu) — how many enquiries were answered in time, late, or not yet, and how long it took.</p>
    @include('partials.lead-response-hours', ['hours' => $leadHours])
</div>
