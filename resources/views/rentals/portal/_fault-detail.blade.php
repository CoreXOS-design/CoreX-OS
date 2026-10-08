{{-- The owner's fault screen (fault flow F3/F4/F7 + W2): the agent's version of the fault, the decision controls, the decision
     read-only once made, and the work order's stage / who / appointment. ONE card, used by the Faults tab; the Decisions list is a
     shortcut that opens the same card. --}}
<template x-if="faultDetail">
    <div class="card" data-fault-detail>
        <p class="badge" style="background:#fdecea; color:#b3261e;" x-show="faultDetail.awaiting_decision" data-needs-decision-banner>Needs your decision</p>
        <h2 x-text="faultDetail.title"></h2>
        <p class="muted" x-text="(faultDetail.property || '') + ' · ' + faultDetail.status_label"></p>
        <template x-if="faultDetail.progress"><div>@include('rentals.portal._progress', ['p' => 'faultDetail.progress'])</div></template>
        <p x-show="faultDetail.description" x-text="faultDetail.description"></p>
        <div class="photo-grid" x-show="faultDetail.photos.length">
            <template x-for="ph in faultDetail.photos" :key="ph.id">
                <a :href="ph.url" target="_blank" rel="noopener"><img :src="ph.thumb_url || ph.url" alt="Photo of the fault" loading="lazy" decoding="async"></a>
            </template>
        </div>
        <p x-show="faultDetail.agent_note"><strong>Your agent says:</strong> <span x-text="faultDetail.agent_note"></span></p>

        <template x-if="faultDetail.decision">
            <div>
                <p><strong x-text="faultDetail.decision.decision === 'approved' ? 'You approved this repair.' : 'This repair was declined.'"></strong></p>
                <p class="muted" x-show="faultDetail.decision.reason" x-text="'Reason: ' + faultDetail.decision.reason"></p>
                <p class="muted" x-show="faultDetail.decision.contractor" x-text="faultDetail.decision.contractor"></p>
                <p class="muted" x-text="faultDetail.decision.how + ' · ' + new Date(faultDetail.decision.at).toLocaleString()"></p>
            </div>
        </template>

        {{-- Once a work order exists: where the repair stands (plain stage, who, the appointment). --}}
        <template x-if="faultDetail.work_order">
            <div data-fault-work-order style="margin-top:10px; padding-top:10px; border-top:1px solid #eef1f6;">
                <p><strong>The repair</strong> <span class="badge" x-text="faultDetail.work_order.stage_label"></span></p>
                <p class="muted" x-text="faultDetail.work_order.who_label + (faultDetail.work_order.contractor_name ? ' — ' + faultDetail.work_order.contractor_name : '')"></p>
                <p x-show="faultDetail.work_order.appointment_at"><strong x-text="faultDetail.work_order.appointment_at ? 'Appointment: ' + new Date(faultDetail.work_order.appointment_at).toLocaleString([], {dateStyle:'full', timeStyle:'short'}) : ''"></strong></p>
                <button class="btn btn-outline" @click="landlordTab='jobs'; loadWorkOrders(); loadDecisions()">Open the work order</button>
            </div>
        </template>

        <template x-if="faultDetail.awaiting_decision">
            <div>
                <label>Your decision</label>
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
                            <option value="list" :hidden="!faultDetail.contractors.length" :disabled="!!(!faultDetail.contractors.length)">A contractor from my agent's list</option>
                            <option value="agency">My agent arranges it</option>
                        </select>
                        <p class="muted" x-show="!faultDetail.contractors.length">Your agent has no contractor on file for this type of work yet. You can use your own, or ask your agent to arrange it.</p>
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
        <button class="btn btn-outline" @click="faultDetail = null">Close</button>
    </div>
</template>
