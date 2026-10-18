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
                <p class="muted" x-show="faultDetail.repair_asked && faultDetail.repair_asked.owner_comment" data-owner-comment-readback>
                    <strong>Your comment to your agent:</strong> <span x-text="faultDetail.repair_asked && faultDetail.repair_asked.owner_comment ? faultDetail.repair_asked.owner_comment.text : ''"></span>
                    <span x-text="faultDetail.repair_asked && faultDetail.repair_asked.owner_comment ? ' (' + fmtDay(faultDetail.repair_asked.owner_comment.at) + ')' : ''"></span>
                </p>
                <button class="btn btn-primary" x-show="!faultDetail.repair_asked && !repairForm.open" data-repair-anyway @click="openRepairRequest(faultDetail)">Have this repaired anyway</button>
                {{-- AT-452 (§19.12) - the tenant's report, READ-ONLY, then the owner's own comment box. The owner can never edit what the tenant wrote. --}}
                <template x-if="repairForm.open && !faultDetail.repair_asked">
                    <div data-repair-request-form style="margin-top:10px; padding-top:10px; border-top:1px solid #eef1f6;">
                        <h3 style="margin-top:0;">What the tenant reported</h3>
                        <div data-tenant-report style="background:#f6f8fb; border-radius:10px; padding:10px 12px;">
                            <p style="margin:0 0 6px; white-space:pre-line;" data-tenant-report-text x-text="faultDetail.tenant_report && faultDetail.tenant_report.text ? faultDetail.tenant_report.text : 'The tenant did not write anything - they pressed &quot;That fixed it&quot; after reading the suggested steps.'"></p>
                            <p class="muted" style="margin:0;" data-tenant-report-date x-text="'Reported ' + fmtDay(faultDetail.tenant_report ? faultDetail.tenant_report.at : faultDetail.reported_at)"></p>
                            <div class="photo-grid" x-show="faultDetail.tenant_report && faultDetail.tenant_report.photos && faultDetail.tenant_report.photos.length" data-tenant-report-photos>
                                <template x-for="(ph, i) in ((faultDetail.tenant_report && faultDetail.tenant_report.photos) || [])" :key="ph.id"><button type="button" class="photo-btn" :aria-label="'Open photo ' + (i + 1)" @click="openPhotos(faultDetail.tenant_report.photos, i, $event)"><img :src="ph.thumb_url || ph.url" alt="Photo the tenant sent" loading="lazy" decoding="async"></button></template>
                            </div>
                        </div>
                        <label for="owner-comment-repair">Your comment to your agent (optional)</label>
                        <textarea id="owner-comment-repair" rows="3" maxlength="2000" x-model="repairForm.comment" data-owner-comment-input></textarea>
                        <p class="error" x-show="repairForm.error" x-text="repairForm.error"></p>
                        <button class="btn btn-primary" :disabled="!!(repairForm.busy)" @click="submitRepairRequest()" data-repair-submit x-text="repairForm.busy ? 'Sending…' : 'Ask my agent to have this repaired'"></button>
                        <button class="btn btn-outline" @click="repairForm.open = false" data-repair-cancel>Back</button>
                    </div>
                </template>
            </section>
        </template>

        {{-- AT-452 round 2 (item I) - the agent arranged this without waiting (inside the no-approval limit, or an emergency): the owner is INFORMED, never asked. --}}
        <template x-if="faultDetail.arranged">
            <section class="sec" data-fault-section="arranged">
                <h3>Arranged by your agent</h3>
                <p style="margin:0 0 6px;" data-arranged-text x-text="faultDetail.arranged.text"></p>
                <p style="margin:0 0 6px;" x-show="faultDetail.work_order" data-arranged-who x-text="faultDetail.work_order ? (faultDetail.work_order.who_label + (faultDetail.work_order.contractor_name ? ' — ' + faultDetail.work_order.contractor_name : '')) : ''"></p>
                <p style="margin:0 0 6px;" x-show="faultDetail.arranged.amount !== null && faultDetail.arranged.amount !== undefined" data-arranged-amount><strong x-text="(faultDetail.arranged.final_cost !== null ? 'Quoted / approved: ' : 'Quoted / approved: ') + 'R' + Number(faultDetail.arranged.amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></strong></p>
                <p style="margin:0 0 6px;" x-show="faultDetail.arranged.final_cost !== null" data-arranged-final><strong x-text="'Final cost: R ' + Number(faultDetail.arranged.final_cost || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></strong></p>
                <p class="error" style="margin:0 0 6px;" x-show="faultDetail.arranged.needs_owner_approval" data-arranged-over-limit>This is above your repair limit, so it needs your approval before the work goes ahead &mdash; open the work order below.</p>
            </section>
        </template>

        {{-- section 22.5 - the agent recorded what the owner told them: the same sentence they were emailed, the agent's note, and "Query this" --}}
        <template x-if="faultDetail.authorisation">
            <section class="sec" data-fault-section="authorisation">
                <h3 x-text="faultDetail.authorisation.title"></h3>
                <p style="margin:0 0 6px;" data-authorisation-sentence x-text="faultDetail.authorisation.sentence"></p>
                <p class="muted" style="margin:0 0 6px;" x-show="faultDetail.authorisation.note" data-authorisation-note x-text="'What your agent wrote down: &quot;' + faultDetail.authorisation.note + '&quot;'"></p>
            </section>
        </template>

        {{-- section 22.7 - Stop this / Query this for every agent-made notice above (shown once) --}}
        @include('rentals.portal._owner-notice-actions')
        {{-- section 22.13.4 - who pays for this repair (a record; nothing has been charged) --}}
        <section class="sec" data-fault-section="who-pays" x-show="faultDetail.payer_ruling">@include('rentals.portal._who-pays', ['p' => 'faultDetail.payer_ruling'])</section>

        {{-- 1. Progress (and, once a work order exists, where the repair stands) --}}
        <template x-if="!faultDetail.self_resolved && (faultDetail.progress || faultDetail.work_order)">
            <section class="sec" data-fault-section="progress">
                <h3>Progress</h3>
                <template x-if="faultDetail.progress"><div>@include('rentals.portal._progress', ['p' => 'faultDetail.progress'])</div></template>
                <template x-if="faultDetail.work_order">
                    <div data-fault-work-order>
                        <p><strong>The repair</strong> <span class="badge" x-text="faultDetail.work_order.stage_label"></span></p>
                        <p class="muted" x-text="faultDetail.work_order.who_label + (faultDetail.work_order.contractor_name ? ' — ' + faultDetail.work_order.contractor_name : '')"></p>
                        <p class="muted" x-show="faultDetail.work_order.contractor_callout" x-text="faultDetail.work_order.contractor_callout" data-owner-callout></p>
                        <p x-show="faultDetail.work_order.appointment_at"><strong x-text="faultDetail.work_order.appointment_at ? 'Appointment: ' + fmtDateTime(faultDetail.work_order.appointment_at, true) : ''"></strong></p>
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

        {{-- 5. Your decision: the controls while it is waiting; once the OWNER pressed it, the answer. When an agent wrote it down for the owner, the sentence above ("Authorised on your instruction") says it once - nothing is repeated here. --}}
        <template x-if="faultDetail.awaiting_decision || (faultDetail.decision && faultDetail.decision.via_link)">
            <section class="sec" data-fault-section="decision">
                <h3>Your decision</h3>
                <template x-if="faultDetail.decision && faultDetail.decision.via_link">
                    <div>
                        <p><strong x-text="faultDetail.decision.decision === 'approved' ? 'You asked for someone to look and quote.' : 'You declined this repair.'"></strong></p>
                        <p class="muted" x-show="faultDetail.decision.reason" x-text="'Reason: ' + faultDetail.decision.reason"></p>
                        <p class="muted" x-show="faultDetail.decision.contractor" x-text="faultDetail.decision.contractor"></p>
                        <p class="muted" data-decision-when x-text="'On ' + fmtDateTime(faultDetail.decision.at)"></p>
                        <p class="muted" x-show="faultDetail.owner_comment" data-owner-comment-readback><strong>Your comment to your agent:</strong> <span x-text="faultDetail.owner_comment ? faultDetail.owner_comment.text : ''"></span></p>
                        <p class="muted" x-show="faultDetail.decision.decision === 'approved'" data-decision-next>Your agent will arrange it and tell you. You will be asked again before any repair cost is approved.</p>
                    </div>
                </template>
                <template x-if="faultDetail.awaiting_decision">
                    <div>
                        {{-- the agent suggested someone: ONE press accepts them; or choose someone else; or decline. Nothing is spent yet. --}}
                        <template x-if="faultDetail.proposed && !faultForm.other">
                            <div data-proposed-choice>
                                <p style="margin:0 0 6px;" data-proposed-inspector><strong x-text="'Your agent would like to send ' + faultDetail.proposed.name + ' to look at this and give you a quote.'"></strong></p>
                                <p style="margin:0 0 6px;" x-show="faultDetail.proposed.callout" data-owner-callout x-text="faultDetail.proposed.callout"></p>
                                <p class="muted" style="margin:0 0 6px;">Nothing is spent yet: you will be asked again before any repair cost is approved.</p>
                                <label for="owner-comment-proposed">Your comment to your agent (optional)</label>
                                <textarea id="owner-comment-proposed" rows="2" maxlength="2000" x-model="faultForm.note" data-owner-comment-input></textarea>
                                <p class="error" x-show="faultForm.error" x-text="faultForm.error"></p>
                                <div class="btn-stack" data-owner-choice-buttons>
                                <button class="btn btn-ok" :disabled="!!(faultForm.busy)" @click="sendProposed()" data-owner-yes x-text="faultForm.busy ? 'Sending…' : 'Yes, send ' + faultDetail.proposed.name"></button>
                                <button class="btn btn-outline" :disabled="!!(faultForm.busy)" @click="chooseOther('approve')" data-owner-someone-else>Choose someone else</button>
                                <button class="btn btn-outline" :disabled="!!(faultForm.busy)" @click="chooseOther('decline')" data-owner-decline>Decline</button>
                                </div>
                            </div>
                        </template>
                        <template x-if="!faultDetail.proposed || faultForm.other">
                            <div>
                                <p class="muted" style="margin:0 0 8px;" x-show="faultDetail.proposed && faultForm.other">Your agent suggested <span x-text="faultDetail.proposed ? faultDetail.proposed.name : ''"></span>. Choose who you would rather use, or decline.</p>
                                <label style="margin-top:0;">What would you like to do?</label>
                                <select x-model="faultForm.decision" data-owner-decision-select>
                                    <option value="">Choose…</option>
                                    <option value="approve">Send someone to look and quote</option>
                                    <option value="decline">Decline</option>
                                </select>
                                <template x-if="faultForm.decision === 'approve'">
                                    <div>
                                        <label>Who should look and quote?</label>
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
                                {{-- AT-452 (§19.12) - the owner's own comment to the agent, for every decision (for a decline it is the reason, and it is required). --}}
                                <template x-if="faultForm.decision">
                                    <div data-owner-comment-box>
                                        <label for="owner-comment-decision" data-owner-comment-label x-text="faultForm.decision === 'decline' ? 'Your comment to your agent (required - please tell us why you are declining)' : 'Your comment to your agent (optional)'"></label>
                                        <textarea id="owner-comment-decision" rows="3" maxlength="2000" x-model="faultForm.note" data-owner-comment-input></textarea>
                                    </div>
                                </template>
                                <p class="error" x-show="faultForm.error" x-text="faultForm.error"></p>
                                <button class="btn btn-ok" :disabled="!!(faultForm.busy || !faultForm.decision)" @click="submitFaultDecision()">Send my decision</button>
                            </div>
                        </template>
                    </div>
                </template>
            </section>
        </template>
        <button class="btn btn-outline" @click="faultDetail = null">Close</button>
    </div>
</template>
