{{-- .ai/specs/rentals-faults-work-orders.md section 31.2 - the tenant's "Report a fault": ONE form, ONE submit. The description and photos are the report; the
     problem type is optional (the agent classifies an untyped report); when a type is picked its first-aid advice sits beside the form as a panel that never blocks the
     submit ("That fixed it" is a small secondary button inside it). Used on the portal Home and on the Faults tab - same Alpine state (faultWizard), never both at once. --}}
<template x-if="faultWizard.open">
    <div class="card" data-fault-wizard>
        {{-- Done: a clear success state that already shows where the report is (the progress line) - nothing left to press twice. --}}
        <template x-if="faultWizard.step === 'done'">
            <div class="done-card" data-fault-done>
                <div class="tick">&#10003;</div>
                <h2 style="margin-bottom:4px;" x-text="faultWizard.doneTitle"></h2>
                <p class="muted" style="margin:0 0 8px;" x-text="faultWizard.doneText"></p>
                <template x-if="faultWizard.reported && faultWizard.reported.progress"><div style="text-align:left;" data-fault-done-progress>@include('rentals.portal._progress', ['p' => 'faultWizard.reported.progress'])</div></template>
                <button class="btn btn-primary" @click="closeFaultWizard()">Done</button>
            </div>
        </template>

        <template x-if="faultWizard.step !== 'done'">
            <div data-fault-form>
                <label style="margin-top:0;">What is wrong?</label>
                <textarea rows="3" x-model="faultWizard.description" :disabled="!!(faultWizard.sending)" placeholder="Tell us in a few words" data-fault-description></textarea>

                <label>What kind of problem is it? <span class="muted">(optional)</span></label>
                <select x-model="faultWizard.faultTypeId" :disabled="!!(faultWizard.sending)" @change="selectFaultType()" data-fault-type-select>
                    <option value="">Not sure / not on the list</option>
                    <template x-for="ft in (faultTypesByProperty[faultWizard.property] || [])" :key="ft.id">
                        <option :value="ft.id" x-text="ft.name"></option>
                    </template>
                </select>

                {{-- Try this first: advice for the chosen type, beside the form - it never blocks the report --}}
                <template x-if="faultWizard.hasAid">
                    <details open style="margin-top:12px;" data-fault-aid-step>
                        <summary style="cursor:pointer;font-weight:600;">Try this first</summary>
                        @include('rentals.portal._fault-aid', ['w' => 'faultWizard', 'tenant' => true])
                        <button class="btn btn-ok" type="button" :disabled="!!(faultWizard.sending)" @click="submitFault('first_aid_resolved')" data-fault-fixed-it x-text="faultWizard.sending ? 'Sending…' : 'That fixed it - no need to report'"></button>
                    </details>
                </template>

                <label>Photos</label>
                @include('rentals.portal._photo-picker', ['bind' => 'faultWizard'])
                <button class="btn btn-danger" data-fault-submit :disabled="!!(faultWizard.sending || faultWizard.photoBusy)" @click="submitFault('still_a_problem')"
                        x-text="faultWizard.sending ? ('Sending…' + (faultWizard.progress ? ' ' + faultWizard.progress + '%' : '')) : (faultWizard.photoBusy ? 'Preparing photos…' : 'Report fault')"></button>
                <div class="progress" x-show="faultWizard.sending"><span :style="'width:' + (faultWizard.progress || 5) + '%'"></span></div>
                <p class="error" x-show="faultWizard.error" x-text="faultWizard.error"></p>
                <button class="btn btn-outline" :disabled="!!(faultWizard.sending)" @click="closeFaultWizard()">Cancel</button>
            </div>
        </template>
    </div>
</template>
