{{-- The owner's fault screen (fault flow F3/F4/F7 + W2): the agent's version of the fault, the decision controls, the decision
     read-only once made, and the work order's stage / who / appointment. ONE card, used by the Faults tab; the Decisions list is a
     shortcut that opens the same card.
     AT-452 item 7 - clearly demarcated sections, each with a heading and a dividing line, in this order: Progress; What the tenant
     reported; Photos; What your agent says; Your decision. Same data as before; a section with nothing in it is left out. --}}
<template x-if="faultDetail">
    <div class="card" data-fault-detail>
        <p class="badge" style="background:#fdecea; color:#b3261e;" x-show="faultDetail.awaiting_decision" data-needs-decision-banner>Needs your decision</p>
        <h2 x-text="faultDetail.title"></h2>
        <p class="muted" x-text="(faultDetail.property || '') + ' · ' + faultDetail.status_label"></p>
        <p class="muted" x-show="faultDetail.reported_before" data-reported-before>
            <span x-text="faultDetail.reported_before && faultDetail.reported_before.text"></span>
            <a class="link" href="#" x-show="faultDetail.reported_before && faultDetail.reported_before.fault_id" @click.prevent="openFault(faultDetail.reported_before.fault_id)"> - see it</a>
        </p>

        {{-- AT-452 round 2 (F-OWNER) - a fault the tenant fixed themselves: shown to the owner, never a decision. --}}
        <template x-if="faultDetail.self_resolved">
            <section class="sec" data-fault-section="self-resolved">
                <h3>Resolved without a repair</h3>
                <p style="margin:0 0 8px;" data-self-resolved-text>
                    <span x-text="faultDetail.type_name + ' - ' + (faultDetail.property || '') + '. Reported by the tenant ' + fmtDay(faultDetail.reported_at) + '. '"></span><span x-show="faultDetail.advice" x-text="'Resolved with the suggested step: ' + faultDetail.advice + '. '"></span><strong>No cost.</strong>
                    <span x-show="faultDetail.tenant_description" x-text="' The tenant wrote: ' + faultDetail.tenant_description"></span>
                </p>
                <p class="muted" x-show="faultDetail.repair_asked" data-repair-asked>Owner asked for a repair on <span x-text="fmtDay(faultDetail.repair_asked && faultDetail.repair_asked.at)"></span> &mdash; <a class="link" href="#" @click.prevent="openFault(faultDetail.repair_asked.id)">see the request</a></p>
                <button class="btn btn-outline" x-show="!faultDetail.repair_asked" data-repair-anyway @click="repairAnyway(faultDetail)">Have this repaired anyway</button>
            </section>
        </template>

        {{-- AT-452 round 2 (item I) - the agent arranged this without waiting (inside the no-approval limit, or an emergency): the owner is INFORMED, never asked. --}}
        <template x-if="faultDetail.arranged">
            <section class="sec" data-fault-section="arranged">
                <h3>Arranged by your agent</h3>
                <p style="margin:0 0 6px;" data-arranged-text x-text="faultDetail.arranged.text"></p>
                <p style="margin:0 0 6px;" x-show="faultDetail.work_order" data-arranged-who x-text="faultDetail.work_order ? (faultDetail.work_order.who_label + (faultDetail.work_order.contractor_name ? ' — ' + faultDetail.work_order.contractor_name : '')) : ''"></p>
                <p style="margin:0 0 6px;" x-show="faultDetail.arranged.amount !== null && faultDetail.arranged.amount !== undefined" data-arranged-amount><strong x-text="(faultDetail.arranged.final_cost !== null ? 'Quoted / approved: ' : 'Quoted / approved: ') + 'R ' + Number(faultDetail.arranged.amount || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></strong></p>
                <p style="margin:0 0 6px;" x-show="faultDetail.arranged.final_cost !== null" data-arranged-final><strong x-text="'Final cost: R ' + Number(faultDetail.arranged.final_cost || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></strong></p>
                <p class="error" style="margin:0 0 6px;" x-show="faultDetail.arranged.needs_owner_approval" data-arranged-over-limit>This has gone over your no-approval limit, so it needs your approval before the work goes ahead &mdash; open the work order below.</p>
                <template x-for="(q, qi) in (faultDetail.arranged.queries || [])" :key="qi"><p class="muted" style="margin:0 0 4px;" x-text="'You asked ' + fmtDay(q.at) + ': ' + q.note"></p></template>
                <label style="margin-top:8px;">Query this</label>
                <textarea rows="2" maxlength="1000" x-model="queryForm.note" placeholder="Ask your agent a question about this repair" data-query-note></textarea>
                <p class="error" x-show="queryForm.error" x-text="queryForm.error"></p>
                <p class="muted" x-show="queryForm.sent" data-query-sent>Sent &mdash; your agent has been told.</p>
                <button class="btn btn-outline" :disabled="!!(queryForm.busy || queryForm.note.trim().length < 3)" @click="sendQuery()" data-query-send>Send my question</button>
            </section>
        </template>

        {{-- 1. Progress (and, once a work order exists, where the repair stands) --}}
        <template x-if="!faultDetail.self_resolved && (faultDetail.progress || faultDetail.work_order)">
            <section class="sec" data-fault-section="progress">
                <h3>Progress</h3>
                <template x-if="faultDetail.progress"><div>@include('rentals.portal._progress', ['p' => 'faultDetail.progress'])</div></template>
                <template x-if="faultDetail.work_order">
                    <div data-fault-work-order>
                        <p><strong>The repair</strong> <span class="badge" x-text="faultDetail.work_order.stage_label"></span></p>
                        <p class="muted" x-text="faultDetail.work_order.who_label + (faultDetail.work_order.contractor_name ? ' — ' + faultDetail.work_order.contractor_name : '')"></p>
                        <p x-show="faultDetail.work_order.appointment_at"><strong x-text="faultDetail.work_order.appointment_at ? 'Appointment: ' + new Date(faultDetail.work_order.appointment_at).toLocaleString([], {dateStyle:'full', timeStyle:'short'}) : ''"></strong></p>
                        <button class="btn btn-outline" @click="landlordTab='jobs'; loadWorkOrders(); loadDecisions()">Open the work order</button>
                    </div>
                </template>
            </section>
        </template>

        {{-- 2. What the tenant reported ("What you reported" when the owner raised it) --}}
        <template x-if="faultDetail.description">
            <section class="sec" data-fault-section="reported">
                <h3 x-text="faultDetail.reported_by_owner ? 'What you reported' : 'What the tenant reported'"></h3>
                <p style="margin:0;" x-text="faultDetail.description"></p>
            </section>
        </template>

        {{-- 3. Photos - open in the shared viewer --}}
        <template x-if="faultDetail.photos.length">
            <section class="sec" data-fault-section="photos">
                <h3>Photos</h3>
                <div class="photo-grid">
                    <template x-for="(ph, i) in faultDetail.photos" :key="ph.id">
                        <button type="button" class="photo-btn" :aria-label="'Open photo ' + (i + 1)" @click="openPhotos(faultDetail.photos, i, $event)"><img :src="ph.thumb_url || ph.url" :alt="ph.alt || 'Photo of the fault'" loading="lazy" decoding="async"></button>
                    </template>
                </div>
            </section>
        </template>

        {{-- 4. What your agent says --}}
        <template x-if="faultDetail.agent_note">
            <section class="sec" data-fault-section="agent">
                <h3>What your agent says</h3>
                <p style="margin:0;" x-text="faultDetail.agent_note"></p>
            </section>
        </template>

        {{-- 5. Your decision: the answer once made, or the controls while it is waiting --}}
        <template x-if="faultDetail.decision || faultDetail.awaiting_decision">
            <section class="sec" data-fault-section="decision">
                <h3>Your decision</h3>
                <template x-if="faultDetail.decision">
                    <div>
                        <p><strong x-text="faultDetail.decision.decision === 'approved' ? 'You approved this repair.' : 'This repair was declined.'"></strong></p>
                        <p class="muted" x-show="faultDetail.decision.reason" x-text="'Reason: ' + faultDetail.decision.reason"></p>
                        <p class="muted" x-show="faultDetail.decision.contractor" x-text="faultDetail.decision.contractor"></p>
                        <p class="muted" x-text="faultDetail.decision.how + ' · ' + new Date(faultDetail.decision.at).toLocaleString()"></p>
                    </div>
                </template>
                <template x-if="faultDetail.awaiting_decision">
                    <div>
                        <label style="margin-top:0;">Approve or decline?</label>
                        <select x-model="faultForm.decision">
                            <option value="">Choose…</option>
                            <option value="approve">Approve</option>
                            <option value="decline">Decline</option>
                        </select>
                        <template x-if="faultForm.decision === 'decline'">
                            <div>
                                <label>Why are you declining? (required)</label>
                                <textarea rows="3" x-model="faultForm.note"></textarea>
                            </div>
                        </template>
                        <template x-if="faultForm.decision === 'approve'">
                            <div>
                                <label>Who should handle the repair?</label>
                                <select x-model="faultForm.handled_by">
                                    <option value="">Choose…</option>
                                    <option value="own">My own contractor</option>
                                    <option value="list" :hidden="!faultDetail.has_contractor" :disabled="!!(!faultDetail.has_contractor)">A contractor from my agent's list</option>
                                    <option value="agency">My agent arranges it</option>
                                </select>
                                {{-- AT-452 item 9 - the same "is a contractor loaded for this type of work?" answer the agent's warning uses (has_contractor). --}}
                                <p class="muted" x-show="!faultDetail.has_contractor" data-no-contractor-note>Your agent has no contractor on file for this type of work yet. You can use your own, or ask your agent to arrange it.</p>
                                <template x-if="faultForm.handled_by === 'own'">
                                    <div>
                                        <label>Contractor's name (optional)</label>
                                        <input type="text" x-model="faultForm.contractor_name" maxlength="191">
                                        <label>Contractor's phone number (optional)</label>
                                        <input type="text" x-model="faultForm.contractor_phone" maxlength="40">
                                        <p class="muted">Your agent may need to speak to them about access and the work.</p>
                                    </div>
                                </template>
                                <template x-if="faultForm.handled_by === 'list'">
                                    <div>
                                        <label>Choose a contractor</label>
                                        <select x-model="faultForm.agency_service_provider_id">
                                            <option value="">Choose…</option>
                                            <template x-for="c in faultDetail.contractors" :key="c.id"><option :value="c.id" x-text="c.name"></option></template>
                                        </select>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <p class="error" x-show="faultForm.error" x-text="faultForm.error"></p>
                        <button class="btn btn-ok" :disabled="!!(faultForm.busy || !faultForm.decision)" @click="submitFaultDecision()">Send my decision</button>
                    </div>
                </template>
            </section>
        </template>
        <button class="btn btn-outline" @click="faultDetail = null">Close</button>
    </div>
</template>
