{{--
    Reusable calendar-event feedback modal (AT-114).

    ONE canonical feedback action, summonable from ANY surface that shows a
    calendar event (contact, buyer pipeline, property, …) — not just the
    calendar. It reuses the EXISTING feedback endpoints and fields verbatim:
      - GET  command-center.calendar.feedback.show  (loads contacts + options)
      - POST command-center.calendar.feedback.store (the canonical save —
        writes CalendarEventFeedback, same table/fields as the calendar)
    No parallel feedback logic: only the entry point is new.

    SUMMON:  window.dispatchEvent(new CustomEvent('corex:open-event-feedback',
                 { detail: { eventId } }))
    AFTER SAVE: dispatches window 'corex:feedback-saved' { eventId } so the host
                surface can flip its badge in place (no reload needed).

    Wrapped in @once so a page may include it via several partials safely.

    EXTENSIBILITY: this is the first "summon an event action from anywhere"
    component. A future action (reschedule, cancel, open linked pack) follows the
    same shape — a sibling partial listening on its own 'corex:open-event-*'
    event, POSTing the existing canonical route, emitting a 'corex:*-saved' event
    the host listens for. The host never learns the action's internals; it only
    dispatches and listens.

    PER-PROPERTY (AT-114 pt2): a viewing can span MULTIPLE properties, and
    feedback is captured PER PROPERTY (storeFeedback keys each row on
    calendar_event_id + contact_id + property_id). This modal therefore renders
    one feedback block PER PROPERTY (mirroring the data granularity of the
    calendar's own feedback flow) — outcome / concerns / seller-visible notes /
    internal notes / next action for each property × each linked contact. A
    property-less event (a meeting) falls back to a single block with no
    property. The richer listing-presentation (per_property) feedback keeps its
    dedicated flow on the calendar; here we offer a direct jump rather than
    duplicate that distinct UI.
--}}
@once
<div x-data="eventFeedbackModal()"
     x-on:corex:open-event-feedback.window="open($event.detail.eventId)">
    <div x-show="isOpen" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/50" @click="isOpen = false"></div>
        <div class="relative rounded-md w-full max-w-lg max-h-[85vh] overflow-y-auto"
             style="background: var(--surface); border: 1px solid var(--border);">

            {{-- Header --}}
            <div class="flex items-start justify-between gap-3 px-6 py-4 sticky top-0"
                 style="background: var(--surface); border-bottom: 1px solid var(--border);">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Capture Feedback</h2>
                    <p class="text-xs mt-0.5 truncate" style="color: var(--text-muted);"
                       x-text="data.event ? (data.event.title + ' — ' + data.event.date) : ''"></p>
                </div>
                <button type="button" @click="isOpen = false" class="text-xl leading-none px-2" style="color: var(--text-muted);">&times;</button>
            </div>

            <div class="px-6 py-4 space-y-4">
                {{-- Loading --}}
                <template x-if="loading">
                    <p class="text-sm" style="color: var(--text-muted);">Loading…</p>
                </template>

                {{-- Per-property fallback (listing presentations) — keep the richer
                     flow on the calendar; offer a direct jump rather than duplicate it. --}}
                <template x-if="!loading && data.feedback_mode === 'per_property'">
                    <div class="rounded-md p-4 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">
                        This event uses listing-presentation feedback (per property).
                        <a :href="calendarLink" class="font-semibold no-underline hover:underline" style="color: var(--brand-icon, #00d4aa);">Open it in the calendar →</a>
                    </div>
                </template>

                {{-- No contacts linked → cannot capture per-contact feedback --}}
                <template x-if="!loading && data.feedback_mode !== 'per_property' && data.contacts.length === 0">
                    <p class="text-sm" style="color: var(--text-muted);">No contacts are linked to this event, so there is nobody to capture feedback for.</p>
                </template>

                {{-- Per-property feedback (viewings) — one block per property in
                     the event (or a single property-less block for a meeting),
                     each capturing the same fields as the calendar modal, for
                     every linked contact. --}}
                <template x-if="!loading && data.feedback_mode !== 'per_property' && data.contacts.length > 0">
                    <div class="space-y-4">
                        <template x-for="block in blocks" :key="block.key">
                            <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                                {{-- Property heading (omitted for a property-less meeting) --}}
                                <template x-if="block.property_id !== null">
                                    <h3 class="text-sm font-semibold mb-3 flex items-center gap-2" style="color: var(--text-primary);">
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded" style="background: var(--brand-button); color: #fff;" x-text="block.badge"></span>
                                        <span x-text="block.address"></span>
                                    </h3>
                                </template>

                                {{-- One field-set per linked contact for this property --}}
                                <template x-for="contact in data.contacts" :key="contact.id">
                                    <div class="mb-4 last:mb-0">
                                        <p class="text-xs font-semibold mb-2" style="color: var(--text-secondary);"
                                           x-text="(block.property_id !== null ? '— ' : '') + contact.label"></p>

                                        {{-- Outcome --}}
                                        <div class="mb-3">
                                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Outcome</label>
                                            <select x-model="form[key(contact.id, block.property_id)].outcome_id"
                                                    class="w-full rounded-md px-3 py-2 text-sm"
                                                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                                <option value="">Select…</option>
                                                <template x-for="o in data.outcomes" :key="o.id">
                                                    <option :value="o.id" x-text="o.label"></option>
                                                </template>
                                            </select>
                                        </div>

                                        {{-- Concerns --}}
                                        <div class="mb-3" x-show="data.concerns.length > 0">
                                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Concerns</label>
                                            <div class="flex flex-wrap gap-2">
                                                <template x-for="c in data.concerns" :key="c.id">
                                                    <label class="inline-flex items-center gap-1.5 text-xs cursor-pointer" style="color: var(--text-primary);">
                                                        <input type="checkbox" :value="c.id" x-model="form[key(contact.id, block.property_id)].concern_ids" class="rounded">
                                                        <span x-text="c.label"></span>
                                                    </label>
                                                </template>
                                            </div>
                                        </div>

                                        {{-- Seller-visible notes --}}
                                        <div class="mb-3">
                                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Seller-visible notes</label>
                                            <textarea x-model="form[key(contact.id, block.property_id)].seller_visible_notes" rows="2"
                                                      class="w-full rounded-md px-3 py-2 text-sm"
                                                      style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                                      placeholder="Shown to seller on live link…"></textarea>
                                        </div>

                                        {{-- Internal notes --}}
                                        <div class="mb-3">
                                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Internal notes</label>
                                            <textarea x-model="form[key(contact.id, block.property_id)].internal_notes" rows="2"
                                                      class="w-full rounded-md px-3 py-2 text-sm"
                                                      style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                                      placeholder="Agent-only notes…"></textarea>
                                        </div>

                                        {{-- Next action --}}
                                        <div>
                                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Next action</label>
                                            <input type="text" x-model="form[key(contact.id, block.property_id)].next_action_notes"
                                                   class="w-full rounded-md px-3 py-2 text-sm"
                                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                                   placeholder="Follow-up action…">
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            {{-- Footer --}}
            <div style="border-top: 1px solid var(--border);">
                <template x-if="error">
                    <div class="px-6 pt-3 text-xs" style="color: var(--ds-crimson, #dc2626);" x-text="error"></div>
                </template>
                <div class="px-6 py-4 flex items-center justify-end gap-2">
                    <button type="button" @click="isOpen = false" class="corex-btn-outline">Cancel</button>
                    <template x-if="!loading && data.feedback_mode !== 'per_property' && data.contacts.length > 0">
                        <button type="button" @click="save()" :disabled="saving" class="corex-btn-primary disabled:opacity-50">
                            <span x-show="!saving">Save Feedback</span>
                            <span x-show="saving" x-cloak>Saving…</span>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function eventFeedbackModal() {
        // URL templates built server-side so we never assume the app's base path.
        const SHOW_URL  = @json(route('command-center.calendar.feedback.show',  ['calendarEvent' => '__ID__']));
        const STORE_URL = @json(route('command-center.calendar.feedback.store', ['calendarEvent' => '__ID__']));
        return {
            isOpen: false,
            loading: false,
            saving: false,
            error: null,
            eventId: null,
            calendarLink: '#',
            data: { event: null, feedback_mode: 'per_contact', contacts: [], properties: [], outcomes: [], concerns: [] },
            // One render block per property (or a single property-less block).
            blocks: [],
            form: {},

            // Stable form key for a (contact, property) pair. property_id may be
            // null for a property-less meeting — encode it explicitly so the key
            // is unambiguous and matches storeFeedback's nullable property_id.
            key(contactId, propertyId) {
                return contactId + '::' + (propertyId === null ? 'none' : propertyId);
            },

            async open(eventId) {
                this.eventId = eventId;
                this.error = null;
                this.saving = false;
                this.loading = true;
                this.isOpen = true;
                this.calendarLink = '{{ route('command-center.calendar') }}?capture_feedback=' + eventId;
                this.data = { event: null, feedback_mode: 'per_contact', contacts: [], properties: [], outcomes: [], concerns: [] };
                this.blocks = [];
                this.form = {};
                try {
                    const r = await fetch(SHOW_URL.replace('__ID__', eventId), {
                        headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
                    });
                    if (!r.ok) {
                        this.error = r.status === 403
                            ? 'You do not have access to this event.'
                            : ('Could not load feedback (HTTP ' + r.status + ').');
                        this.loading = false;
                        return;
                    }
                    const d = await r.json();
                    const mode = d.feedback_mode || 'per_contact';
                    this.data = {
                        event: d.event || null,
                        feedback_mode: mode,
                        contacts: Array.isArray(d.contacts) ? d.contacts : [],
                        properties: Array.isArray(d.properties) ? d.properties : [],
                        outcomes: d.outcomes || [],
                        concerns: d.concerns || [],
                    };

                    if (mode !== 'per_property') {
                        // Build one block per property; a property-less meeting
                        // collapses to a single block with property_id = null.
                        const props = this.data.properties;
                        this.blocks = props.length
                            ? props.map((p, i) => ({
                                key: 'prop:' + p.id,
                                property_id: p.id,
                                address: p.address,
                                badge: 'Property ' + (i + 1) + ' of ' + props.length,
                            }))
                            : [{ key: 'none', property_id: null, address: null, badge: null }];

                        // Pre-fill each (contact, property) field-set from any
                        // existing feedback row for that exact pair.
                        const existing = {};
                        (Array.isArray(d.existing_feedback) ? d.existing_feedback : []).forEach(row => {
                            existing[this.key(row.contact_id, row.property_id ?? null)] = row;
                        });
                        this.data.contacts.forEach(c => {
                            this.blocks.forEach(b => {
                                const k = this.key(c.id, b.property_id);
                                const ex = existing[k] || {};
                                this.form[k] = {
                                    outcome_id: ex.outcome_id ? String(ex.outcome_id) : '',
                                    concern_ids: (ex.concern_ids || []).map(String),
                                    seller_visible_notes: ex.seller_visible_notes || '',
                                    internal_notes: ex.internal_notes || '',
                                    next_action_notes: ex.next_action_notes || '',
                                };
                            });
                        });
                    }
                } catch (e) {
                    this.error = 'Network error: ' + (e.message || 'request failed');
                } finally {
                    this.loading = false;
                }
            },

            buildPayload() {
                // One row per (contact, property) field-set, mirroring the
                // calendar's per-contact viewing payload (property_id nullable).
                return {
                    feedback_kind: 'viewing',
                    feedback: Object.entries(this.form).map(([k, f]) => {
                        const [cidStr, pidStr] = k.split('::');
                        return {
                            contact_id: parseInt(cidStr),
                            property_id: pidStr === 'none' ? null : parseInt(pidStr),
                            outcome_id: f.outcome_id ? parseInt(f.outcome_id) : null,
                            concern_ids: (f.concern_ids || []).map(Number),
                            seller_visible_notes: f.seller_visible_notes || null,
                            internal_notes: f.internal_notes || null,
                            next_action_notes: f.next_action_notes || null,
                        };
                    }),
                };
            },

            async save() {
                this.saving = true;
                this.error = null;
                try {
                    const r = await fetch(STORE_URL.replace('__ID__', this.eventId), {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify(this.buildPayload()),
                    });
                    if (r.ok) {
                        const savedId = this.eventId;
                        this.isOpen = false;
                        window.dispatchEvent(new CustomEvent('corex:feedback-saved', { detail: { eventId: savedId } }));
                        return;
                    }
                    let body = null;
                    try { body = await r.json(); } catch (_) {}
                    const detail = body && body.errors ? Object.values(body.errors).flat().slice(0, 3).join(' · ') : null;
                    this.error = detail || (body && body.message) || ('Save failed (HTTP ' + r.status + ').');
                } catch (e) {
                    this.error = 'Network error: ' + (e.message || 'request failed');
                } finally {
                    this.saving = false;
                }
            },
        };
    }
</script>
@endonce
