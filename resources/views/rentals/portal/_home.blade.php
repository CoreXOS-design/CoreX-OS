{{-- .ai/specs/rental-portal-access.md §20 — the portal Home, one panel for the tenant AND the owner: who to call, the inspection
     dates, where the lease stands. Reads /api/v1/client/rentals[/landlord]/overview (the person's OWN properties only). --}}
<div data-portal-home>
    <div class="card" data-portal-home-decisions x-show="activeRole === 'landlord' && overview.decisions_waiting > 0" style="padding:12px 16px;">
        <a class="link" href="#" @click.prevent="landlordTab='decisions'; loadDecisions()" x-text="overview.decisions_waiting + (overview.decisions_waiting === 1 ? ' decision waiting for you →' : ' decisions waiting for you →')"></a>
    </div>

    <template x-for="h in overview.homes" :key="h.property.id">
        <div class="card" data-portal-home-block>
            <h2 x-text="h.property.address"></h2>
            <p class="muted" style="margin:-6px 0 8px;" x-show="h.tenant_names" x-text="'Tenant: ' + h.tenant_names"></p>

            {{-- 1. Who to call --}}
            <div class="list-item" data-portal-home-contact>
                <template x-if="h.contact.agent">
                    <div>
                        <strong x-text="h.contact.agent.name"></strong>
                        <span class="muted" x-show="h.contact.agent.designation" x-text="' · ' + h.contact.agent.designation"></span>
                        <div class="row" style="justify-content:flex-start; gap:14px; margin-top:4px;">
                            <a class="link" x-show="h.contact.agent.phone" :href="'tel:' + (h.contact.agent.phone || '').replace(/\s+/g, '')" x-text="h.contact.agent.phone"></a>
                            <a class="link" x-show="h.contact.agent.email" :href="'mailto:' + h.contact.agent.email" x-text="h.contact.agent.email"></a>
                        </div>
                    </div>
                </template>
                <template x-if="!h.contact.agent">
                    <div>
                        <strong x-text="[h.contact.office.agency, h.contact.office.branch].filter(Boolean).join(' · ') || 'Your agency'"></strong>
                        <div class="row" style="justify-content:flex-start; gap:14px; margin-top:4px;">
                            <a class="link" x-show="h.contact.office.phone" :href="'tel:' + (h.contact.office.phone || '').replace(/\s+/g, '')" x-text="h.contact.office.phone"></a>
                            <a class="link" x-show="h.contact.office.email" :href="'mailto:' + h.contact.office.email" x-text="h.contact.office.email"></a>
                        </div>
                    </div>
                </template>
                <div class="muted" style="margin-top:4px;" x-show="h.contact.agent"
                     x-text="[[h.contact.office.agency, h.contact.office.branch].filter(Boolean).join(' · '), h.contact.office.phone, h.contact.office.email].filter(Boolean).join(' · ')"></div>
                <div class="muted" x-show="h.contact.office.address" x-text="h.contact.office.address"></div>
            </div>

            {{-- 3. Lease end and renewal --}}
            <div class="list-item" data-portal-home-lease>
                <template x-if="h.tenancy">
                    <div>
                        <div class="row"><strong x-text="h.tenancy.headline"></strong></div>
                        <div class="muted" x-show="h.tenancy.detail" x-text="h.tenancy.detail"></div>
                        <div class="row" style="margin-top:6px;"><span class="muted">Start</span><strong x-text="fmtDay(h.tenancy.start_date)"></strong></div>
                        <div class="row"><span class="muted">End</span><strong x-text="fmtDay(h.tenancy.end_date)"></strong></div>
                    </div>
                </template>
                <p class="muted" style="margin:0;" x-show="!h.tenancy">No current tenancy.</p>
            </div>

            {{-- 4. FAQ worked out from the lease's own notice / cancellation terms (§21). Nothing at all when the lease has none. --}}
            <div class="list-item" data-portal-home-faq x-show="h.faq && h.faq.length">
                <template x-for="q in (h.faq || [])" :key="h.property.id + q.key">
                    <div class="faq" data-portal-faq-item>
                        <button type="button" class="faq-q" :aria-expanded="isFaqOpen(h.property.id, q.key) ? 'true' : 'false'" @click="toggleFaq(h.property.id, q.key)">
                            <span x-text="q.question"></span>
                            <span class="faq-mark" x-text="isFaqOpen(h.property.id, q.key) ? '−' : '+'"></span>
                        </button>
                        <p class="faq-a" x-show="isFaqOpen(h.property.id, q.key)" x-text="q.answer"></p>
                    </div>
                </template>
            </div>

            {{-- 2. Inspection dates --}}
            <div class="list-item" data-portal-home-inspections>
                <div class="muted" style="margin-bottom:4px;">Inspections</div>
                <template x-for="i in h.inspections.upcoming" :key="'u' + i.id">
                    <div class="row"><span x-text="i.type_label"></span><strong x-text="fmtDay(i.date) + (i.time ? ' · ' + i.time : '')"></strong></div>
                </template>
                <template x-for="i in h.inspections.past" :key="'p' + i.id">
                    <div class="row"><span x-text="i.type_label + ' · ' + fmtDay(i.date)"></span><span class="badge" x-text="i.status_label"></span></div>
                </template>
                <p class="muted" style="margin:2px 0 0;" x-show="h.inspections.past_total > h.inspections.past.length" x-text="'+ ' + (h.inspections.past_total - h.inspections.past.length) + ' earlier'"></p>
                <p class="muted" style="margin:0;" x-show="!h.inspections.upcoming.length && !h.inspections.past.length">None booked.</p>
            </div>
        </div>
    </template>

    <div class="card" x-show="overview.loaded && !overview.homes.length"><p class="muted" style="margin:0;">No properties yet.</p></div>
    <p class="error" x-show="overview.error" x-text="overview.error"></p>
</div>
