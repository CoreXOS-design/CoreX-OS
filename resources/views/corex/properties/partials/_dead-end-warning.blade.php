{{-- MIC ↔ Deeds ↔ Contact loop (Part B) — surface a dead-end owner on the property too, so an
     agent viewing the property sees there is nothing contactable before trying to pitch it. --}}
@php($deadEndContacts = $property->contacts->filter(fn ($c) => $c->deadEndFlag))
@if($deadEndContacts->isNotEmpty())
    <div class="rounded-md px-4 py-3 mb-4"
         style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 45%, var(--border));">
        <div class="text-sm font-semibold" style="color: var(--text-primary);">⚠ No contact details available — dead end</div>
        @foreach($deadEndContacts as $c)
            <div class="text-xs mt-1" style="color: var(--text-muted);">
                {{ trim($c->first_name . ' ' . (string) $c->last_name) ?: 'Owner' }} —
                {{ \App\Models\ContactDeadEndFlag::reasonLabel($c->deadEndFlag->reason) }}. This owner has been chased; nothing to reach.
            </div>
        @endforeach
    </div>
@endif
