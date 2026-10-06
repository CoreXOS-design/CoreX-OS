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
        header.top { background:var(--brand); color:#fff; padding:18px 16px; display:flex; align-items:center; justify-content:space-between; }
        header.top h1 { font-size:16px; margin:0; font-weight:700; }
        header.top button { background:transparent; border:1px solid rgba(255,255,255,.4); color:#fff; border-radius:8px; padding:6px 10px; font-size:13px; }
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
        .tabs { display:flex; gap:6px; margin-bottom:14px; }
        .tabs button { flex:1; padding:10px; border-radius:10px; border:1px solid var(--border); background:var(--surface); font-weight:600; font-size:13px; }
        .tabs button.active { background:var(--brand); color:#fff; border-color:var(--brand); }
        .badge { display:inline-block; font-size:11px; font-weight:700; padding:3px 8px; border-radius:999px; background:var(--surface-2); color:var(--muted); text-transform:uppercase; }
        .error { color:var(--danger); font-size:13px; margin-top:6px; }
        .success { color:var(--ok); font-size:13px; margin-top:6px; }
        .list-item { border-bottom:1px solid var(--border); padding:10px 0; }
        .list-item:last-child { border-bottom:none; }
        a.link { color:var(--accent); text-decoration:none; font-weight:600; font-size:13px; }
        .photo-grid { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
        .photo-grid img { width:72px; height:72px; object-fit:cover; border-radius:8px; border:1px solid var(--border); }
    </style>
</head>
<body>
<div x-data="rentalsPortal()" x-init="init()">
    <header class="top">
        <h1>My Rentals</h1>
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
                        <button class="btn btn-primary" @click="lookup()">Continue</button>
                    </div>
                </template>
                <template x-if="login.step === 'password'">
                    <div>
                        <label>Password</label>
                        <input type="password" x-model="login.password">
                        <button class="btn btn-primary" @click="passwordLogin()">Sign in</button>
                        <a class="link" @click.prevent="sendOtp('recovery')" href="#">Forgot password?</a>
                    </div>
                </template>
                <template x-if="login.step === 'otp-sent'">
                    <div>
                        <p class="muted">We sent a 6-digit code to <strong x-text="login.email"></strong>.</p>
                        <label>Code</label>
                        <input type="text" inputmode="numeric" maxlength="6" x-model="login.code">
                        <button class="btn btn-primary" @click="verifyOtp()">Verify</button>
                    </div>
                </template>
                <template x-if="login.step === 'set-password'">
                    <div>
                        <p class="muted">Set a password for next time.</p>
                        <label>New password</label>
                        <input type="password" x-model="login.newPassword">
                        <label>Confirm password</label>
                        <input type="password" x-model="login.newPasswordConfirm">
                        <button class="btn btn-primary" @click="setPassword()">Save & continue</button>
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
                            <button :class="{active: tenantTab==='lease'}" @click="tenantTab='lease'; loadTenantLeases()">Lease</button>
                            <button :class="{active: tenantTab==='faults'}" @click="tenantTab='faults'; loadFaultReports()">Faults</button>
                            <button :class="{active: tenantTab==='jobs'}" @click="tenantTab='jobs'; loadWorkOrders()">Jobs</button>
                            <button :class="{active: tenantTab==='documents'}" @click="tenantTab='documents'; loadDocuments()">Documents</button>
                        </div>

                        <template x-if="tenantTab === 'lease'">
                            <div>
                                <template x-for="lease in tenantLeases" :key="lease.id">
                                    <div class="card">
                                        <h2 x-text="lease.property_address || ('Lease #' + lease.id)"></h2>
                                        <span class="badge" x-text="lease.status"></span>
                                        <a class="link" style="display:block;margin-top:8px" @click.prevent="loadLeaseDetail(lease.id)" href="#">View details →</a>
                                    </div>
                                </template>
                                <template x-if="leaseDetail">
                                    <div class="card">
                                        <h2>Lease terms</h2>
                                        <div class="row"><span class="muted">Rent</span><strong x-text="'R ' + (leaseDetail.rent_amount ?? 0).toLocaleString()"></strong></div>
                                        <div class="row"><span class="muted">Deposit</span><strong x-text="'R ' + (leaseDetail.deposit_amount ?? 0).toLocaleString()"></strong></div>
                                        <div class="row"><span class="muted">Start</span><strong x-text="leaseDetail.start_date"></strong></div>
                                        <div class="row"><span class="muted">End</span><strong x-text="leaseDetail.end_date"></strong></div>
                                        <div class="row"><span class="muted">Landlord</span><strong x-text="(leaseDetail.landlord_names || []).join(', ') || '—'"></strong></div>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <template x-if="tenantTab === 'faults'">
                            <div>
                                <button class="btn btn-primary" @click="startFaultReport()">Report a fault</button>

                                <template x-if="faultWizard.open">
                                    <div class="card">
                                        <template x-if="!faultWizard.faultTypeId">
                                            <div>
                                                <label>What's the problem?</label>
                                                <select x-model="faultWizard.faultTypeId" @change="selectFaultType()">
                                                    <option value="">Choose…</option>
                                                    <template x-for="ft in faultWizard.property ? faultTypesByProperty[faultWizard.property] || [] : []" :key="ft.id">
                                                        <option :value="ft.id" x-text="ft.name"></option>
                                                    </template>
                                                </select>
                                            </div>
                                        </template>
                                        <template x-if="faultWizard.faultTypeId && faultWizard.firstAid">
                                            <div>
                                                <h2>Try this first</h2>
                                                <p x-text="faultWizard.firstAid"></p>
                                                <button class="btn btn-ok" @click="submitFault('first_aid_resolved')">That fixed it</button>
                                                <button class="btn btn-outline" @click="faultWizard.firstAid=null">Still a problem</button>
                                            </div>
                                        </template>
                                        <template x-if="faultWizard.faultTypeId && !faultWizard.firstAid">
                                            <div>
                                                <label>Title</label>
                                                <input type="text" x-model="faultWizard.title">
                                                <label>Describe the problem</label>
                                                <textarea rows="3" x-model="faultWizard.description"></textarea>
                                                <label>Photos</label>
                                                <input type="file" accept="image/*" multiple @change="faultWizard.photos = $event.target.files">
                                                <button class="btn btn-danger" @click="submitFault('still_a_problem')">Submit report</button>
                                            </div>
                                        </template>
                                        <p class="error" x-show="faultWizard.error" x-text="faultWizard.error"></p>
                                    </div>
                                </template>

                                <div class="card">
                                    <h2>My faults</h2>
                                    <template x-for="f in faultReports" :key="f.id">
                                        <div class="list-item row">
                                            <span x-text="f.title"></span>
                                            <span class="badge" x-text="f.status"></span>
                                            {{-- BUILD 3 — §17.3.5: the linked work order's plain stage. --}}
                                            <span class="muted" x-show="f.work_order_stage" x-text="f.work_order_stage ? 'Job: ' + f.work_order_stage.stage_label : ''"></span>
                                        </div>
                                    </template>
                                    <p class="muted" x-show="!faultReports.length">No faults reported yet.</p>
                                </div>
                            </div>
                        </template>

                        {{-- BUILD 3 BEGIN — §17.3.5 / §17.10.4: the tenant's Jobs are WORK ORDERS (the job-card endpoints stay registered but are no longer linked from here): the plain stage, who is doing it, the completion rounds, the photos the agency allows — never a price — and the "Is this finished?" question. --}}
                        <template x-if="tenantTab === 'jobs'">
                            <div>
                                <div class="card" x-show="!workOrders.length"><p class="muted">No maintenance jobs yet.</p></div>
                                <template x-for="w in workOrders" :key="w.id">
                                    <div class="card" data-work-order>
                                        <div class="row"><h2 x-text="w.title"></h2><span class="badge" x-text="w.stage_label"></span></div>
                                        <p class="muted" x-show="w.property_address" x-text="w.property_address"></p>
                                        <p class="muted" x-text="w.who_label + (w.contractor_name ? ' — ' + w.contractor_name : '')"></p>
                                        <p class="muted" x-show="w.completed_at" x-text="w.completed_at ? 'Completed ' + w.completed_at.substring(0,10) : ''"></p>
                                        <p class="muted" x-show="!w.completed_at && w.scheduled_at" x-text="w.scheduled_at ? 'Scheduled ' + w.scheduled_at.substring(0,10) : ''"></p>
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
                                                <button class="btn btn-ok" :disabled="answerBusy" @click="answerCompletion(w, true)">All done, thanks</button>
                                                <template x-if="!answerForm || answerForm.workOrderId !== w.id">
                                                    <button class="btn btn-outline" @click="openNotComplete(w)">Not complete / still wrong</button>
                                                </template>
                                                <template x-if="answerForm && answerForm.workOrderId === w.id">
                                                    <div>
                                                        <label>What is still wrong?</label>
                                                        <textarea rows="3" x-model="answerForm.note" placeholder="For example: the tap is fixed but it still drips."></textarea>
                                                        <label>Photos (optional)</label>
                                                        <input type="file" accept="image/*" multiple @change="answerForm.photos = $event.target.files">
                                                        <button class="btn btn-danger" :disabled="answerBusy" @click="answerCompletion(w, false)">Send — it is not complete</button>
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
                            <div class="card">
                                <h2>My documents</h2>
                                <template x-for="d in documents" :key="d.id">
                                    <div class="list-item" x-text="d.name"></div>
                                </template>
                                <p class="muted" x-show="!documents.length">No documents shared yet.</p>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- LANDLORD --}}
                <template x-if="activeRole === 'landlord'">
                    <div>
                        <div class="tabs">
                            <button :class="{active: landlordTab==='decisions'}" @click="landlordTab='decisions'; loadDecisions()">Decisions</button>
                            <button :class="{active: landlordTab==='properties'}" @click="landlordTab='properties'; loadLandlordProperties()">Properties</button>
                            <button :class="{active: landlordTab==='faults'}" @click="landlordTab='faults'; loadLandlordFaults()">Faults</button>
                            <button :class="{active: landlordTab==='jobs'}" @click="landlordTab='jobs'; loadWorkOrders()">Jobs</button>
                        </div>

                        <template x-if="landlordTab === 'decisions'">
                            <div>
                                <div class="card" x-show="!decisions.fault_reports.length && !decisions.work_orders.length">
                                    <p class="muted">Nothing needs your decision right now.</p>
                                </div>
                                <template x-for="f in decisions.fault_reports" :key="'f'+f.id">
                                    <div class="card">
                                        <h2 x-text="f.title"></h2>
                                        <button class="btn btn-ok" @click="decideFault(f.id, 'approve_agency_appoints')">Approve</button>
                                        <button class="btn btn-outline" @click="handleMyselfNote=''; decideFault(f.id, 'approve_owner_handles', promptNote())">I'll handle it myself</button>
                                        <button class="btn btn-danger" @click="decideFault(f.id, 'decline')">Decline</button>
                                    </div>
                                </template>
                                <template x-for="w in decisions.work_orders" :key="'w'+w.id">
                                    <div class="card">
                                        <h2 x-text="w.title"></h2>
                                        <p class="muted">Quote: <strong x-text="'R ' + (w.selected_quote_amount ?? 0)"></strong></p>
                                        <button class="btn btn-ok" @click="decideWorkOrder(w.id, 'approve')">Approve</button>
                                        <button class="btn btn-danger" @click="decideWorkOrder(w.id, 'decline')">Decline</button>
                                    </div>
                                </template>
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
                                        <p class="muted" x-show="!w.completed_at && w.scheduled_at" x-text="w.scheduled_at ? 'Scheduled ' + w.scheduled_at.substring(0,10) : ''"></p>
                                        <p class="muted" x-show="w.owner_facing_amount !== null && w.owner_facing_amount !== undefined" x-text="'Amount: R ' + w.owner_facing_amount"></p>
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
                                    <div class="card">
                                        <h2>Request work</h2>
                                        <label>What's the problem?</label>
                                        <select x-model="landlordFaultWizard.faultTypeId" @change="selectLandlordFaultType()">
                                            <option value="">Choose… (or skip and describe it below)</option>
                                            <template x-for="ft in landlordFaultTypesByProperty[landlordFaultWizard.property] || []" :key="ft.id">
                                                <option :value="ft.id" x-text="ft.name + (ft.category ? ' (' + ft.category + ')' : '')"></option>
                                            </template>
                                        </select>
                                        <template x-if="landlordFaultWizard.firstAid">
                                            <p class="muted" x-text="landlordFaultWizard.firstAid"></p>
                                        </template>
                                        <label>Title</label>
                                        <input type="text" x-model="landlordFaultWizard.title">
                                        <label>Describe the problem</label>
                                        <textarea rows="3" x-model="landlordFaultWizard.description"></textarea>
                                        <label>Photos</label>
                                        <input type="file" accept="image/*" multiple @change="landlordFaultWizard.photos = $event.target.files">
                                        <button class="btn btn-danger" @click="submitLandlordFault()">Submit request</button>
                                        <button class="btn btn-outline" @click="landlordFaultWizard.open=false">Cancel</button>
                                        <p class="error" x-show="landlordFaultWizard.error" x-text="landlordFaultWizard.error"></p>
                                        <p class="success" x-show="landlordFaultWizard.success" x-text="landlordFaultWizard.success"></p>
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
                                        <div class="list-item row">
                                            <span x-text="f.title"></span>
                                            <span class="badge" x-text="f.status"></span>
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

async function portalFetch(url, options = {}) {
    await ensureCsrfCookie();
    const headers = Object.assign({
        'Accept': 'application/json',
        'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'),
    }, options.headers || {});
    if (!(options.body instanceof FormData) && options.body) {
        headers['Content-Type'] = 'application/json';
    }
    const res = await fetch(url, Object.assign({ credentials: 'same-origin' }, options, { headers }));
    let data = null;
    try { data = await res.json(); } catch (e) { /* no body */ }
    return { ok: res.ok, status: res.status, data };
}

function rentalsPortal() {
    return {
        loading: true,
        session: { authenticated: false },
        roles: [],
        activeRole: null,
        login: { step: 'email', email: '', password: '', code: '', newPassword: '', newPasswordConfirm: '', error: null, mustSetPassword: false },
        tenantTab: 'lease',
        landlordTab: 'decisions',
        tenantLeases: [], leaseDetail: null,
        faultReports: [], documents: [],
        faultWizard: { open: false, property: null, faultTypeId: '', firstAid: null, title: '', description: '', photos: null, error: null },
        faultTypesByProperty: {},
        decisions: { fault_reports: [], work_orders: [] },
        landlordProperties: [], propertyDetail: null,
        landlordFaults: [],
        jobCards: [],
        // BUILD 3 — the portal's Jobs are work orders (§17.3.5); the "is this finished?" answer form (§17.10.4).
        workOrders: [], answerForm: null, answerBusy: false, answerError: null,
        landlordFaultWizard: { open: false, property: null, faultTypeId: '', firstAid: null, title: '', description: '', photos: null, error: null, success: null },
        landlordFaultTypesByProperty: {},

        async init() {
            const me = await portalFetch('/api/v1/client/me');
            if (me.ok) {
                this.session.authenticated = true;
                await this.detectRoles();
            }
            this.loading = false;
        },

        async detectRoles() {
            const roles = [];
            const leases = await portalFetch('/api/v1/client/rentals/leases');
            if (leases.ok && leases.data.leases && leases.data.leases.length) roles.push('tenant');
            const props = await portalFetch('/api/v1/client/rentals/landlord/properties');
            if (props.ok && props.data.properties && props.data.properties.length) roles.push('landlord');
            this.roles = roles;
            this.activeRole = roles[0] || null;
            if (this.activeRole === 'tenant') { this.tenantLeases = leases.data.leases; this.loadFaultReports(); }
            if (this.activeRole === 'landlord') { this.landlordProperties = props.data.properties; this.loadDecisions(); }
        },

        setRole(role) {
            this.activeRole = role;
            if (role === 'tenant') this.loadTenantLeases();
            if (role === 'landlord') this.loadDecisions();
        },

        async lookup() {
            this.login.error = null;
            const r = await portalFetch('/api/v1/client-auth/lookup', { method: 'POST', body: JSON.stringify({ email: this.login.email }) });
            if (!r.ok || !r.data.exists) { this.login.error = r.data?.message || 'Not found.'; return; }
            if (r.data.requires_password) {
                this.login.step = 'password';
            } else {
                await this.sendOtp('activation');
            }
        },

        async sendOtp(purpose) {
            this.login.error = null;
            await portalFetch('/api/v1/client-auth/otp/send', { method: 'POST', body: JSON.stringify({ email: this.login.email, purpose }) });
            this.login.step = 'otp-sent';
        },

        async verifyOtp() {
            this.login.error = null;
            const r = await portalFetch('/api/v1/client-auth/otp/verify', { method: 'POST', body: JSON.stringify({ email: this.login.email, code: this.login.code }) });
            if (!r.ok) { this.login.error = r.data?.message || 'Invalid code.'; return; }
            this.login.activationToken = r.data.activation_token;
            this.login.step = 'set-password';
        },

        async setPassword() {
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
        },

        async passwordLogin() {
            this.login.error = null;
            const r = await portalFetch('/api/v1/client-auth/login', { method: 'POST', body: JSON.stringify({ email: this.login.email, password: this.login.password }) });
            if (!r.ok) { this.login.error = r.data?.message || 'Invalid credentials.'; return; }
            this.session.authenticated = true;
            await this.detectRoles();
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
        async loadLeaseDetail(id) {
            const r = await portalFetch('/api/v1/client/rentals/leases/' + id);
            if (r.ok) this.leaseDetail = r.data.lease;
        },
        async loadFaultReports() {
            const r = await portalFetch('/api/v1/client/rentals/fault-reports');
            if (r.ok) this.faultReports = r.data.fault_reports;
        },
        // §14.29 — one list, two endpoints: the tenant's own lease(s) or the landlord's own properties.
        async loadJobCards() {
            const url = this.activeRole === 'landlord'
                ? '/api/v1/client/rentals/landlord/job-cards'
                : '/api/v1/client/rentals/job-cards';
            const r = await portalFetch(url);
            if (r.ok) this.jobCards = r.data.job_cards;
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
        openNotComplete(w) { this.answerError = null; this.answerForm = { workOrderId: w.id, note: '', photos: null }; },
        // §17.10.4 — confirm ("All done") or say it is NOT complete (a note of at least 5 characters, up to 10 photos).
        async answerCompletion(w, fixed) {
            this.answerError = null;
            const form = new FormData();
            form.append('fixed', fixed ? '1' : '0');
            if (!fixed) {
                const note = (this.answerForm && this.answerForm.workOrderId === w.id ? this.answerForm.note : '').trim();
                if (note.length < 5) { this.answerError = 'Please tell us what is still wrong (at least 5 characters).'; return; }
                form.append('note', note);
                if (this.answerForm && this.answerForm.photos) {
                    for (const file of this.answerForm.photos) form.append('photos[]', file);
                }
            }
            this.answerBusy = true;
            const r = await portalFetch('/api/v1/client/rentals/work-orders/' + w.id + '/completion-response', { method: 'POST', body: form });
            this.answerBusy = false;
            if (!r.ok) { this.answerError = r.data?.message || 'Could not send your answer.'; return; }
            this.answerForm = null;
            await this.loadWorkOrders();
        },
        // BUILD 3 END
        async loadDocuments() {
            const r = await portalFetch('/api/v1/client/rentals/documents');
            if (r.ok) this.documents = r.data.documents;
        },

        async startFaultReport() {
            this.faultWizard = { open: true, property: this.tenantLeases[0]?.property_id || null, faultTypeId: '', firstAid: null, title: '', description: '', photos: null, error: null };
            const propertyId = this.leaseDetail?.property?.id || this.tenantLeases[0]?.property_id;
            this.faultWizard.property = propertyId;
            if (propertyId && !this.faultTypesByProperty[propertyId]) {
                const r = await portalFetch('/api/v1/client/rentals/properties/' + propertyId + '/fault-types');
                if (r.ok) this.faultTypesByProperty[propertyId] = r.data.fault_types;
            }
        },
        selectFaultType() {
            const types = this.faultTypesByProperty[this.faultWizard.property] || [];
            const t = types.find(t => String(t.id) === String(this.faultWizard.faultTypeId));
            this.faultWizard.firstAid = t ? t.first_aid_steps : null;
            this.faultWizard.title = t ? t.name : '';
        },
        async submitFault(resolution) {
            this.faultWizard.error = null;
            const form = new FormData();
            form.append('rental_fault_type_id', this.faultWizard.faultTypeId);
            form.append('resolution', resolution);
            form.append('title', this.faultWizard.title || 'Fault reported');
            form.append('description', this.faultWizard.description || '');
            if (this.faultWizard.photos) {
                for (const file of this.faultWizard.photos) form.append('photos[]', file);
            }
            const r = await portalFetch('/api/v1/client/rentals/properties/' + this.faultWizard.property + '/fault-reports', { method: 'POST', body: form });
            if (!r.ok) { this.faultWizard.error = r.data?.message || 'Could not submit.'; return; }
            this.faultWizard.open = false;
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
        async decideFault(id, decision, note) {
            if (decision === 'approve_owner_handles' && !note) { note = this.promptNote(); if (!note) return; }
            const r = await portalFetch('/api/v1/client/rentals/landlord/fault-reports/' + id + '/decision', { method: 'POST', body: JSON.stringify({ decision, note }) });
            if (r.ok) this.loadDecisions();
            else alert(r.data?.message || 'Could not record decision.');
        },
        async decideWorkOrder(id, decision) {
            const r = await portalFetch('/api/v1/client/rentals/landlord/work-orders/' + id + '/decision', { method: 'POST', body: JSON.stringify({ decision }) });
            if (r.ok) this.loadDecisions();
            else alert(r.data?.message || 'Could not record decision.');
        },

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
        async startLandlordFaultReport(propertyId) {
            this.landlordFaultWizard = { open: true, property: propertyId, faultTypeId: '', firstAid: null, title: '', description: '', photos: null, error: null, success: null };
            if (propertyId && !this.landlordFaultTypesByProperty[propertyId]) {
                const r = await portalFetch('/api/v1/client/rentals/landlord/properties/' + propertyId + '/fault-types');
                if (r.ok) this.landlordFaultTypesByProperty[propertyId] = r.data.fault_types;
            }
        },
        selectLandlordFaultType() {
            const types = this.landlordFaultTypesByProperty[this.landlordFaultWizard.property] || [];
            const t = types.find(t => String(t.id) === String(this.landlordFaultWizard.faultTypeId));
            this.landlordFaultWizard.firstAid = t ? t.first_aid_steps : null;
            if (t && !this.landlordFaultWizard.title) this.landlordFaultWizard.title = t.name;
        },
        async submitLandlordFault() {
            this.landlordFaultWizard.error = null;
            if (!this.landlordFaultWizard.title) { this.landlordFaultWizard.error = 'Please give it a short title.'; return; }
            const form = new FormData();
            if (this.landlordFaultWizard.faultTypeId) form.append('rental_fault_type_id', this.landlordFaultWizard.faultTypeId);
            form.append('title', this.landlordFaultWizard.title);
            form.append('description', this.landlordFaultWizard.description || '');
            if (this.landlordFaultWizard.photos) {
                for (const file of this.landlordFaultWizard.photos) form.append('photos[]', file);
            }
            const r = await portalFetch('/api/v1/client/rentals/landlord/properties/' + this.landlordFaultWizard.property + '/fault-reports', { method: 'POST', body: form });
            if (!r.ok) { this.landlordFaultWizard.error = r.data?.message || 'Could not submit.'; return; }
            this.landlordFaultWizard.success = 'Request sent — your agent has been notified.';
            await this.loadLandlordFaults();
            setTimeout(() => { this.landlordFaultWizard.open = false; }, 1500);
        },
    };
}
</script>
</body>
</html>
