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
        .whoami { background:#fff; border-bottom:1px solid var(--border); padding:6px 16px; font-size:13px; color:var(--text); display:flex; gap:6px; align-items:baseline; white-space:nowrap; overflow:hidden; }
        .whoami .who-name { font-weight:700; overflow:hidden; text-overflow:ellipsis; min-width:0; }
        .whoami .who-role { color:var(--muted); flex:0 0 auto; }
        .roleswitch { display:flex; gap:0; margin:0 0 12px; border:1px solid var(--border); border-radius:10px; overflow:hidden; background:#fff; }
        .roleswitch button { flex:1; border:0; background:transparent; padding:11px 8px; font-size:15px; font-weight:600; color:var(--muted); }
        .roleswitch button.active { background:var(--brand); color:#fff; }
        .steps { margin:8px 0 2px; }
        .step { display:flex; align-items:baseline; gap:10px; padding:5px 0; color:var(--muted); font-size:14px; flex-wrap:wrap; }
        .step .dot { width:10px; height:10px; border-radius:50%; border:2px solid #c3cad6; flex:0 0 10px; align-self:center; }
        .step.done { color:var(--text); }
        .step.done .dot { background:#1f9d55; border-color:#1f9d55; }
        .step.current { font-weight:700; }
        .step.current .dot { box-shadow:0 0 0 3px rgba(31,157,85,.25); }
        .step .when { margin-left:auto; font-size:12px; color:var(--muted); font-weight:400; }
        .step .detail { flex-basis:100%; padding-left:20px; font-size:13px; color:var(--muted); font-weight:400; }
        .tabs .count { display:inline-block; min-width:18px; margin-left:4px; padding:0 5px; border-radius:9px; background:#d93025; color:#fff; font-size:11px; line-height:18px; text-align:center; }
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
    {{-- Another tab signed in as someone else: this page is showing the previous person - say so and offer the reload, never act on it. --}}
    <div class="wrap" x-show="sessionChanged" x-cloak data-session-changed>
        <div class="card">
            <h2>You signed in as someone else</h2>
            <p>In another window or tab of this browser you signed in as a different person. This page was showing the previous person's information, so nothing here will work any more.</p>
            <button class="btn btn-primary" @click="window.location.reload()">Reload this page</button>
        </div>
    </div>
    {{-- Who is signed in and which side of the portal is on screen - always, one compact line (a shared browser must never leave this unclear). --}}
    <div class="whoami" data-portal-who x-show="session.authenticated && me" x-cloak>
        <span class="muted">Signed in as</span>
        <span class="who-name" data-who-name :title="whoEmail()" x-text="whoName()"></span>
        <span class="who-role" data-who-role x-show="roleLabel()" x-text="'· ' + roleLabel() + ' view'"></span>
    </div>

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
                        <button class="btn btn-primary" :disabled="!!(busy.login)" @click="lookup()" x-text="busy.login ? 'Please wait…' : 'Continue'"></button>
                    </div>
                </template>
                <template x-if="login.step === 'password'">
                    <div>
                        <label>Password</label>
                        <input type="password" x-model="login.password">
                        <button class="btn btn-primary" :disabled="!!(busy.login)" @click="passwordLogin()" x-text="busy.login ? 'Signing in…' : 'Sign in'"></button>
                        <a class="link" @click.prevent="sendOtp('recovery')" href="#">Forgot password?</a>
                    </div>
                </template>
                <template x-if="login.step === 'otp-sent'">
                    <div>
                        <p class="muted">We sent a 6-digit code to <strong x-text="login.email"></strong>.</p>
                        <label>Code</label>
                        <input type="text" inputmode="numeric" maxlength="6" x-model="login.code">
                        <button class="btn btn-primary" :disabled="!!(busy.login)" @click="verifyOtp()" x-text="busy.login ? 'Checking…' : 'Verify'"></button>
                    </div>
                </template>
                <template x-if="login.step === 'set-password'">
                    <div>
                        <p class="muted">Set a password for next time.</p>
                        <label>New password</label>
                        <input type="password" x-model="login.newPassword">
                        <label>Confirm password</label>
                        <input type="password" x-model="login.newPasswordConfirm">
                        <button class="btn btn-primary" :disabled="!!(busy.login)" @click="setPassword()" x-text="busy.login ? 'Saving…' : 'Save & continue'"></button>
                    </div>
                </template>
                <p class="error" x-show="login.error" x-text="login.error"></p>
            </div>
        </template>

        {{-- The link was made for someone else (or for a repair this person has no part in): never show the signed-in person's portal as if it were the link's. --}}
        <template x-if="!loading && session.authenticated && linkIssue">
            <div class="card" data-link-mismatch>
                <h2>This link is not for this account</h2>
                <p>You are signed in as <strong x-text="whoName()"></strong>.</p>
                <p x-show="linkIssue.kind === 'email'">This link is for <strong x-text="linkIssue.masked"></strong>.</p>
                <p x-show="linkIssue.kind === 'fault'">This link is for a repair on a property you have no part in.</p>
                <p x-show="linkIssue.kind === 'view'" data-link-view-issue x-text="'This link is for the ' + (linkIssue.wanted === 'landlord' ? 'owner' : 'tenant') + ' side of the portal, and this login does not have it.'"></p>
                <button class="btn btn-primary" @click="logout()" x-text="linkIssue.masked ? 'Sign out and sign in as ' + linkIssue.masked : 'Sign out and sign in again'"></button>
            </div>
        </template>

        {{-- ── AUTHENTICATED ───────────────────────────────────────── --}}
        <template x-if="!loading && session.authenticated && !linkIssue">
            <div>
                {{-- One login that is both tenant and owner: a clear switch, remembering the last choice. --}}
                <template x-if="roles.length > 1">
                    <div class="roleswitch" data-role-switch role="tablist">
                        <button :class="{active: activeRole==='tenant'}" @click="setRole('tenant')" x-show="roles.includes('tenant')" data-role-tenant>Tenant</button>
                        <button :class="{active: activeRole==='landlord'}" @click="setRole('landlord')" x-show="roles.includes('landlord')" data-role-owner>Owner</button>
                    </div>
                </template>

                {{-- TENANT --}}
                <template x-if="activeRole === 'tenant'">
                    <div>
                        <div class="tabs">
                            <button :class="{active: tenantTab==='home'}" @click="tenantTab='home'; loadOverview()">Home</button>
                            <button :class="{active: tenantTab==='lease'}" @click="tenantTab='lease'; loadTenantLeases()">Lease</button>
                            <button :class="{active: tenantTab==='faults'}" @click="tenantTab='faults'; loadFaultReports()">Faults</button>
                            <button :class="{active: tenantTab==='jobs'}" @click="tenantTab='jobs'; loadWorkOrders()">Work orders<span class="count" data-workorders-count x-show="tenantChecksWaiting() > 0" x-text="tenantChecksWaiting()"></span></button>
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
                                                <select x-model="faultWizard.faultTypeId" :disabled="!!(faultWizard.sending)" @change="selectFaultType()">
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
                                                        <button class="btn btn-ok" :disabled="!!(faultWizard.sending)" @click="submitFault('first_aid_resolved')" x-text="faultWizard.sending ? 'Sending…' : 'That fixed it'"></button>
                                                        <button class="btn btn-outline" :disabled="!!(faultWizard.sending)" @click="faultWizard.step = 'form'">Still a problem</button>
                                                    </div>
                                                </template>

                                                <template x-if="faultWizard.faultTypeId && faultWizard.step === 'form'">
                                                    <div data-fault-form>
                                                        <label>Title</label>
                                                        <input type="text" x-model="faultWizard.title" :disabled="!!(faultWizard.sending)">
                                                        <label>Describe the problem</label>
                                                        <textarea rows="3" x-model="faultWizard.description" :disabled="!!(faultWizard.sending)"></textarea>
                                                        <label>Photos</label>
                                                        @include('rentals.portal._photo-picker', ['bind' => 'faultWizard'])
                                                        <button class="btn btn-danger" data-fault-submit :disabled="!!(faultWizard.sending || faultWizard.photoBusy)" @click="submitFault('still_a_problem')"
                                                                x-text="faultWizard.sending ? ('Sending…' + (faultWizard.progress ? ' ' + faultWizard.progress + '%' : '')) : (faultWizard.photoBusy ? 'Preparing photos…' : 'Submit report')"></button>
                                                        <div class="progress" x-show="faultWizard.sending"><span :style="'width:' + (faultWizard.progress || 5) + '%'"></span></div>
                                                    </div>
                                                </template>
                                                <p class="error" x-show="faultWizard.error" x-text="faultWizard.error"></p>
                                                <button class="btn btn-outline" :disabled="!!(faultWizard.sending)" @click="closeFaultWizard()">Cancel</button>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                {{-- Johan, 8 Oct 2026: the tenant sees where EACH fault is, step by step, fault and work order in one line. --}}
                                <template x-for="f in faultReports" :key="f.id">
                                    <div class="card" data-tenant-fault>
                                        <div class="row"><h2 x-text="f.title" style="margin:0;"></h2></div>
                                        <template x-if="f.progress"><div>@include('rentals.portal._progress', ['p' => 'f.progress'])</div></template>
                                        <button class="btn btn-outline" x-show="f.rental_work_order_id" @click="tenantTab='jobs'; loadWorkOrders()">See the work order</button>
                                    </div>
                                </template>
                                <div class="card" x-show="!faultReports.length"><p class="muted">No faults reported yet.</p></div>
                            </div>
                        </template>

                        {{-- BUILD 3 BEGIN — §17.3.5 / §17.10.4: the tenant's Jobs are WORK ORDERS (job cards are internal and have no portal endpoints): the plain stage, who is doing it, the completion rounds, the photos the agency allows — never a price — and the "Is this finished?" question. --}}
                        <template x-if="tenantTab === 'jobs'">
                            <div>
                                <div class="card" x-show="!workOrders.length"><p class="muted">No work orders yet.</p></div>
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
                                                <button class="btn btn-ok" :disabled="!!(answerBusy)" @click="answerCompletion(w, true)" x-text="answerBusy ? 'Sending…' : 'All done, thanks'"></button>
                                                <template x-if="!answerForm || answerForm.workOrderId !== w.id">
                                                    <button class="btn btn-outline" @click="openNotComplete(w)">Not complete / still wrong</button>
                                                </template>
                                                <template x-if="answerForm && answerForm.workOrderId === w.id">
                                                    <div>
                                                        <label>What is still wrong?</label>
                                                        <textarea rows="3" x-model="answerForm.note" placeholder="For example: the tap is fixed but it still drips."></textarea>
                                                        <label>Photos (optional)</label>
                                                        @include('rentals.portal._photo-picker', ['bind' => 'answerForm'])
                                                        <button class="btn btn-danger" :disabled="!!(answerBusy || answerForm.photoBusy)" @click="answerCompletion(w, false)" x-text="answerBusy ? 'Sending…' : 'Send — it is not complete'"></button>
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
                            <button :class="{active: landlordTab==='properties'}" @click="landlordTab='properties'; loadLandlordProperties()">Properties</button>
                            <button :class="{active: landlordTab==='faults'}" @click="landlordTab='faults'; loadLandlordFaults()">Faults<span class="count" data-faults-count x-show="faultsNeedingOwner() > 0" x-text="faultsNeedingOwner()"></span></button>
                            <button :class="{active: landlordTab==='jobs'}" @click="landlordTab='jobs'; loadWorkOrders(); loadDecisions()">Work orders<span class="count" data-workorders-count x-show="workOrdersNeedingOwner() > 0" x-text="workOrdersNeedingOwner()"></span></button>
                            <button :class="{active: landlordTab==='documents'}" @click="landlordTab='documents'; loadDocuments()">Documents</button>
                        </div>

                        <template x-if="landlordTab === 'home'">
                            @include('rentals.portal._home')
                        </template>

                        <template x-if="landlordTab === 'documents'">
                            @include('rentals.portal._documents')
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
                                <div class="card" x-show="!workOrders.length"><p class="muted">No work orders yet.</p></div>
                                <template x-for="w in workOrders" :key="w.id">
                                    <div class="card" data-work-order>
                                        <div class="row"><h2 x-text="w.title"></h2><span class="badge" x-text="w.stage_label"></span></div>
                                        <p class="muted" x-show="w.property_address" x-text="w.property_address"></p>
                                        <p class="muted" x-text="w.who_label + (w.contractor_name ? ' — ' + w.contractor_name : '')"></p>
                                        <p class="muted" x-show="w.completed_at" x-text="w.completed_at ? 'Completed ' + w.completed_at.substring(0,10) : ''"></p>
                                        <p x-show="!w.completed_at && w.appointment_at"><strong x-text="w.appointment_at ? 'Appointment: ' + new Date(w.appointment_at).toLocaleString([], {dateStyle:'full', timeStyle:'short'}) : ''"></strong><span class="muted" x-show="w.appointment_note" x-text="w.appointment_note ? ' — ' + w.appointment_note : ''"></span></p>
                                        <a class="link" href="#" x-show="w.rental_fault_report_id" @click.prevent="landlordTab='faults'; loadLandlordFaults(); openFault(w.rental_fault_report_id)">See the fault →</a>
                                        <p class="muted" x-show="w.contractor_phone" x-text="w.contractor_phone ? 'Contractor phone: ' + w.contractor_phone : ''"></p>
                                        <p class="muted" x-show="w.owner_facing_amount !== null && w.owner_facing_amount !== undefined" x-text="'Amount: R ' + w.owner_facing_amount"></p>
                                        {{-- §17.31 — supplier invoices the agent has chosen to share with the owner (never shown to a tenant). --}}
                                        <div data-wo-invoices x-show="(w.invoices || []).length" style="margin-top:10px; padding-top:10px; border-top:1px solid #eef1f6;">
                                            <p class="muted"><strong>Supplier invoices</strong></p>
                                            <template x-for="inv in (w.invoices || [])" :key="inv.id">
                                                <div class="list-item" data-wo-invoice>
                                                    <div class="row"><strong x-text="'Invoice ' + inv.invoice_number"></strong><span x-text="'R ' + Number(inv.amount).toFixed(2)"></span></div>
                                                    <div class="muted" x-text="[inv.supplier, inv.invoice_date].filter(Boolean).join(' · ')"></div>
                                                    <div style="display:flex; gap:14px; margin-top:6px;">
                                                        <a class="link" :href="inv.view_url" target="_blank" rel="noopener">View</a>
                                                        <a class="link" :href="inv.download_url">Download</a>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                        {{-- W6 (8 Oct 2026): the owner may also set the appointment and report progress; the tenant is told. The agency's own team reports through its job card. --}}
                                        <template x-if="w.stage !== 'completed' && w.stage !== 'cancelled'">
                                            <div data-owner-appointment style="margin-top:10px; padding-top:10px; border-top:1px solid #eef1f6;">
                                                {{-- An appointment only once the job is approved (the server decides: owner_can_appoint). --}}
                                                <template x-if="w.owner_can_appoint">
                                                    <div data-owner-appointment-form>
                                                        <label>Appointment for the repair</label>
                                                        <input type="datetime-local" x-model="apptDraft[w.id + '_at']">
                                                        <input type="text" maxlength="500" placeholder="Note for the tenant (optional)" x-model="apptDraft[w.id + '_note']">
                                                        <button class="btn btn-outline" @click="setAppointment(w)" x-text="w.appointment_at ? 'Change the appointment' : 'Set the appointment'"></button>
                                                    </div>
                                                </template>
                                                <p class="muted" x-show="!w.owner_can_appoint" data-owner-appointment-locked>The appointment can be set once the job is approved.</p>
                                                {{-- Only the buttons that will work (the server decides: owner_can_start / owner_can_finish), else the plain reason why not. --}}
                                                <template x-if="w.who !== 'our_team'">
                                                    <div data-owner-progress>
                                                        <button class="btn btn-outline" x-show="w.owner_can_start" @click="reportProgress(w, 'started')" data-owner-started>The work has started</button>
                                                        <button class="btn btn-ok" x-show="w.owner_can_finish" @click="reportProgress(w, 'finished')" data-owner-finished>The work is finished</button>
                                                        <p class="muted" x-show="w.owner_progress_note" x-text="w.owner_progress_note" data-owner-progress-note></p>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                        <div class="photo-grid" x-show="w.photos.length">
                                            <template x-for="ph in w.photos" :key="ph.id">
                                                <a :href="ph.url" target="_blank" rel="noopener"><img :src="ph.url" :alt="ph.photo_type + ' photo'" loading="lazy"></a>
                                            </template>
                                        </div>
                                        {{-- A quote waiting on the owner lives on ITS work order (was the Decisions tab). --}}
                                        <template x-if="w.owner_approval_status === 'pending'">
                                            <div data-wo-decision style="margin-top:10px; padding-top:10px; border-top:1px solid #eef1f6;">
                                                <p class="badge" style="background:#fdecea; color:#b3261e;">Needs your decision</p>
                                                <p class="muted">Quote: <strong x-text="'R ' + (w.owner_facing_amount ?? 0)"></strong></p>
                                                <button class="btn btn-ok" :disabled="!!(busy['wo' + w.id])" @click="decideWorkOrder(w.id, 'approve')" x-text="busy['wo' + w.id] ? 'Sending…' : 'Approve'" data-wo-approve></button>
                                                <button class="btn btn-danger" x-show="!declineOpen[w.id]" :disabled="!!(busy['wo' + w.id])" @click="declineOpen[w.id] = true" data-wo-decline>Decline</button>
                                                {{-- Declining asks for the reason (the agent is told it). --}}
                                                <div x-show="declineOpen[w.id]" data-wo-decline-form style="margin-top:8px;">
                                                    <label>Why are you declining this quote?</label>
                                                    <textarea rows="2" maxlength="2000" x-model="declineNote[w.id]" placeholder="For example: too expensive, please get another quote."></textarea>
                                                    <button class="btn btn-danger" :disabled="!!(busy['wo' + w.id])" @click="sendDecline(w.id)" data-wo-decline-send>Send my decline</button>
                                                    <p class="error" x-show="declineError[w.id]" x-text="declineError[w.id]"></p>
                                                    <button class="btn btn-outline" @click="declineOpen[w.id] = false">Back</button>
                                                </div>
                                            </div>
                                        </template>
                        {{-- BUILD 2 BEGIN — extra work beyond the owner's agreed terms (.ai/specs/rental-work-orders.md §17.7.4): what was approved, the extra work (selling only), the crew's photos and note, the new total, Approve / Decline. --}}
                        <template x-for="v in variationsFor(w.id)" :key="'v'+v.id">
                            <div class="card" data-variation-card>
                                <h2 x-text="'Extra work needs your approval'"></h2>
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
                                <button class="btn btn-ok" :disabled="!!(busy['var' + v.id])" @click="decideVariation(v, 'approve')" x-text="busy['var' + v.id] ? 'Sending…' : 'Approve'"></button>
                                <button class="btn btn-danger" :disabled="!!(busy['var' + v.id])" @click="decideVariation(v, 'decline')">Decline</button>
                            </div>
                        </template>
                        {{-- BUILD 2 END --}}
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
                                {{-- The fault itself is the primary route (Johan, 8 Oct 2026): open it here, read the agent's version, decide. --}}
                                @include('rentals.portal._fault-detail')

                                <template x-if="!faultDetail && landlordFaults.filter(f => f.needs_decision).length">
                                    <div class="card" data-needs-decision style="border-color:#f1b7b2;">
                                        <h2 x-text="landlordFaults.filter(f => f.needs_decision).length === 1 ? 'A repair needs your decision' : landlordFaults.filter(f => f.needs_decision).length + ' repairs need your decision'"></h2>
                                        <template x-for="f in landlordFaults.filter(f => f.needs_decision)" :key="'nd'+f.id">
                                            <div class="list-item">
                                                <div class="row"><strong x-text="f.title"></strong><span class="badge" style="background:#fdecea; color:#b3261e;">Needs your decision</span></div>
                                                <button class="btn btn-primary" @click="openFault(f.id)">Review and decide</button>
                                            </div>
                                        </template>
                                    </div>
                                </template>

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
                                                <select x-model="landlordFaultWizard.faultTypeId" :disabled="!!(landlordFaultWizard.sending)" @change="selectLandlordFaultType()">
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
                                                <input type="text" x-model="landlordFaultWizard.title" :disabled="!!(landlordFaultWizard.sending)">
                                                <label>Describe the problem</label>
                                                <textarea rows="3" x-model="landlordFaultWizard.description" :disabled="!!(landlordFaultWizard.sending)"></textarea>
                                                <label>Photos</label>
                                                @include('rentals.portal._photo-picker', ['bind' => 'landlordFaultWizard'])
                                                <button class="btn btn-danger" data-fault-submit :disabled="!!(landlordFaultWizard.sending || landlordFaultWizard.photoBusy)" @click="submitLandlordFault()"
                                                        x-text="landlordFaultWizard.sending ? ('Sending…' + (landlordFaultWizard.progress ? ' ' + landlordFaultWizard.progress + '%' : '')) : (landlordFaultWizard.photoBusy ? 'Preparing photos…' : 'Submit request')"></button>
                                                <div class="progress" x-show="landlordFaultWizard.sending"><span :style="'width:' + (landlordFaultWizard.progress || 5) + '%'"></span></div>
                                                <button class="btn btn-outline" :disabled="!!(landlordFaultWizard.sending)" @click="closeLandlordFaultWizard()">Cancel</button>
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
                                    <h2>Repairs and requests</h2>
                                    <template x-for="f in landlordFaults" :key="f.id">
                                        <div class="list-item row" style="cursor:pointer" @click="openFault(f.id)">
                                            <span x-text="f.title"></span>
                                            <span class="badge" :style="f.needs_decision ? 'background:#fdecea; color:#b3261e;' : ''" x-text="f.needs_decision ? 'Needs your decision' : (f.status_label || f.status)"></span>
                                        </div>
                                    </template>
                                    <p class="muted" x-show="!landlordFaults.length">No repairs or requests yet.</p>
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
    if (portalClientChanged(res.headers && res.headers.get ? res.headers.get('X-Portal-Client') : null)) {
        return { ok: false, status: 409, data: { message: 'You signed in as someone else in another window. Please reload this page.', session_changed: true } };
    }
    let data = null;
    try { data = await res.json(); } catch (e) { /* no body */ }
    return { ok: res.ok, status: res.status, data };
}

// A browser holds ONE portal session. The server names the person who answered (X-Portal-Client); if it is not the person this page was
// drawn for, another tab has signed in as someone else - this page must not keep offering the old person's buttons.
let portalClientId = null;
function portalClientChanged(id) {
    if (!id) return false;
    if (portalClientId && portalClientId !== id) {
        if (typeof window.__portalSessionChanged === 'function') window.__portalSessionChanged();
        return true;
    }
    portalClientId = id;
    return false;
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
            if (portalClientChanged(xhr.getResponseHeader ? xhr.getResponseHeader('X-Portal-Client') : null)) {
                resolve({ ok: false, status: 409, data: { message: 'You signed in as someone else in another window. Please reload this page.', session_changed: true } });
                return;
            }
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
        initStarted: false,
        // §22 — the agency's logo / name. Known before sign-in only when the personal link carried the email.
        branding: @json($branding ?? null),
        busy: {},
        session: { authenticated: false },
        me: null,          // who /client/me says is signed in
        linkIssue: null,   // {kind:'email'|'fault', masked} when the link is not for this account
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
        workOrders: [], declineOpen: {}, declineNote: {}, declineError: {}, sessionChanged: false, answerForm: null, answerBusy: false, answerError: null, answerKeys: {},
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
            // Alpine calls an x-data object's own init() by itself, and this page ALSO says x-init="init()": without this guard every load
            // ran it twice at once (two session checks, two role detections, two copies of every list request racing on a cold server).
            if (this.initStarted) return;
            this.initStarted = true;
            this.watchSession();
            // rental-portal-access.md §16 — a personal link (?email=…) arrives with the email already filled in.
            // Only pre-fills the field: nothing is looked up or sent until the person presses Continue.
            // WHO the link is for: the server read a mail link's SIGNED recipient reference (?r=...) and hands the address over; the
            // lease screen's copy link carries ?email= instead (App\Support\PortalLink).
            const linked = String(@json($linkedEmail ?? null) || new URLSearchParams(window.location.search).get('email') || '').trim();
            if (linked && linked.length <= 255 && /^[^\s@]+@[^\s@]+$/.test(linked)) this.login.email = linked;
            const me = await portalFetch('/api/v1/client/me');
            if (me.ok) {
                this.session.authenticated = true;
                this.me = me.data;
                // A link made for ANOTHER person must never silently show this person's portal: say whose session this is.
                const signedInAs = String((me.data && me.data.client && me.data.client.email) || '').toLowerCase();
                if (this.login.email && signedInAs && this.login.email.toLowerCase() !== signedInAs) {
                    this.linkIssue = { kind: 'email', masked: this.maskEmail(this.login.email) };
                } else {
                    await this.detectRoles();
                }
            }
            this.loading = false;
        },

        // ── who is signed in, which side is on screen, and links made for somebody else ──
        whoName() { const c = (this.me && this.me.contact) || {}; return (c.full_name || [c.first_name, c.last_name].filter(Boolean).join(' ') || (this.me && this.me.client && this.me.client.email) || 'you').trim(); },
        whoEmail() { return (this.me && this.me.client && this.me.client.email) || ''; },
        roleLabel() { return this.linkIssue ? '' : (this.activeRole === 'landlord' ? 'Owner' : (this.activeRole === 'tenant' ? 'Tenant' : '')); },
        maskEmail(e) {
            const [u, d] = String(e).split('@');
            if (!d) return '';
            return (u.length <= 2 ? u[0] + '*' : u[0] + '*'.repeat(Math.min(6, u.length - 2)) + u[u.length - 1]) + '@' + d;
        },
        rememberedRole() { try { return window.localStorage.getItem('portal.role.' + ((this.me && this.me.client && this.me.client.id) || '')) || null; } catch (e) { return null; } },
        rememberRole(role) { try { window.localStorage.setItem('portal.role.' + ((this.me && this.me.client && this.me.client.id) || ''), role); } catch (e) { /* private window: the choice just is not remembered */ } },

        async loadBranding() {
            const r = await portalFetch('/api/v1/client/rentals/branding');
            if (r.ok && r.data && r.data.branding) this.branding = r.data.branding;
        },

        // §27 - when another tab of this browser signs in as someone else, portalFetch() sees a different person answering and calls this.
        watchSession() { window.__portalSessionChanged = () => { this.sessionChanged = true; }; },

        async detectRoles() {
            this.loadBranding();
            const roles = [];
            const leases = await portalFetch('/api/v1/client/rentals/leases');
            if (leases.ok && leases.data.leases && leases.data.leases.length) roles.push('tenant');
            const props = await portalFetch('/api/v1/client/rentals/landlord/properties');
            if (props.ok && props.data.properties && props.data.properties.length) roles.push('landlord');
            this.roles = roles;
            if (leases.ok && leases.data.leases) this.tenantLeases = leases.data.leases;
            if (props.ok && props.data.properties) this.landlordProperties = props.data.properties;
            // The last side this person used (one login can be both tenant and owner); otherwise the first they have.
            const remembered = this.rememberedRole();
            this.activeRole = (remembered && roles.includes(remembered)) ? remembered : (roles[0] || null);

            // WHERE a mail link lands, and in WHICH view (App\Support\PortalLink: ?as=owner|tenant and one target). The side the mail was
            // written for always wins over the side last used: a person who is both tenant and owner opens the right one.
            const qs = new URLSearchParams(window.location.search);
            const num = (k) => parseInt(qs.get(k) || '', 10) || 0;
            const linkedFault = parseInt(qs.get('fault') || '', 10) || 0, linkedWo = num('wo'), linkedInsp = num('insp'), linkedLease = num('lease'), linkedDocs = qs.get('docs') === '1';
            const wanted = { owner: 'landlord', tenant: 'tenant' }[qs.get('as')] || (linkedFault > 0 && !qs.get('as') ? 'landlord' : null);   // old owner fault links had no side: they were always the owner's
            if (wanted) {
                if (!roles.includes(wanted)) { this.linkIssue = { kind: (!qs.get('as') ? 'fault' : 'view'), masked: null, wanted }; return; }
                this.activeRole = wanted;
                this.rememberRole(wanted);
            }

            if (this.activeRole === 'landlord') {
                this.landlordTab = linkedFault > 0 ? 'faults' : (linkedWo > 0 ? 'jobs' : (linkedDocs ? 'documents' : 'home'));
                if (linkedFault > 0) {
                    // lands on that exact fault with the decision controls; a person with no part in that repair is told so
                    const opened = await portalFetch('/api/v1/client/rentals/landlord/fault-reports/' + linkedFault);
                    if (!opened.ok) { this.linkIssue = { kind: 'fault', masked: null }; return; }
                    this.faultDetail = opened.data.fault_report;
                    this.$nextTick(() => { const el = document.querySelector('[data-fault-detail]'); if (el && el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
                }
                this.loadOverview(); this.loadDecisions(); this.loadLandlordFaults();
                if (linkedDocs) this.loadDocuments();
                if (linkedWo > 0 || !(linkedFault > 0)) {
                    await this.loadWorkOrders();
                    if (linkedWo > 0 && !this.workOrders.some((w) => w.id === linkedWo)) { this.linkIssue = { kind: 'fault', masked: null }; return; }
                } else { this.loadWorkOrders(); }
                return;
            }
            if (this.activeRole === 'tenant') {
                this.tenantTab = linkedFault > 0 ? 'faults' : (linkedWo > 0 ? 'jobs' : (linkedDocs ? 'documents' : (linkedLease > 0 ? 'lease' : 'home')));
                this.loadOverview();
                if (linkedLease > 0) this.loadTenantLeases();
                if (linkedDocs) this.loadDocuments();
                await Promise.all([this.loadWorkOrders(), this.loadFaultReports()]);
                const missing = (linkedFault > 0 && !this.faultReports.some((f) => f.id === linkedFault)) || (linkedWo > 0 && !this.workOrders.some((w) => w.id === linkedWo));
                if (missing) this.linkIssue = { kind: 'fault', masked: null };   // not this tenant's repair: told so, never shown somebody else's
            }
        },

        setRole(role) {
            this.activeRole = role;
            this.rememberRole(role);
            if (role === 'tenant') { this.tenantTab = 'home'; this.tenantLeases.length || this.loadTenantLeases(); this.loadWorkOrders(); this.loadFaultReports(); }
            if (role === 'landlord') { this.landlordTab = 'home'; this.loadDecisions(); this.loadLandlordFaults(); this.loadWorkOrders(); }
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
        // What needs the owner, counted for the tab badges (the decisions themselves live on the fault / work order they belong to).
        faultsNeedingOwner() { return (this.landlordFaults || []).filter(f => f.needs_decision).length || (this.decisions.fault_reports || []).length; },
        workOrdersNeedingOwner() { return (this.decisions.work_orders || []).length + (this.decisions.variations || []).length; },
        variationsFor(workOrderId) { return (this.decisions.variations || []).filter(v => v.work_order_id === workOrderId); },
        tenantChecksWaiting() { return (this.workOrders || []).filter(w => w.awaiting_answer).length; },
        fmtDay(iso) { return iso ? new Date(iso).toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' }) : ''; },
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
            if (r.ok) {
                this.faultDetail = r.data.fault_report;
                this.$nextTick(() => { const el = document.querySelector('[data-fault-detail]'); if (el && el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
            } else alert(r.data?.message || 'Could not open this.');
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
            if (r.ok) { const id = this.faultDetail.id; await this.openFault(id); this.loadDecisions(); this.loadLandlordFaults(); this.loadWorkOrders(); }
            else f.error = r.data?.message || 'Could not record your decision.';
        },
        // Declining a quote needs the owner's reason (the agent is told it); an empty one is refused here with a plain sentence.
        async sendDecline(id) {
            const reason = String(this.declineNote[id] || '').trim();
            if (reason.length < 3) { this.declineError[id] = 'Please tell us why, so we can get you a better quote.'; return; }
            this.declineError[id] = null;
            await this.decideWorkOrder(id, 'decline', reason);
        },
        async decideWorkOrder(id, decision, note) {
            const bk = 'wo' + id;
            if (this.busy[bk]) return;
            this.busy[bk] = true;
            try {
                const r = await portalFetch('/api/v1/client/rentals/landlord/work-orders/' + id + '/decision', { method: 'POST', body: JSON.stringify({ decision, note: note ? String(note).trim() : '' }), key: 'decide-wo-' + id + '-' + decision });
                if (r.ok) { await this.loadDecisions(); await this.loadWorkOrders(); }
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
                if (r.ok) { await this.loadDecisions(); await this.loadWorkOrders(); return; }
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
