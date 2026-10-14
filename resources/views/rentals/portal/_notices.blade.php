{{-- .ai/specs/lease-notices.md §8 - the Notices tab, one panel for the tenant AND the owner: give notice, and see where the notices you gave stand.
     Reads /api/v1/client/rentals[/landlord]/notices (the person's OWN side only). Giving notice tells your agent and nobody else. --}}
<div class="card" data-portal-notices>
    <h2>Notices</h2>
    <p class="muted" style="margin:-4px 0 10px;" x-text="activeRole === 'landlord'
        ? 'Give notice on a lease at one of your properties. It goes to your agent first, who checks it against the lease and the law - nothing goes to your tenant until they have.'
        : 'Give notice to end your lease. It goes to your agent first, who checks it against your lease and the law - nothing goes to your landlord until they have.'"></p>

    <template x-if="noticePanel.error"><p class="error" x-text="noticePanel.error"></p></template>

    <template x-if="noticePanel.loaded && noticePanel.leases.length === 0">
        <p class="muted" data-notice-no-lease>There is no running lease to give notice on.</p>
    </template>

    <template x-if="noticePanel.done">
        <p class="success" data-notice-done x-text="noticePanel.done"></p>
    </template>

    <template x-if="noticePanel.leases.length > 0 && !noticePanel.form.open">
        <button class="btn btn-primary" data-notice-open @click="openNoticeForm()">Give notice</button>
    </template>

    <template x-if="noticePanel.form.open">
        <div data-notice-form>
            <template x-if="noticePanel.leases.length > 1">
                <div>
                    <label>Which lease</label>
                    <select x-model="noticePanel.form.lease_id" aria-label="Lease">
                        <template x-for="l in noticePanel.leases" :key="l.id"><option :value="String(l.id)" x-text="l.address"></option></template>
                    </select>
                </div>
            </template>
            <label>Date you gave notice</label>
            <input type="date" x-model="noticePanel.form.notice_date" :max="noticeToday()" aria-label="Date you gave notice">
            <label x-text="activeRole === 'landlord' ? 'Date you want the tenancy to end' : 'Date you plan to move out'"></label>
            <input type="date" x-model="noticePanel.form.intended_end_date" aria-label="Move-out date">
            <label>Reason (optional)</label>
            <textarea x-model="noticePanel.form.reason" rows="3" maxlength="2000" aria-label="Reason"></textarea>
            <label>Attach your letter or email (optional - PDF, photo or Word, up to 10 MB)</label>
            <input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" @change="pickNoticeFile($event)" aria-label="Attachment">
            <template x-if="noticePanel.form.error"><p class="error" data-notice-error x-text="noticePanel.form.error"></p></template>
            <div style="display:flex; gap:8px; margin-top:10px;">
                <button class="btn btn-primary" :disabled="!!(noticePanel.form.sending)" @click="submitNotice()" x-text="noticePanel.form.sending ? 'Sending…' : 'Send my notice'"></button>
                <button class="btn" :disabled="!!(noticePanel.form.sending)" @click="noticePanel.form.open = false">Cancel</button>
            </div>
        </div>
    </template>

    <template x-for="n in noticePanel.notices" :key="n.id">
        <div class="list-item" data-portal-notice>
            <div class="row"><strong x-text="n.address"></strong><span class="muted" x-text="n.status_label"></span></div>
            <div class="muted" x-text="'Given ' + n.notice_date + ' · moving out ' + (n.accepted_end_date || n.intended_end_date)"></div>
            <div x-show="n.reason" class="muted" x-text="'Reason: ' + n.reason"></div>
            <div data-notice-status-line x-text="n.status_line"></div>
        </div>
    </template>
    <template x-if="noticePanel.loaded && noticePanel.notices.length === 0 && noticePanel.leases.length > 0">
        <p class="muted" data-notice-none>You have not given any notice.</p>
    </template>
    <template x-if="noticePanel.fromOther.length > 0">
        <div data-notices-from-other style="margin-top:14px;">
            <h2 x-text="activeRole === 'landlord' ? 'Notice from your tenant' : 'Notice from your landlord'"></h2>
            <template x-for="n in noticePanel.fromOther" :key="n.id">
                <div class="list-item" data-portal-notice-received>
                    <div class="row"><strong x-text="n.address"></strong></div>
                    <div x-text="n.status_line"></div>
                </div>
            </template>
        </div>
    </template>
    <p class="muted" style="margin-top:10px;">Breach notices and notices to vacate that your agent issues are under <a class="link" href="#" @click.prevent="(activeRole === 'landlord' ? landlordTab = 'documents' : tenantTab = 'documents'); loadDocuments()">Documents</a>.</p>
</div>
