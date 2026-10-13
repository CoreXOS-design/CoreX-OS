{{-- section 22.7 - "Stop this" and "Query this" on EVERY notice about something the agent did without the owner deciding. Two equal, aligned buttons (side by side at 390px);
     "Stop this" until the work has started, then the line "Work has started - contact your agent" + Query this; while it is on hold: "Go ahead after all" + Query this.
     Needs the Alpine `noticeUi`, `sendStop()`, `sendResume()`, `sendQuery()` of the portal shell and `faultDetail.owner_actions`. --}}
<template x-if="faultDetail.owner_actions && faultDetail.owner_actions.show">
    <section class="sec" data-fault-section="owner-actions">
        <h3>Your say</h3>

        {{-- on hold --}}
        <template x-if="faultDetail.owner_actions.hold">
            <div data-owner-hold>
                <p class="error" style="margin:0 0 6px;font-weight:700;" data-owner-hold-banner>On hold &mdash; you stopped this.</p>
                <p class="muted" style="margin:0 0 6px;" data-owner-hold-line x-text="faultDetail.owner_actions.hold.line"></p>
                <p class="muted" style="margin:0 0 6px;">Nothing more will be sent or started until you say go ahead.</p>
            </div>
        </template>
        {{-- work already started --}}
        <template x-if="!faultDetail.owner_actions.hold && faultDetail.owner_actions.work_started">
            <p style="margin:0 0 6px;" data-work-started><strong>Work has started &mdash; contact your agent.</strong></p>
        </template>
        <template x-if="!faultDetail.owner_actions.hold && faultDetail.owner_actions.closed">
            <p class="muted" style="margin:0 0 6px;" data-job-closed>This job is closed, so there is nothing to stop.</p>
        </template>

        <div class="btn-pair btn-pair-keep" data-owner-action-buttons>
            <template x-if="faultDetail.owner_actions.hold"><button class="btn btn-primary" :disabled="!!noticeUi.busy" @click="sendResume()" data-go-ahead>Go ahead after all</button></template>
            <template x-if="!faultDetail.owner_actions.hold && faultDetail.owner_actions.can_stop"><button class="btn btn-danger" @click="noticeUi.panel = (noticeUi.panel === 'stop' ? null : 'stop'); noticeUi.error = null" data-stop-this>Stop this</button></template>
            <template x-if="!faultDetail.owner_actions.hold && !faultDetail.owner_actions.can_stop"><span class="btn-spacer" aria-hidden="true"></span></template>
            <button class="btn btn-outline" @click="noticeUi.panel = (noticeUi.panel === 'query' ? null : 'query'); noticeUi.error = null" data-query-this>Query this</button>
        </div>

        {{-- Stop this: a reason (required) and a confirm --}}
        <div x-show="noticeUi.panel === 'stop' && faultDetail.owner_actions.can_stop" data-stop-panel>
            <label style="margin-top:10px;">Why do you want to stop it? (required)</label>
            <textarea rows="2" maxlength="500" x-model="noticeUi.reason" placeholder="A short reason" data-stop-reason></textarea>
            <p class="muted">The job goes on hold at once. Your agents and the contractor are told.</p>
            <div class="btn-pair btn-pair-keep">
                <button class="btn btn-danger" :disabled="!!(noticeUi.busy || noticeUi.reason.trim().length < 3)" @click="sendStop()" data-confirm-stop>Yes, stop this</button>
                <button class="btn btn-outline" @click="noticeUi.panel = null" data-cancel-stop>Not now</button>
            </div>
        </div>

        {{-- Query this: asking stops nothing --}}
        <div x-show="noticeUi.panel === 'query'" data-query-panel>
            <label style="margin-top:10px;">Your question for your agent</label>
            <textarea rows="2" maxlength="1000" x-model="queryForm.note" placeholder="Ask your agent a question about this repair" data-query-note></textarea>
            <p class="muted">Asking a question does not stop anything.</p>
            <p class="muted" x-show="queryForm.sent" data-query-sent>Sent &mdash; your agent has been told.</p>
            <button class="btn btn-outline" :disabled="!!(queryForm.busy || queryForm.note.trim().length < 3)" @click="sendQuery()" data-query-send>Send my question</button>
        </div>

        <p class="error" x-show="noticeUi.error" x-text="noticeUi.error" data-notice-error></p>

        {{-- the questions asked so far, with the agents' answers --}}
        <template x-for="q in (faultDetail.owner_actions.queries || [])" :key="q.id">
            <div style="margin-top:10px;border-top:1px solid var(--border);padding-top:6px;" data-owner-question>
                <p class="muted" style="margin:0 0 2px;" x-text="'You asked ' + fmtDay(q.at) + ': ' + q.question"></p>
                <p style="margin:0;" x-show="q.answer" data-owner-answer x-text="(q.answered_by || 'Your agent') + ' answered: ' + q.answer"></p>
                <p class="muted" style="margin:0;" x-show="!q.answer">Your agent has not answered yet.</p>
            </div>
        </template>
    </section>
</template>
