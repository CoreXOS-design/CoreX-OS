{{--
    Tenant/Landlord Rentals Portal — AT-445, .ai/specs/rental-portal-access.md §9
    Single Blade shell, client-side "router". Session-cookie auth (Sanctum
    stateful SPA) against the SAME /api/v1/client-auth/* + /api/v1/client/*
    endpoints the mobile app reaches with bearer tokens — no token is ever
    stored where this page's own JavaScript could read it back out.
    Mobile-first (~390px) — tenants and landlords mostly use phones.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>My Rentals</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root { --bg:#f4f6fb; --surface:#fff; --surface-2:#f0f2f8; --border:rgba(0,0,0,.08); --text:#141821; --muted:#5a6472; --brand:#0b2a4a; --accent:#00b4d8; --danger:#c0392b; --ok:#1f8a4c; }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--text); font-family:'Figtree', system-ui, sans-serif; -webkit-font-smoothing:antialiased; }
        .wrap { max-width: 480px; margin: 0 auto; padding: 16px 16px 48px; }
        header.top { background:#fff; color:var(--brand); padding:10px 16px; min-height:56px; display:flex; align-items:center; justify-content:space-between; gap:12px; border-bottom:3px solid var(--brand); }
        header.top h1 { font-size:16px; margin:0; font-weight:700; }
        header.top .logo { max-height:38px; max-width:200px; object-fit:contain; display:block; }
        header.top button { background:transparent; border:1px solid var(--border); color:var(--brand); border-radius:8px; padding:6px 10px; font-size:13px; }
        .card { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:16px; margin-bottom:14px; }
        .muted { color:var(--muted); font-size:13px; }
        .row { display:flex; justify-content:space-between; align-items:center; gap:8px; }
        h2 { font-size:15px; margin:0 0 10px; }
        label { display:block; font-size:13px; font-weight:600; margin:10px 0 4px; }
        input[type=text], input[type=email], input[type=password], input[type=number], input[type=date], textarea, select {
            width:100%; padding:11px 12px; border:1px solid var(--border); border-radius:10px; font-size:15px; font-family:inherit; background:var(--surface-2);
        }
        .btn { display:inline-block; width:100%; text-align:center; padding:12px; border-radius:10px; border:none; font-weight:700; font-size:15px; cursor:pointer; margin-top:10px; }
        .btn-primary { background:var(--accent); color:#fff; }
        .btn-outline { background:transparent; border:1px solid var(--border); color:var(--text); }
        .btn-danger { background:var(--danger); color:#fff; }
        .btn-ok { background:var(--ok); color:#fff; }
        .tabs { display:flex; gap:6px; margin-bottom:14px; overflow-x:auto; }
        .tabs button { flex:1 1 auto; white-space:nowrap; padding:10px 8px; border-radius:10px; border:1px solid var(--border); background:var(--surface); font-weight:600; font-size:13px; }
        .tabs button.active { background:var(--brand); color:#fff; border-color:var(--brand); }
        .badge { display:inline-block; font-size:11px; font-weight:700; padding:3px 8px; border-radius:999px; background:var(--surface-2); color:var(--muted); text-transform:uppercase; }
        .error { color:var(--danger); font-size:13px; margin-top:6px; }
        .success { color:var(--ok); font-size:13px; margin-top:6px; }
        .list-item { border-bottom:1px solid var(--border); padding:10px 0; }
        .list-item:last-child { border-bottom:none; }
        a.link { color:var(--accent); text-decoration:none; font-weight:600; font-size:13px; }
        .photo-grid { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
        .photo-grid img { width:72px; height:72px; object-fit:cover; border-radius:8px; border:1px solid var(--border); }
        button:disabled, .btn:disabled { opacity:.55; cursor:not-allowed; }
        .photo-actions { display:flex; gap:8px; }
        .photo-actions .pick { margin-top:0; position:relative; overflow:hidden; flex:1 1 0; }
        .file-hidden { position:absolute; inset:0; width:100%; height:100%; opacity:0; cursor:pointer; font-size:0; }
        .thumb { position:relative; width:72px; height:72px; }
        .thumb-x { position:absolute; top:-6px; right:-6px; width:24px; height:24px; border-radius:50%; border:none; background:var(--danger); color:#fff; font-size:16px; line-height:24px; padding:0; cursor:pointer; }
        .progress { height:6px; border-radius:3px; background:var(--surface-2); overflow:hidden; margin-top:8px; }
        .progress > span { display:block; height:100%; background:var(--accent); transition:width .15s linear; }
        .faq { border-top:1px solid var(--border); }
        .faq:first-child { border-top:none; }
        .faq-q { width:100%; display:flex; justify-content:space-between; align-items:center; gap:8px; background:none; border:none; padding:10px 0; font:inherit; font-weight:600; font-size:14px; text-align:left; color:var(--text); cursor:pointer; }
        .faq-mark { color:var(--muted); font-size:18px; line-height:1; }
        .faq-a { margin:0 0 10px; font-size:14px; color:var(--text); }
        .aid-urgent { background:#fdecea; color:var(--danger); border:1px solid var(--danger); border-radius:10px; padding:8px 10px; font-weight:700; font-size:13px; margin-bottom:8px; }
        .aid-steps { white-space:pre-line; margin:0 0 8px; font-size:14px; }
        .aid-photo { display:inline-flex; flex-direction:column; gap:2px; text-decoration:none; width:96px; font-size:11px; }
        .aid-photo img { width:96px; height:72px; }
        .aid-doc { margin-top:8px; display:flex; flex-direction:column; gap:2px; }
        .aid-doc-img { max-width:100%; max-height:180px; border-radius:8px; border:1px solid var(--border); }
        .done-card { text-align:center; }
        .done-card .tick { width:44px; height:44px; border-radius:50%; background:var(--ok); color:#fff; font-size:26px; line-height:44px; margin:0 auto 8px; }
    </style>
</head>
<body>
<div x-data="rentalsPortal()" x-init="init()">
    {{-- §22 — the agency's logo on every page (its name when it has no logo; "My Rentals" until the agency is known). --}}
    <header class="top" data-portal-brand>
        <template x-if="branding && branding.logo_url">
            <img class="logo" :src="branding.logo_url" :alt="branding.name || 'Agency logo'" data-portal-logo x-on:error="branding.logo_url = null">
        </template>
        <template x-if="!(branding && branding.logo_url)">
            <h1 data-portal-name x-text="(branding && branding.name) || 'My Rentals'"></h1>
        </template>
        <button x-show="session.authenticated" @click="logout()">Log out</button>
    </header>

    <div class="wrap">
        <template x-if="loading"><p class="muted">Loading…</p></template>

        {{-- ── LOGIN ───────────────────────────────────────────────── --}}
        <template x-if="!loading && !session.authenticated">
            <div class="card">
                <h2>Sign in</h2>
                <template x-if="login.step === 'email'">
                    <div>
                        <label>Email address</label>
                        <input type="email" x-model="login.email" placeholder="you@example.com">
                        <button class="btn btn-primary" :disabled="busy.login" @click="lookup()" x-text="busy.login ? 'Please wait…' : 'Continue'"></button>
                    </div>
                </template>
                <template x-if="login.step === 'password'">
                    <div>
                        <label>Password</label>
                        <input type="password" x-model="login.password">
                        <button class="btn btn-primary" :disabled="busy.login" @click="passwordLogin()" x-text="busy.login ? 'Signing in…' : 'Sign in'"></button>
                        <a class="link" @click.prevent="sendOtp('recovery')" href="#">Forgot password?</a>
                    </div>
                </template>
                <template x-if="login.step === 'otp-sent'">
                    <div>
                        <p class="muted">We sent a 6-digit code to <strong x-text="login.email"></strong>.</p>
                        <label>Code</label>
                        <input type="text" inputmode="numeric" maxlength="6" x-model="login.code">
                        <button class="btn btn-primary" :disabled="busy.login" @click="verifyOtp()" x-text="busy.login ? 'Checking…' : 'Verify'"></button>
                    </div>
                </template>
                <template x-if="login.step === 'set-password'">
                    <div>
                        <p class="muted">Set a password for next time.</p>
                        <label>New password</label>
                        <input type="password" x-model="login.newPassword">
                        <label>Confirm password</label>
                        <input type="password" x-model="login.newPasswordConfirm">
                        <button class="btn btn-primary" :disabled="busy.login" @click="setPassword()" x-text="busy.login ? 'Saving…' : 'Save & continue'"></button>
                    </div>
                </template>
                <p class="error" x-show="login.error" x-text="login.error"></p>
            </div>
        </template>

        {{-- ── AUTHENTICATED ───────────────────────────────────────── --}}
        <template x-if="!loading && session.authenticated">
            <div>
                <template x-if="roles.length > 1">
                    <div class="tabs">
                        <button :class="{active: activeRole==='tenant'}" @click="setRole('tenant')" x-show="roles.includes('tenant')">My Tenancy</button>
                        <button :class="{active: activeRole==='landlord'}" @click="setRole('landlord')" x-show="roles.includes('landlord')">My Properties</button>
                    </div>
                </template>

                {{-- TENANT --}}
                <template x-if="activeRole === 'tenant'">
                    <div>
                        <div class="tabs">
                            <button :class="{active: tenantTab==='home'}" @click="tenantTab='home'; loadOverview()">Home</button>
                            <button :class="{active: tenantTab==='lease'}" @click="tenantTab='lease'; loadTenantLeases()">Lease</button>
                            <button :class="{active: tenantTab==='faults'}" @click="tenantTab='faults'; loadFaultReports()">Faults</button>
                            <button :class="{active: tenantTab==='jobs'}" @click="tenantTab='jobs'; loadWorkOrders()">Jobs</button>
                            <button :class="{active: tenantTab==='documents'}" @click="tenantTab='documents'; loadDocuments()">Documents</button>
                        </div>

                        <template x-if="tenantTab === 'home'">
                            @include('rentals.portal._home')
                        </template>

                        <template x-if="tenantTab === 'lease'">
                            <div>
                                {{-- §22 — "View details" opens right under the lease it belongs to (inline), and closes again. --}}
                                <template x-for="lease in tenantLeases" :key="lease.id">
                                    <div class="card" data-lease-card>
                                        <h2 x-text="lease.property_address || ('Lease #' + lease.id)"></h2>
                                        <span class="badge" x-text="lease.status"></span>
                                        <a class="link" style="display:block;margin-top:8px" data-lease-toggle @click.prevent="toggleLeaseDetail(lease.id)" href="#" x-text="openLeaseId === lease.id ? 'Hide details ↑' : 'View details ↓'"></a>
                                        <p class="muted" style="margin:8px 0 0;" x-show="openLeaseId === lease.id && !leaseDetails[lease.id] && !leaseDetailError">Loading…</p>
                                        <p class="error" x-show="openLeaseId === lease.id && leaseDetailError" x-text="leaseDetailError"></p>
                                        <template x-if="openLeaseId === lease.id && leaseDetails[lease.id]">
                                            <div data-lease-detail style="margin-top:10px; padding-top:10px; border-top:1px solid var(--border);">
                                                <div class="row"><span class="muted">Rent</span><strong x-text="'R ' + (leaseDetails[lease.id].rent_amount ?? 0).toLocaleString()"></strong></div>
                                                <div class="row"><span class="muted">Deposit</span><strong x-text="'R ' + (leaseDetails[lease.id].deposit_amount ?? 0).toLocaleString()"></strong></div>
                                                <div class="row"><span class="muted">Start</span><strong x-text="fmtDay(leaseDetails[lease.id].start_date)"></strong></div>
                                                <div class="row"><span class="muted">End</span><strong x-text="leaseDetails[lease.id].is_month_to_month ? 'Month-to-month' : fmtDay(leaseDetails[lease.id].end_date)"></strong></div>
                                                <div class="row"><span class="muted">Landlord</span><strong x-text="(leaseDetails[lease.id].landlord_names || []).join(', ') || '—'"></strong></div>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                <div class="card" x-show="!tenantLeases.length"><p class="muted" style="margin:0;">No leases yet.</p></div>
                            </div>
                        </template>

                        <template x-if="tenantTab === 'faults'">
                            <div>
                                <button class="btn btn-primary" x-show="!faultWizard.open" @click="startFaultReport()">Report a fault</button>

                                <template x-if="faultWizard.open">
                                    <div class="card" data-fault-wizard>
                                        {{-- Done: a clear success state, nothing left to press twice. --}}
                                        <template x-if="faultWizard.step === 'done'">
                                            <div class="done-card" data-fault-done>
                                                <div class="tick">&#10003;</div>
                                                <h2 style="margin-bottom:4px;" x-text="faultWizard.doneTitle"></h2>
                                                <p class="muted" style="margin:0 0 4px;" x-text="faultWizard.doneText"></p>
                                                <button class="btn btn-primary" @click="closeFaultWizard()">Done</button>
                                            </div>
                                        </template>

                                        <template x-if="faultWizard.step !== 'done'">
                                            <div>
                                                <label style="margin-top:0;">What's the problem?</label>
                                                <select x-model="faultWizard.faultTypeId" :disabled="faultWizard.sending" @change="selectFaultType()">
                                                    <option value="">Choose…</option>
                                                    <template x-for="ft in (faultTypesByProperty[faultWizard.property] || [])" :key="ft.id">
                                                        <option :value="ft.id" x-text="ft.name"></option>
                                                    </template>
                                                </select>

                                                {{-- Before you report: the steps, photos and documents for the chosen fault type. --}}
                                                <template x-if="faultWizard.faultTypeId && faultWizard.step === 'aid'">
                                                    <div style="margin-top:12px;" data-fault-aid-step>
                                                        <h2>Try this first</h2>
                                                        @include('rentals.portal._fault-aid', ['w' => 'faultWizard'])
                                                        <button class="btn btn-ok" :disabled="faultWizard.sending" @click="submitFault('first_aid_resolved')" x-text="faultWizard.sending ? 'Sending…' : 'That fixed it'"></button>
                                                        <button class="btn btn-outline" :disabled="faultWizard.sending" @click="faultWizard.step = 'form'">Still a problem</button>
                                                    </div>
                                                </template>

                                                <template x-if="faultWizard.faultTypeId && faultWizard.step === 'form'">
                                                    <div data-fault-form>
                                                        <label>Title</label>
                                                        <input type="text" x-model="faultWizard.title" :disabled="faultWizard.sending">
                                                        <label>Describe the problem</label>
                                                        <textarea rows="3" x-model="faultWizard.description" :disabled="faultWizard.sending"></textarea>
                                                        <label>Photos</label>
                                                        @include('rentals.portal._photo-picker', ['bind' => 'faultWizard'])
                                                        <button class="btn btn-danger" data-fault-submit :disabled="faultWizard.sending || faultWizard.photoBusy" @click="submitFault('still_a_problem')"
                                                                x-text="faultWizard.sending ? ('Sending…' + (faultWizard.progress ? ' ' + faultWizard.progress + '%' : '')) : (faultWizard.photoBusy ? 'Preparing photos…' : 'Submit report')"></button>
                                                        <div class="progress" x-show="faultWizard.sending"><span :style="'width:' + (faultWizard.progress || 5) + '%'"></span></div>
                                                    </div>
                                                </template>
                                                <p class="error" x-show="faultWizard.error" x-text="faultWizard.error"></p>
                                                <button class="btn btn-outline" :disabled="faultWizard.sending" @click="closeFaultWizard()">Cancel</button>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                <div class="card">
                                    <h2>My faults</h2>
                                    <template x-for="f in faultReports" :key="f.id">
                                        <div class="list-item row">
                                            <span x-text="f.title"></span>
                                            <span class="badge" x-text="f.status"></span>
                                            {{-- BUILD 3 — §17.3.5: the linked work order's plain stage. --}}
                                            <span class="muted" x-show="f.work_order_stage" x-text="f.work_order_stage ? 'Repair: ' + f.work_order_stage.stage_label + (f.work_order_stage.who_label ? ' · ' + f.work_order_stage.who_label + (f.work_order_stage.contractor_name ? ' (' + f.work_order_stage.contractor_name + ')' : '') : '') + (f.work_order_stage.appointment_at ? ' · appointment ' + new Date(f.work_order_stage.appointment_at).toLocaleString([], {dateStyle:'medium', timeStyle:'short'}) : '') : ''"></span>
                                        </div>
                                    </template>
                                    <p class="muted" x-show="!faultReports.length">No faults reported yet.</p>
                                </div>
                            </div>
                        </template>

                        {{-- BUILD 3 BEGIN — §17.3.5 / §17.10.4: the tenant's Jobs are WORK ORDERS (job cards are internal and have no portal endpoints): the plain stage, who is doing it, the completion rounds, the photos the agency allows — never a price — and the "Is this finished?" question. --}}
                        <template x-if="tenantTab === 'jobs'">
                            <div>
                                <div class="card" x-show="!workOrders.length"><p class="muted">No maintenance jobs yet.</p></div>
                                <template x-for="w in workOrders" :key="w.id">
                                    <div class="card" data-work-order>
                                        <div class="row"><h2 x-text="w.title"></h2><span class="badge" x-text="w.stage_label"></span></div>
                                        <p class="muted" x-show="w.property_address" x-text="w.property_address"></p>
                                        <p class="muted" x-text="w.who_label + (w.contractor_name ? ' — ' + w.contractor_name : '')"></p>
                                        <p class="muted" x-show="w.completed_at" x-text="w.completed_at ? 'Completed ' + w.completed_at.substring(0,10) : ''"></p>
                                        <p x-show="!w.completed_at && w.appointment_at"><strong x-text="w.appointment_at ? 'Appointment: ' + new Date(w.appointment_at).toLocaleString([], {dateStyle:'full', timeStyle:'short'}) : ''"></strong><span class="muted" x-show="w.appointment_note" x-text="w.appointment_note ? ' — ' + w.appointment_note : ''"></span></p>
                                        <div class="photo-grid" x-show="w.photos.length">
                                            <template x-for="ph in w.photos" :key="ph.id">
                                                <a :href="ph.url" target="_blank" rel="noopener"><img :src="ph.url" :alt="ph.photo_type + ' photo'" loading="lazy"></a>
                                            </template>
                                        </div>

                                        {{-- §17.10.4 — "Is this finished?" — only while a completion round waits on this tenant. --}}
                                        <template x-if="w.awaiting_answer">
                                            <div data-finished-block style="margin-top:12px; padding-top:12px; border-top:1px solid #e3e8ef;">
                                                <h2>Is this finished?</h2>
                                                <p class="muted" x-text="(w.awaiting_answer.reported_by || 'The crew') + ' says this work is complete. Please check it' + (w.awaiting_answer.answer_due ? ' — if we do not hear from you by ' + w.awaiting_answer.answer_due.substring(0,10) + ' we will treat it as accepted.' : '.')"></p>
                                                <button class="btn btn-ok" :disabled="answerBusy" @click="answerCompletion(w, true)" x-text="answerBusy ? 'Sending…' : 'All done, thanks'"></button>
                                                <template x-if="!answerForm || answerForm.workOrderId !== w.id">
                                                    <button class="btn btn-outline" @click="openNotComplete(w)">Not complete / still wrong</button>
                                                </template>
                                                <template x-if="answerForm && answerForm.workOrderId === w.id">
                                                    <div>
                                                        <label>What is still wrong?</label>
                                                        <textarea rows="3" x-model="answerForm.note" placeholder="For example: the tap is fixed but it still drips."></textarea>
                                                        <label>Photos (optional)</label>
                                                        @include('rentals.portal._photo-picker', ['bind' => 'answerForm'])
                                                        <button class="btn btn-danger" :disabled="answerBusy || answerForm.photoBusy" @click="answerCompletion(w, false)" x-text="answerBusy ? 'Sending…' : 'Send — it is not complete'"></button>
                                                    </div>
                                                </template>
                                                <p class="error" x-show="answerError" x-text="answerError"></p>
                                            </div>
                                        </template>

                                        <template x-for="r in w.rounds" :key="r.id">
                                            <div style="margin-top:10px; padding-top:10px; border-top:1px solid #eef1f6;">
                                                <div class="row"><span class="muted" x-text="'Check ' + r.round_no + ' — reported done ' + (r.reported_at ? r.reported_at.substring(0,10) : '')"></span><span class="badge" x-text="r.outcome_label"></span></div>
                                                <p class="muted" x-show="r.response_note" x-text="r.response_note ? 'You said: ' + r.response_note : ''"></p>
                                                <div class="photo-grid" x-show="r.photos.length">
                                                    <template x-for="ph in r.photos" :key="ph.id">
                                                        <a :href="ph.url" target="_blank" rel="noopener"><img :src="ph.url" alt="Photo you sent" loading="lazy"></a>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                        {{-- BUILD 3 END --}}

                        <template x-if="tenantTab === 'documents'">
                            @include('rentals.portal._documents')
                        </template>
                    </div>
                </template>

                {{-- LANDLORD --}}
                <template x-if="activeRole === 'landlord'">
                    <div>
                        <div class="tabs">
                            <button :class="{active: landlordTab==='home'}" @click="landlordTab='home'; loadOverview()">Home</button>
                            <button :class="{active: landlordTab==='decisions'}" @click="landlordTab='decisions'; loadDecisions()">Decisions</button>
                            <button :class="{active: landlordTab==='properties'}" @click="landlordTab='properties'; loadLandlordProperties()">Properties</button>
                            <button :class="{active: landlordTab==='faults'}" @click="landlordTab='faults'; loadLandlordFaults()">Faults</button>
                            <button :class="{active: landlordTab==='jobs'}" @click="landlordTab='jobs'; loadWorkOrders()">Jobs</button>
                            <button :class="{active: landlordTab==='documents'}" @click="landlordTab='documents'; loadDocuments()">Documents</button>
                        </div>

                        <template x-if="landlordTab === 'home'">
                            @include('rentals.portal._home')
                        </template>

                        <template x-if="landlordTab === 'documents'">
                            @include('rentals.portal._documents')
                        </template>

                        <template x-if="landlordTab === 'decisions'">
                            <div>
                                <div class="card" x-show="!decisions.fault_reports.length && !decisions.work_orders.length && !(decisions.variations || []).length">
                                    <p class="muted">Nothing needs your decision right now.</p>
                                </div>
                                {{-- Fault flow F3/F4: the agent's version of the fault, then Approve or Decline (reason required) and,
                                     if approving, who handles the repair. The decision, once made, shows read-only. --}}
                                <template x-for="f in decisions.fault_reports" :key="'f'+f.id">
                                    <div class="card">
                                        <h2 x-text="f.title"></h2>
                                        <p class="muted">Your agent needs your decision on this repair.</p>
                                        <button class="btn btn-primary" @click="openFault(f.id)">Review and decide</button>
                                    </div>
                                </template>
                                <template x-if="faultDetail">
                                    <div class="card" data-fault-detail>
                                        <h2 x-text="faultDetail.title"></h2>
                                        <p class="muted" x-text="(faultDetail.property || '') + ' · ' + faultDetail.status_label"></p>
                                        <p x-show="faultDetail.description" x-text="faultDetail.description"></p>
                                        <div class="photo-grid" x-show="faultDetail.photos.length">
                                            <template x-for="ph in faultDetail.photos" :key="ph.id">
                                                <a :href="ph.url" target="_blank" rel="noopener"><img :src="ph.url" alt="Photo of the fault" loading="lazy"></a>
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
                                                            <option value="list" :hidden="!faultDetail.contractors.length" :disabled="!faultDetail.contractors.length">A contractor from my agent's list</option>
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
                                                <button class="btn btn-ok" :disabled="faultForm.busy || !faultForm.decision" @click="submitFaultDecision()">Send my decision</button>
                                            </div>
                                        </template>
                                        <button class="btn btn-outline" @click="faultDetail = null">Close</button>
                                    </div>
                                </template>
                                <template x-for="w in decisions.work_orders" :key="'w'+w.id">
                                    <div class="card">
                                        <h2 x-text="w.title"></h2>
                                        <p class="muted">Quote: <strong x-text="'R ' + (w.selected_quote_amount ?? 0)"></strong></p>
                                        <button class="btn btn-ok" :disabled="busy['wo' + w.id]" @click="decideWorkOrder(w.id, 'approve')" x-text="busy['wo' + w.id] ? 'Sending…' : 'Approve'"></button>
                                        <button class="btn btn-danger" :disabled="busy['wo' + w.id]" @click="decideWorkOrder(w.id, 'decline')">Decline</button>
                                    </div>
                                </template>
                                {{-- BUILD 2 BEGIN — extra work beyond the owner's agreed terms (.ai/specs/rental-work-orders.md §17.7.4): what was approved, the extra work (selling only), the crew's photos and note, the new total, Approve / Decline. --}}
                                <template x-for="v in (decisions.variations || [])" :key="'v'+v.id">
                                    <div class="card" data-variation-card>
                                        <h2 x-text="'Extra work needs your approval: ' + (v.title || '')"></h2>
                                        <p class="muted">Approved so far: <strong x-text="'R ' + Number(v.baseline_amount).toFixed(2)"></strong></p>
                                        <template x-for="(l, li) in v.lines" :key="li">
                                            <div class="list-item">
                                                <div class="row">
                                                    <span x-text="l.description + (l.quantity ? ' x ' + l.quantity : '')"></span>
                                                    <span x-text="l.total != null ? 'R ' + Number(l.total).toFixed(2) : ''"></span>
                                                </div>
                                                <p class="muted" x-show="l.note" x-text="l.note"></p>
                                            </div>
                                        </template>
                                        <div class="photo-grid" x-show="v.photos.length">
                                            <template x-for="ph in v.photos" :key="ph.id">
                                                <a :href="ph.url" target="_blank" rel="noopener"><img :src="ph.url" alt="Photo of the extra work" loading="lazy"></a>
                                            </template>
                                        </div>
                                        <p style="margin-top:10px;">Extra work: <strong x-text="'R ' + Number(v.extra_amount).toFixed(2)"></strong> &middot; New total: <strong x-text="'R ' + Number(v.new_total).toFixed(2)"></strong></p>
                                        <p class="muted" x-show="v.term_text" x-text="v.term_text"></p>
                                        <button class="btn btn-ok" :disabled="busy['var' + v.id]" @click="decideVariation(v, 'approve')" x-text="busy['var' + v.id] ? 'Sending…' : 'Approve'"></button>
                                        <button class="btn btn-danger" :disabled="busy['var' + v.id]" @click="decideVariation(v, 'decline')">Decline</button>
                                    </div>
                                </template>
                                {{-- BUILD 2 END --}}
                            </div>
                        </template>

                        <template x-if="landlordTab === 'properties'">
                            <div>
                                <template x-for="p in landlordProperties" :key="p.id">
                                    <div class="card">
                                        <h2 x-text="p.address"></h2>
                                        <div class="row">
                                            <a class="link" href="#" @click.prevent="loadPropertyDetail(p.id)">View activity →</a>
                                            <button class="btn btn-danger" style="width:auto;margin-top:0;padding:8px 12px;" @click="startLandlordFaultReport(p.id)">Request work</button>
                                        </div>
                                    </div>
                                </template>
                                <template x-if="propertyDetail">
                                    <div class="card">
                                        <h2>Occupancy history</h2>
                                        <template x-for="o in propertyDetail.occupancy_history" :key="o.id">
                                            <div class="list-item row">
                                                <span x-text="o.tenant_names"></span>
                                                <span class="badge" x-text="o.status"></span>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>

                        {{-- BUILD 3 BEGIN — §17.3.5: the landlord's Jobs are WORK ORDERS too: the plain stage, who is doing it, the owner-facing amount only (never cost or margin), the completion rounds and the permitted photos. --}}
                        <template x-if="landlordTab === 'jobs'">
                            <div>
                                <div class="card" x-show="!workOrders.length"><p class="muted">No maintenance jobs yet.</p></div>
                                <template x-for="w in workOrders" :key="w.id">
                                    <div class="card" data-work-order>
                                        <div class="row"><h2 x-text="w.title"></h2><span class="badge" x-text="w.stage_label"></span></div>
                                        <p class="muted" x-show="w.property_address" x-text="w.property_address"></p>
                                        <p class="muted" x-text="w.who_label + (w.contractor_name ? ' — ' + w.contractor_name : '')"></p>
                                        <p class="muted" x-show="w.completed_at" x-text="w.completed_at ? 'Completed ' + w.completed_at.substring(0,10) : ''"></p>
                                        <p x-show="!w.completed_at && w.appointment_at"><strong x-text="w.appointment_at ? 'Appointment: ' + new Date(w.appointment_at).toLocaleString([], {dateStyle:'full', timeStyle:'short'}) : ''"></strong><span class="muted" x-show="w.appointment_note" x-text="w.appointment_note ? ' — ' + w.appointment_note : ''"></span></p>
                                        <p class="muted" x-show="w.contractor_phone" x-text="w.contractor_phone ? 'Contractor phone: ' + w.contractor_phone : ''"></p>
                                        <p class="muted" x-show="w.owner_facing_amount !== null && w.owner_facing_amount !== undefined" x-text="'Amount: R ' + w.owner_facing_amount"></p>
                                        {{-- W6 (8 Oct 2026): the owner may also set the appointment and report progress; the tenant is told. The agency's own team reports through its job card. --}}
                                        <template x-if="w.stage !== 'completed' && w.stage !== 'cancelled'">
                                            <div data-owner-appointment style="margin-top:10px; padding-top:10px; border-top:1px solid #eef1f6;">
                                                <label>Appointment for the repair</label>
                                                <input type="datetime-local" x-model="apptDraft[w.id + '_at']">
                                                <input type="text" maxlength="500" placeholder="Note for the tenant (optional)" x-model="apptDraft[w.id + '_note']">
                                                <button class="btn btn-outline" @click="setAppointment(w)" x-text="w.appointment_at ? 'Change the appointment' : 'Set the appointment'"></button>
                                                <template x-if="w.who !== 'our_team'">
                                                    <div>
                                                        <button class="btn btn-outline" x-show="w.stage !== 'in_progress' && w.stage !== 'check_requested'" @click="reportProgress(w, 'started')">The work has started</button>
                                                        <button class="btn btn-ok" x-show="w.stage !== 'check_requested'" @click="reportProgress(w, 'finished')">The work is finished</button>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                        <div class="photo-grid" x-show="w.photos.length">
                                            <template x-for="ph in w.photos" :key="ph.id">
                                                <a :href="ph.url" target="_blank" rel="noopener"><img :src="ph.url" :alt="ph.photo_type + ' photo'" loading="lazy"></a>
                                            </template>
                                        </div>
                                        <template x-for="r in w.rounds" :key="r.id">
                                            <div style="margin-top:10px; padding-top:10px; border-top:1px solid #eef1f6;">
                                                <div class="row"><span class="muted" x-text="'Tenant check ' + r.round_no + ' — reported done ' + (r.reported_at ? r.reported_at.substring(0,10) : '')"></span><span class="badge" x-text="r.outcome_label"></span></div>
                                                <p class="muted" x-show="r.response_note" x-text="r.response_note ? 'The tenant said: ' + r.response_note : ''"></p>
                                                <div class="photo-grid" x-show="r.photos.length">
                                                    <template x-for="ph in r.photos" :key="ph.id">
                                                        <a :href="ph.url" target="_blank" rel="noopener"><img :src="ph.url" alt="Photo from the tenant" loading="lazy"></a>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                        {{-- BUILD 3 END --}}

                        {{-- §15 (AT-447, portal frontend follow-up) — "Request work / report
                             a problem": same shape as the tenant fault-report wizard above
                             (fault type → first-aid context → title/description/photos),
                             reached from either a property card's own button or here. --}}
                        <template x-if="landlordTab === 'faults'">
                            <div>
                                <template x-if="landlordFaultWizard.open">
                                    <div class="card" data-landlord-fault-wizard>
                                        <template x-if="landlordFaultWizard.step === 'done'">
                                            <div class="done-card" data-fault-done>
                                                <div class="tick">&#10003;</div>
                                                <h2 style="margin-bottom:4px;">Request sent</h2>
                                                <p class="muted" style="margin:0 0 4px;">Your agent has been notified.</p>
                                                <button class="btn btn-primary" @click="closeLandlordFaultWizard()">Done</button>
                                            </div>
                                        </template>
                                        <template x-if="landlordFaultWizard.step !== 'done'">
                                            <div>
                                                <h2>Request work</h2>
                                                <label>What's the problem?</label>
                                                <select x-model="landlordFaultWizard.faultTypeId" :disabled="landlordFaultWizard.sending" @change="selectLandlordFaultType()">
                                                    <option value="">Choose… (or skip and describe it below)</option>
                                                    <template x-for="ft in (landlordFaultTypesByProperty[landlordFaultWizard.property] || [])" :key="ft.id">
                                                        <option :value="ft.id" x-text="ft.name + (ft.category ? ' (' + ft.category + ')' : '')"></option>
                                                    </template>
                                                </select>
                                                <template x-if="landlordFaultWizard.faultTypeId && landlordFaultWizard.ftype && landlordFaultWizard.showAid">
                                                    <div style="margin-top:12px;" data-fault-aid-step>
                                                        <h2>Before you request work</h2>
                                                        @include('rentals.portal._fault-aid', ['w' => 'landlordFaultWizard'])
                                                    </div>
                                                </template>
                                                <label>Title</label>
                                                <input type="text" x-model="landlordFaultWizard.title" :disabled="landlordFaultWizard.sending">
                                                <label>Describe the problem</label>
                                                <textarea rows="3" x-model="landlordFaultWizard.description" :disabled="landlordFaultWizard.sending"></textarea>
                                                <label>Photos</label>
                                                @include('rentals.portal._photo-picker', ['bind' => 'landlordFaultWizard'])
                                                <button class="btn btn-danger" data-fault-submit :disabled="landlordFaultWizard.sending || landlordFaultWizard.photoBusy" @click="submitLandlordFault()"
                                                        x-text="landlordFaultWizard.sending ? ('Sending…' + (landlordFaultWizard.progress ? ' ' + landlordFaultWizard.progress + '%' : '')) : (landlordFaultWizard.photoBusy ? 'Preparing photos…' : 'Submit request')"></button>
                                                <div class="progress" x-show="landlordFaultWizard.sending"><span :style="'width:' + (landlordFaultWizard.progress || 5) + '%'"></span></div>
                                                <button class="btn btn-outline" :disabled="landlordFaultWizard.sending" @click="closeLandlordFaultWizard()">Cancel</button>
                                                <p class="error" x-show="landlordFaultWizard.error" x-text="landlordFaultWizard.error"></p>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                <div class="card" x-show="!landlordFaultWizard.open">
                                    <p class="muted" x-show="!landlordProperties.length">Open the Properties tab to request work on a specific property.</p>
                                    <template x-if="landlordProperties.length">
                                        <div>
                                            <label>Property</label>
                                            <select x-model="landlordFaultWizard.property">
                                                <template x-for="p in landlordProperties" :key="p.id">
                                                    <option :value="p.id" x-text="p.address"></option>
                                                </template>
                                            </select>
                                            <button class="btn btn-primary" @click="startLandlordFaultReport(landlordFaultWizard.property || landlordProperties[0].id)">Request work</button>
                                        </div>
                                    </template>
                                </div>

                                <div class="card">
                                    <h2>My requests</h2>
                                    <template x-for="f in landlordFaults" :key="f.id">
                                        <div class="list-item row" style="cursor:pointer" @click="landlordTab='decisions'; openFault(f.id)">
                                            <span x-text="f.title"></span>
                                            <span class="badge" x-text="f.status_label || f.status"></span>
                                        </div>
                                    </template>
                                    <p class="muted" x-show="!landlordFaults.length">No requests yet.</p>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </template>
    </div>
</div>

<script>
function getCookie(name) {
    const m = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : null;
}

async function ensureCsrfCookie() {
    if (!getCookie('XSRF-TOKEN')) {
        await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
    }
}

// §22 — `options.key` is the form's submission key: the server does the work once per key and replays its answer on a second press.
async function portalFetch(url, options = {}) {
    await ensureCsrfCookie();
    const headers = Object.assign({
        'Accept': 'application/json',
        'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'),
    }, options.headers || {});
    if (options.key) headers['X-Submission-Key'] = options.key;
    if (!(options.body instanceof FormData) && options.body) {
        headers['Content-Type'] = 'application/json';
    }
    const fetchOptions = Object.assign({ credentials: 'same-origin' }, options, { headers });
    delete fetchOptions.key;
    const res = await fetch(url, fetchOptions);
    let data = null;
    try { data = await res.json(); } catch (e) { /* no body */ }
    return { ok: res.ok, status: res.status, data };
}

// §22 — a form with photos goes by XMLHttpRequest so the person SEES the upload progress (fetch cannot report it).
async function portalUpload(url, form, key, onProgress) {
    await ensureCsrfCookie();
    return new Promise((resolve) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.withCredentials = true;
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-XSRF-TOKEN', getCookie('XSRF-TOKEN'));
        if (key) xhr.setRequestHeader('X-Submission-Key', key);
        if (xhr.upload && onProgress) {
            xhr.upload.onprogress = (e) => { if (e.lengthComputable && e.total > 0) onProgress(Math.min(99, Math.round(e.loaded / e.total * 100))); };
        }
        xhr.onload = () => {
            let data = null;
            try { data = JSON.parse(xhr.responseText); } catch (e) { /* no body */ }
            resolve({ ok: xhr.status >= 200 && xhr.status < 300, status: xhr.status, data });
        };
        xhr.onerror = () => resolve({ ok: false, status: 0, data: null });
        xhr.ontimeout = () => resolve({ ok: false, status: 0, data: null });
        xhr.timeout = 180000;
        xhr.send(form);
    });
}

function rentalsPortal() {
    return {
        loading: true,
        // §22 — the agency's logo / name. Known before sign-in only when the personal link carried the email.
        branding: @json($branding ?? null),
        busy: {},
        session: { authenticated: false },
        roles: [],
        activeRole: null,
        login: { step: 'email', email: '', password: '', code: '', newPassword: '', newPasswordConfirm: '', error: null, mustSetPassword: false },
        tenantTab: 'home',
        landlordTab: 'home',
        overview: { homes: [], decisions_waiting: 0, loaded: false, error: null },
        faqOpen: {},
        tenantLeases: [], openLeaseId: null, leaseDetails: {}, leaseDetailError: null,
        faultReports: [], documents: [],
        docs: { rows: [], meta: null, loaded: false, error: null, q: '', type: '', from: '', to: '', sort: 'date', dir: 'desc', page: 1 },
        photoSeq: 0,
        photoLimits: { max_photos: 6, max_photo_mb: 8 },
        faultWizard: { open: false, step: 'pick', property: null, faultTypeId: '', ftype: null, title: '', description: '', photos: [], photoError: null, photoBusy: false, photoPending: 0, sending: false, progress: 0, error: null, key: null, doneTitle: '', doneText: '' },
        faultTypesByProperty: {},
        decisions: { fault_reports: [], work_orders: [], variations: [] },
        landlordProperties: [], propertyDetail: null,
        landlordFaults: [],
        faultDetail: null,
        faultForm: { decision: '', handled_by: '', contractor_name: '', contractor_phone: '', agency_service_provider_id: '', note: '', error: null, busy: false },
        apptDraft: {},
        // BUILD 3 — the portal's Jobs are work orders (§17.3.5); the "is this finished?" answer form (§17.10.4).
        workOrders: [], answerForm: null, answerBusy: false, answerError: null, answerKeys: {},
        landlordFaultWizard: { open: false, step: 'form', property: null, faultTypeId: '', ftype: null, showAid: false, title: '', description: '', photos: [], photoError: null, photoBusy: false, photoPending: 0, sending: false, progress: 0, error: null, key: null },
        landlordFaultTypesByProperty: {},

        // §22 — one press does one thing: a named action cannot start again while it is running.
        async once(name, fn) {
            if (this.busy[name]) return;
            this.busy[name] = true;
            try { return await fn(); } finally { this.busy[name] = false; }
        },
        uuid() {
            if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
            return 'k' + Date.now().toString(36) + Math.random().toString(36).slice(2, 14);
        },

        async init() {
            // rental-portal-access.md §16 — a personal link (?email=…) arrives with the email already filled in.
            // Only pre-fills the field: nothing is looked up or sent until the person presses Continue.
            const linked = new URLSearchParams(window.location.search).get('email');
            if (linked && linked.length <= 255 && /^[^\s@]+@[^\s@]+$/.test(linked)) this.login.email = linked.trim();
            const me = await portalFetch('/api/v1/client/me');
            if (me.ok) {
                this.session.authenticated = true;
                await this.detectRoles();
            }
            this.loading = false;
        },

        async loadBranding() {
            const r = await portalFetch('/api/v1/client/rentals/branding');
            if (r.ok && r.data && r.data.branding) this.branding = r.data.branding;
        },

        async detectRoles() {
            this.loadBranding();
            const roles = [];
            const leases = await portalFetch('/api/v1/client/rentals/leases');
            if (leases.ok && leases.data.leases && leases.data.leases.length) roles.push('tenant');
            const props = await portalFetch('/api/v1/client/rentals/landlord/properties');
            if (props.ok && props.data.properties && props.data.properties.length) roles.push('landlord');
            this.roles = roles;
            this.activeRole = roles[0] || null;
            if (this.activeRole === 'tenant') { this.tenantLeases = leases.data.leases; this.loadOverview(); }
            if (this.activeRole === 'landlord') { this.landlordProperties = props.data.properties; this.loadOverview(); }
        },

        setRole(role) {
            this.activeRole = role;
            if (role === 'tenant') { this.tenantTab = 'home'; this.tenantLeases.length || this.loadTenantLeases(); }
            if (role === 'landlord') this.landlordTab = 'home';
            this.loadOverview();
        },

        async lookup() {
            return this.once('login', async () => {
                this.login.error = null;
                const r = await portalFetch('/api/v1/client-auth/lookup', { method: 'POST', body: JSON.stringify({ email: this.login.email }) });
                if (!r.ok || !r.data.exists) { this.login.error = r.data?.message || 'Not found.'; return; }
                if (r.data.requires_password) {
                    this.login.step = 'password';
                } else {
                    await this.sendOtp('activation');
                }
            });
        },

        async sendOtp(purpose) {
            return this.once('otp', async () => {
                this.login.error = null;
                await portalFetch('/api/v1/client-auth/otp/send', { method: 'POST', body: JSON.stringify({ email: this.login.email, purpose }) });
                this.login.step = 'otp-sent';
            });
        },

        async verifyOtp() {
            return this.once('login', async () => {
                this.login.error = null;
                const r = await portalFetch('/api/v1/client-auth/otp/verify', { method: 'POST', body: JSON.stringify({ email: this.login.email, code: this.login.code }) });
                if (!r.ok) { this.login.error = r.data?.message || 'Invalid code.'; return; }
                this.login.activationToken = r.data.activation_token;
                this.login.step = 'set-password';
            });
        },

        async setPassword() {
            return this.once('login', async () => {
                this.login.error = null;
                if (this.login.newPassword !== this.login.newPasswordConfirm) { this.login.error = 'Passwords do not match.'; return; }
                const r = await portalFetch('/api/v1/client-auth/password/set', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + this.login.activationToken },
                    body: JSON.stringify({ password: this.login.newPassword, password_confirmation: this.login.newPasswordConfirm }),
                });
                if (!r.ok) { this.login.error = r.data?.message || 'Could not set password.'; return; }
                this.session.authenticated = true;
                await this.detectRoles();
            });
        },

        async passwordLogin() {
            return this.once('login', async () => {
                this.login.error = null;
                const r = await portalFetch('/api/v1/client-auth/login', { method: 'POST', body: JSON.stringify({ email: this.login.email, password: this.login.password }) });
                if (!r.ok) { this.login.error = r.data?.message || 'Invalid credentials.'; return; }
                this.session.authenticated = true;
                await this.detectRoles();
            });
        },

        async logout() {
            await portalFetch('/api/v1/client-auth/logout', { method: 'POST' });
            this.session.authenticated = false;
            this.roles = [];
            window.location.reload();
        },

        async loadTenantLeases() {
            const r = await portalFetch('/api/v1/client/rentals/leases');
            if (r.ok) this.tenantLeases = r.data.leases;
        },
        // §22 — the lease's details open directly under that lease, and close again; each lease is fetched once.
        async toggleLeaseDetail(id) {
            if (this.openLeaseId === id) { this.openLeaseId = null; return; }
            this.openLeaseId = id;
            this.leaseDetailError = null;
            if (this.leaseDetails[id]) return;
            const r = await portalFetch('/api/v1/client/rentals/leases/' + id);
            if (r.ok) this.leaseDetails[id] = r.data.lease;
            else this.leaseDetailError = r.data?.message || 'Could not load the lease details.';
        },
        async loadFaultReports() {
            const r = await portalFetch('/api/v1/client/rentals/fault-reports');
            if (r.ok) this.faultReports = r.data.fault_reports;
        },
        // W6 (8 Oct 2026) - the owner books the repair appointment / reports progress on their work order.
        async setAppointment(w) {
            const at = this.apptDraft[w.id + '_at'];
            if (!at) { alert('Please choose a date and time.'); return; }
            const r = await portalFetch('/api/v1/client/rentals/landlord/work-orders/' + w.id + '/appointment', { method: 'POST', body: JSON.stringify({ appointment_at: at, note: this.apptDraft[w.id + '_note'] || '' }) });
            if (r.ok) { this.apptDraft[w.id + '_at'] = ''; await this.loadWorkOrders(); }
            else alert(r.data?.message || 'Could not set the appointment.');
        },
        async reportProgress(w, action) {
            const r = await portalFetch('/api/v1/client/rentals/landlord/work-orders/' + w.id + '/progress', { method: 'POST', body: JSON.stringify({ action }) });
            if (r.ok) await this.loadWorkOrders();
            else alert(r.data?.message || 'Could not record that.');
        },
        // BUILD 3 BEGIN — §17.3.5: one list, two endpoints: the tenant's work orders, or the landlord's (each carries its own `client` view).
        async loadWorkOrders() {
            if (this.activeRole === 'landlord') {
                const r = await portalFetch('/api/v1/client/rentals/landlord/work-orders');
                if (r.ok) this.workOrders = (r.data.work_orders || []).map(w => Object.assign({ photos: [], rounds: [] }, w.client || w));
            } else {
                const r = await portalFetch('/api/v1/client/rentals/work-orders');
                if (r.ok) this.workOrders = r.data.work_orders;
            }
        },
        openNotComplete(w) { this.answerError = null; this.answerForm = { workOrderId: w.id, note: '', photos: [], photoError: null, photoBusy: false, photoPending: 0 }; },
        // §17.10.4 — confirm ("All done") or say it is NOT complete (a note of at least 5 characters, up to the agency's photo limit).
        async answerCompletion(w, fixed) {
            if (this.answerBusy) return;
            this.answerError = null;
            const form = new FormData();
            form.append('fixed', fixed ? '1' : '0');
            const photos = (!fixed && this.answerForm && this.answerForm.workOrderId === w.id) ? this.answerForm.photos : [];
            if (!fixed) {
                const note = (this.answerForm && this.answerForm.workOrderId === w.id ? this.answerForm.note : '').trim();
                if (note.length < 5) { this.answerError = 'Please tell us what is still wrong (at least 5 characters).'; return; }
                form.append('note', note);
                photos.forEach(p => form.append('photos[]', p.file, p.file.name));
            }
            const slot = w.id + (fixed ? 'y' : 'n');
            if (!this.answerKeys[slot]) this.answerKeys[slot] = 'answer-' + this.uuid();
            this.answerBusy = true;
            const r = await portalFetch('/api/v1/client/rentals/work-orders/' + w.id + '/completion-response', { method: 'POST', body: form, key: this.answerKeys[slot] });
            this.answerBusy = false;
            if (!r.ok) { this.answerError = r.data?.message || 'Could not send your answer.'; return; }
            delete this.answerKeys[slot];
            photos.forEach(p => URL.revokeObjectURL(p.url));
            this.answerForm = null;
            await this.loadWorkOrders();
        },
        // BUILD 3 END
        // §20 — the Home panel for both audiences; always the signed-in person's OWN properties (server-side).
        async loadOverview() {
            const base = this.activeRole === 'landlord' ? '/api/v1/client/rentals/landlord' : '/api/v1/client/rentals';
            this.overview.error = null;
            const r = await portalFetch(base + '/overview');
            if (!r.ok) { this.overview.error = r.data?.message || 'Could not load your home page.'; this.overview.loaded = true; return; }
            this.overview.homes = r.data.homes;
            this.overview.decisions_waiting = r.data.decisions_waiting || 0;
            this.overview.loaded = true;
        },
        fmtDay(d) {
            if (!d) return '—';
            const x = new Date(String(d).substring(0, 10) + 'T00:00:00');
            return isNaN(x) ? d : x.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
        },
        // §22 — the Home FAQ: a question opens its answer underneath it.
        isFaqOpen(propertyId, key) { return !!this.faqOpen[propertyId + ':' + key]; },
        toggleFaq(propertyId, key) { const k = propertyId + ':' + key; this.faqOpen[k] = !this.faqOpen[k]; },
        // §19 — one Documents panel for both audiences; the list is always the signed-in person's OWN (server-side).
        async loadDocuments() {
            const base = this.activeRole === 'landlord' ? '/api/v1/client/rentals/landlord' : '/api/v1/client/rentals';
            const p = new URLSearchParams({ sort: this.docs.sort, dir: this.docs.dir, page: this.docs.page });
            ['q', 'type', 'from', 'to'].forEach(k => { if (this.docs[k]) p.set(k, this.docs[k]); });
            this.docs.error = null;
            const r = await portalFetch(base + '/documents?' + p.toString());
            if (!r.ok) { this.docs.error = r.data?.message || 'Could not load your documents.'; this.docs.loaded = true; return; }
            this.docs.rows = r.data.documents;
            this.docs.meta = r.data.meta;
            this.docs.page = r.data.meta.page;
            this.docs.loaded = true;
        },

        // ── §22 — photos: add (camera or gallery, each pick ADDS), shrink in the browser, preview, remove before sending ──
        photoCountLabel(w) {
            return w.photoBusy ? 'Preparing photos…' : (w.photos.length + ' of ' + this.photoLimits.max_photos + ' photos');
        },
        async addPhotos(w, ev) {
            const input = ev.target;
            const files = Array.from(input.files || []);
            input.value = ''; // so the same picture can be picked again after removing it
            w.photoError = null;
            const max = this.photoLimits.max_photos;
            const maxBytes = this.photoLimits.max_photo_mb * 1024 * 1024;
            for (const file of files) {
                if (w.photos.length + (w.photoPending || 0) >= max) { w.photoError = 'You can add up to ' + max + ' photos.'; break; }
                if (!file.type || file.type.indexOf('image/') !== 0) { w.photoError = 'Only photos can be added.'; continue; }
                const sig = file.name + '|' + file.size + '|' + file.lastModified;
                if (w.photos.some(p => p.sig === sig)) continue; // the same picture twice is one picture
                w.photoPending = (w.photoPending || 0) + 1;
                w.photoBusy = true;
                try {
                    const out = await this.compressPhoto(file);
                    if (out.size > maxBytes) { w.photoError = 'That photo is too large — the most is ' + this.photoLimits.max_photo_mb + ' MB.'; continue; }
                    this.photoSeq += 1;
                    w.photos.push({ id: this.photoSeq, sig: sig, file: out, name: out.name, url: URL.createObjectURL(out) });
                } finally {
                    w.photoPending -= 1;
                    w.photoBusy = w.photoPending > 0;
                }
            }
        },
        removePhoto(w, id) {
            const i = w.photos.findIndex(p => p.id === id);
            if (i >= 0) { URL.revokeObjectURL(w.photos[i].url); w.photos.splice(i, 1); }
            w.photoError = null;
        },
        // A phone photo is 3-8 MB; the report only needs it clear. Longest side 1600 px, JPEG 80% — and the original is kept when
        // shrinking would not make it smaller, or when the browser cannot read it.
        async compressPhoto(file) {
            const MAX_EDGE = 1600, QUALITY = 0.8, KEEP_UNDER = 1.2 * 1024 * 1024;
            try {
                if (file.type === 'image/gif' || file.type === 'image/svg+xml') return file;
                let bmp;
                if (window.createImageBitmap) {
                    try { bmp = await createImageBitmap(file, { imageOrientation: 'from-image' }); } catch (e) { bmp = await createImageBitmap(file); }
                } else {
                    return file;
                }
                const scale = Math.min(1, MAX_EDGE / Math.max(bmp.width, bmp.height));
                if (scale === 1 && file.size <= KEEP_UNDER) { if (bmp.close) bmp.close(); return file; }
                const canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(bmp.width * scale));
                canvas.height = Math.max(1, Math.round(bmp.height * scale));
                canvas.getContext('2d').drawImage(bmp, 0, 0, canvas.width, canvas.height);
                if (bmp.close) bmp.close();
                const blob = await new Promise((res) => canvas.toBlob(res, 'image/jpeg', QUALITY));
                if (!blob || blob.size >= file.size) return file;
                return new File([blob], (file.name || 'photo').replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: Date.now() });
            } catch (e) {
                return file;
            }
        },
        freeWizardPhotos(w) { (w.photos || []).forEach(p => URL.revokeObjectURL(p.url)); },

        // ── tenant: report a fault ──
        blankFaultWizard(propertyId) {
            return { open: !!propertyId, step: 'pick', property: propertyId || null, faultTypeId: '', ftype: null, lastTypeName: '', title: '', description: '', photos: [], photoError: null, photoBusy: false, photoPending: 0, sending: false, progress: 0, error: null, key: this.uuid(), doneTitle: '', doneText: '' };
        },
        async startFaultReport() {
            const propertyId = this.tenantLeases[0]?.property_id || null;
            this.faultWizard = this.blankFaultWizard(propertyId);
            if (!propertyId) { this.faultWizard.open = true; this.faultWizard.error = 'We could not find your property.'; return; }
            if (!this.faultTypesByProperty[propertyId]) {
                const r = await portalFetch('/api/v1/client/rentals/properties/' + propertyId + '/fault-types');
                if (r.ok) {
                    this.faultTypesByProperty[propertyId] = r.data.fault_types;
                    if (r.data.limits) this.photoLimits = r.data.limits;
                } else {
                    this.faultWizard.error = r.data?.message || 'Could not load the list of problems.';
                }
            }
        },
        // The moment a type is chosen the person sees what to try first (steps, photos, documents); a type with nothing to show goes straight to the form.
        selectFaultType() {
            const w = this.faultWizard;
            const types = this.faultTypesByProperty[w.property] || [];
            const t = types.find(t => String(t.id) === String(w.faultTypeId));
            w.ftype = t || null;
            w.error = null;
            if (!t) { w.step = 'pick'; return; }
            const hasAid = !!(String(t.first_aid_steps || '').trim() || (t.photos || []).length || (t.documents || []).length);
            w.step = hasAid ? 'aid' : 'form';
            if (!w.title || w.title === w.lastTypeName) w.title = t.name;
            w.lastTypeName = t.name;
        },
        closeFaultWizard() {
            this.freeWizardPhotos(this.faultWizard);
            this.faultWizard = this.blankFaultWizard(null);
        },
        async submitFault(resolution) {
            const w = this.faultWizard;
            if (w.sending || w.photoBusy) return; // one press, one report
            w.error = null;
            const form = new FormData();
            form.append('rental_fault_type_id', w.faultTypeId);
            form.append('resolution', resolution);
            form.append('title', w.title || 'Fault reported');
            form.append('description', w.description || '');
            form.append('submission_key', w.key);
            if (resolution === 'still_a_problem') w.photos.forEach(p => form.append('photos[]', p.file, p.file.name));
            w.sending = true;
            w.progress = 0;
            const r = await portalUpload('/api/v1/client/rentals/properties/' + w.property + '/fault-reports', form, w.key, (pct) => { w.progress = pct; });
            w.sending = false;
            if (!r.ok) {
                w.error = r.status === 0
                    ? 'No connection — check your signal and press the button again. Nothing is sent twice.'
                    : (r.data?.message || 'Could not submit. Please try again.');
                return;
            }
            this.freeWizardPhotos(w);
            w.step = 'done';
            w.doneTitle = resolution === 'first_aid_resolved' ? 'Glad that fixed it' : 'Fault reported';
            w.doneText = resolution === 'first_aid_resolved' ? 'We have noted it on your file.' : 'Your agent has been notified.';
            this.loadFaultReports();
        },

        async loadDecisions() {
            const r = await portalFetch('/api/v1/client/rentals/landlord/decisions');
            if (r.ok) this.decisions = r.data;
        },
        async loadLandlordProperties() {
            const r = await portalFetch('/api/v1/client/rentals/landlord/properties');
            if (r.ok) this.landlordProperties = r.data.properties;
        },
        async loadPropertyDetail(id) {
            const r = await portalFetch('/api/v1/client/rentals/landlord/properties/' + id);
            if (r.ok) this.propertyDetail = r.data.property;
        },
        promptNote() {
            return window.prompt("Add a note (required for 'I'll handle it myself'):") || '';
        },
        // §22 — each decision is one press: the button is off while it is sent, and the server answers a repeat with the first answer.
        async decideFault(id, decision, note) {
            const bk = 'fault' + id;
            if (this.busy[bk]) return;
            if (decision === 'approve_owner_handles' && !note) { note = this.promptNote(); if (!note) return; }
            this.busy[bk] = true;
            try {
                const r = await portalFetch('/api/v1/client/rentals/landlord/fault-reports/' + id + '/decision', { method: 'POST', body: JSON.stringify({ decision, note }), key: 'decide-fault-' + id + '-' + decision });
                if (r.ok) this.loadDecisions();
                else alert(r.data?.message || 'Could not record decision.');
            } finally { this.busy[bk] = false; }
        },
        async openFault(id) {
            this.faultForm = { decision: '', handled_by: '', contractor_name: '', contractor_phone: '', agency_service_provider_id: '', note: '', error: null, busy: false };
            const r = await portalFetch('/api/v1/client/rentals/landlord/fault-reports/' + id);
            if (r.ok) this.faultDetail = r.data.fault_report;
            else alert(r.data?.message || 'Could not open this.');
        },
        async submitFaultDecision() {
            const f = this.faultForm;
            f.error = null;
            if (f.decision === 'decline' && !f.note.trim()) { f.error = 'Please tell us why you are declining.'; return; }
            if (f.decision === 'approve' && !f.handled_by) { f.error = 'Please say who should handle the repair.'; return; }
            if (f.handled_by === 'list' && !f.agency_service_provider_id) { f.error = 'Please choose a contractor.'; return; }
            f.busy = true;
            const body = { decision: f.decision, note: f.note };
            if (f.decision === 'approve') {
                body.handled_by = f.handled_by;
                if (f.handled_by === 'own') { body.contractor_name = f.contractor_name; body.contractor_phone = f.contractor_phone; }
                if (f.handled_by === 'list') body.agency_service_provider_id = Number(f.agency_service_provider_id);
            }
            const r = await portalFetch('/api/v1/client/rentals/landlord/fault-reports/' + this.faultDetail.id + '/decision', { method: 'POST', body: JSON.stringify(body) });
            f.busy = false;
            if (r.ok) { const id = this.faultDetail.id; await this.openFault(id); this.loadDecisions(); this.loadLandlordFaults(); }
            else f.error = r.data?.message || 'Could not record your decision.';
        },
        async decideWorkOrder(id, decision) {
            const bk = 'wo' + id;
            if (this.busy[bk]) return;
            this.busy[bk] = true;
            try {
                const r = await portalFetch('/api/v1/client/rentals/landlord/work-orders/' + id + '/decision', { method: 'POST', body: JSON.stringify({ decision }), key: 'decide-wo-' + id + '-' + decision });
                if (r.ok) this.loadDecisions();
                else alert(r.data?.message || 'Could not record decision.');
            } finally { this.busy[bk] = false; }
        },
        // BUILD 2 BEGIN (§17.7.4) - the decision carries the revision the owner saw; a stale one answers 409 and the list is refreshed.
        async decideVariation(v, decision) {
            const bk = 'var' + v.id;
            if (this.busy[bk]) return;
            const note = decision === 'decline' ? (window.prompt('Add a note (optional):') || '') : '';
            this.busy[bk] = true;
            try {
                const r = await portalFetch('/api/v1/client/rentals/landlord/variations/' + v.id + '/decision', { method: 'POST', body: JSON.stringify({ decision, revision: v.revision, note }), key: 'decide-var-' + v.id + '-' + decision + '-' + v.revision });
                if (r.ok) { this.loadDecisions(); return; }
                alert(r.data?.message || 'Could not record decision.');
                if (r.status === 409 || r.status === 422) this.loadDecisions();
            } finally { this.busy[bk] = false; }
        },
        // BUILD 2 END

        // §15 (AT-447, portal frontend follow-up) — "Request work / report a
        // problem." Same shape as the tenant startFaultReport()/selectFaultType()/
        // submitFault() above, a separate object rather than branching that
        // one on role: the landlord path has no first-aid self-resolve step
        // (an agent raises the work order from this, the landlord never
        // self-resolves) and posts to a different, property-scoped endpoint.
        async loadLandlordFaults() {
            const r = await portalFetch('/api/v1/client/rentals/landlord/fault-reports');
            if (r.ok) this.landlordFaults = r.data.fault_reports;
        },
        blankLandlordWizard(propertyId) {
            return { open: !!propertyId, step: 'form', property: propertyId || null, faultTypeId: '', ftype: null, showAid: false, lastTypeName: '', title: '', description: '', photos: [], photoError: null, photoBusy: false, photoPending: 0, sending: false, progress: 0, error: null, key: this.uuid() };
        },
        async startLandlordFaultReport(propertyId) {
            this.landlordFaultWizard = this.blankLandlordWizard(propertyId);
            if (propertyId && !this.landlordFaultTypesByProperty[propertyId]) {
                const r = await portalFetch('/api/v1/client/rentals/landlord/properties/' + propertyId + '/fault-types');
                if (r.ok) {
                    this.landlordFaultTypesByProperty[propertyId] = r.data.fault_types;
                    if (r.data.limits) this.photoLimits = r.data.limits;
                }
            }
        },
        selectLandlordFaultType() {
            const w = this.landlordFaultWizard;
            const types = this.landlordFaultTypesByProperty[w.property] || [];
            const t = types.find(t => String(t.id) === String(w.faultTypeId));
            w.ftype = t || null;
            w.showAid = !!(t && (String(t.first_aid_steps || '').trim() || (t.photos || []).length || (t.documents || []).length));
            if (t && (!w.title || w.title === w.lastTypeName)) w.title = t.name;
            w.lastTypeName = t ? t.name : '';
        },
        closeLandlordFaultWizard() {
            this.freeWizardPhotos(this.landlordFaultWizard);
            this.landlordFaultWizard = this.blankLandlordWizard(null);
        },
        async submitLandlordFault() {
            const w = this.landlordFaultWizard;
            if (w.sending || w.photoBusy) return;
            w.error = null;
            if (!w.title) { w.error = 'Please give it a short title.'; return; }
            const form = new FormData();
            if (w.faultTypeId) form.append('rental_fault_type_id', w.faultTypeId);
            form.append('title', w.title);
            form.append('description', w.description || '');
            form.append('submission_key', w.key);
            w.photos.forEach(p => form.append('photos[]', p.file, p.file.name));
            w.sending = true;
            w.progress = 0;
            const r = await portalUpload('/api/v1/client/rentals/landlord/properties/' + w.property + '/fault-reports', form, w.key, (pct) => { w.progress = pct; });
            w.sending = false;
            if (!r.ok) {
                w.error = r.status === 0
                    ? 'No connection — check your signal and press the button again. Nothing is sent twice.'
                    : (r.data?.message || 'Could not submit. Please try again.');
                return;
            }
            this.freeWizardPhotos(w);
            w.step = 'done';
            this.loadLandlordFaults();
        },
    };
}
</script>
</body>
</html>
