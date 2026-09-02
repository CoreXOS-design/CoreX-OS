{{-- Linked deals — the FK (deal_contacts) has existed and been populated for a
     while, but nothing on this page ever surfaced it (see ContactController::show()
     2026-09-02 addition). Read-only: deal-party linking is managed from the deal
     itself, not here — this list exists so an agent can see, at a glance, every
     deal this contact has been a buyer or seller party on. --}}
<div>
    <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">
        Linked Deals ({{ $linkedDeals->count() }})
    </h3>
    @forelse($linkedDeals as $deal)
        @php
            $role = optional($deal->contacts->first())->pivot->role;
            $statusColor = match (true) {
                $deal->commission_status === 'registered' => 'var(--ds-green)',
                $deal->accepted_status === 'A' => 'var(--brand-icon)',
                default => 'var(--text-muted)',
            };
            $statusLabel = $deal->commission_status === 'registered'
                ? 'Registered'
                : ($deal->accepted_status === 'A' ? 'Accepted, not yet registered' : 'In progress');
        @endphp
        <div class="flex items-center gap-3 px-4 py-3 rounded-md mb-2" style="background:var(--surface-2); border:1px solid var(--border);">
            <div class="flex-1 min-w-0">
                <a href="{{ route('admin.deals.edit', $deal) }}"
                   class="text-sm font-semibold no-underline hover:underline"
                   style="color:var(--text-primary);">{{ $deal->property_address ?: 'Deal #' . $deal->deal_no }}</a>
                @if($role)
                    <span class="font-semibold text-xs" style="margin-left:.4rem; color: var(--brand-icon, #0ea5e9);">{{ ucfirst($role) }}</span>
                @endif
                <div class="text-xs mt-0.5 flex flex-wrap gap-2" style="color:var(--text-muted);">
                    <span style="color:{{ $statusColor }};">{{ $statusLabel }}</span>
                    @if($deal->property_value)
                        <span>R {{ number_format((float) $deal->property_value, 0) }}</span>
                    @endif
                    @if($deal->deal_date)
                        <span>{{ \Illuminate\Support\Carbon::parse($deal->deal_date)->format('j M Y') }}</span>
                    @endif
                </div>
            </div>
        </div>
    @empty
    <div class="rounded-md py-8 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
        <p class="text-sm" style="color: var(--text-muted);">No deals linked to this contact yet.</p>
    </div>
    @endforelse
</div>
