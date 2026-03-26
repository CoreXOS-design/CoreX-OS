<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>FICA Verification — {{ $agency->name ?? 'Home Finders Coastal' }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700" rel="stylesheet">
    @vite(['resources/css/app.css'])
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f1f5f9; margin: 0; }
        [x-cloak] { display: none !important; }

        .fica-card { background: #fff; border: 1px solid #e2e8f0; padding: 2rem; margin-bottom: 1.5rem; }
        .fica-section-title { font-size: 1.125rem; font-weight: 700; color: #0f172a; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid #0d9488; }
        .fica-subsection-title { font-size: 0.9375rem; font-weight: 700; color: #0f172a; margin: 1.25rem 0 0.75rem; padding-bottom: 0.375rem; border-bottom: 1px solid #e2e8f0; }
        .fica-label { display: block; font-weight: 600; font-size: 0.875rem; color: #334155; margin-bottom: 0.25rem; }
        .fica-label .req { color: #dc2626; }
        .fica-hint { font-size: 0.8rem; color: #94a3b8; margin-top: 0.25rem; line-height: 1.4; }
        .fica-tooltip { display: flex; align-items: flex-start; gap: 0.375rem; font-size: 0.8rem; color: #64748b; margin-top: 0.375rem; line-height: 1.5; background: #f8fafc; border: 1px solid #e2e8f0; padding: 0.5rem 0.625rem; }
        .fica-tooltip svg { flex-shrink: 0; width: 1rem; height: 1rem; color: #94a3b8; margin-top: 0.1rem; }
        .fica-input { width: 100%; padding: 0.625rem 0.75rem; border: 1px solid #cbd5e1; font-size: 0.875rem; background: #fff; transition: border-color 0.15s; box-sizing: border-box; }
        .fica-input:focus { outline: none; border-color: #0d9488; box-shadow: 0 0 0 2px rgba(13,148,136,0.15); }
        textarea.fica-input { resize: vertical; }
        .fica-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .fica-grid-full { grid-column: 1 / -1; }
        .fica-radio-group { display: flex; flex-wrap: wrap; gap: 1.5rem; margin-top: 0.25rem; }
        .fica-radio-label { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; color: #334155; cursor: pointer; }
        .fica-radio-label input[type="radio"],
        .fica-radio-label input[type="checkbox"] { accent-color: #0d9488; }
        .fica-checkbox-group { display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.25rem; }
        .fica-checkbox-label { display: flex; align-items: flex-start; gap: 0.5rem; font-size: 0.8125rem; color: #334155; cursor: pointer; line-height: 1.4; }
        .fica-checkbox-label input { margin-top: 0.15rem; flex-shrink: 0; accent-color: #0d9488; }
        .fica-btn { display: inline-block; padding: 0.75rem 2rem; background: #0f172a; color: #fff; font-weight: 600; font-size: 1rem; border: none; cursor: pointer; transition: background 0.15s; }
        .fica-btn:hover { background: #1e293b; }
        .fica-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .repeatable-card { background: #f8fafc; border: 1px solid #e2e8f0; padding: 1rem; margin-bottom: 0.75rem; position: relative; }
        .repeatable-remove { position: absolute; top: 0.5rem; right: 0.5rem; width: 1.5rem; height: 1.5rem; display: flex; align-items: center; justify-content: center; background: #fee2e2; color: #dc2626; border: none; cursor: pointer; font-size: 0.875rem; line-height: 1; }
        .repeatable-remove:hover { background: #fecaca; }
        .add-btn { display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.8125rem; color: #0d9488; background: none; border: 1px dashed #0d9488; padding: 0.375rem 0.75rem; cursor: pointer; font-weight: 600; margin-top: 0.25rem; }
        .add-btn:hover { background: #f0fdfa; }

        .upload-zone { border: 2px dashed #cbd5e1; padding: 1.25rem; text-align: center; cursor: pointer; transition: border-color 0.15s, background 0.15s; }
        .upload-zone:hover, .upload-zone.dragover { border-color: #0d9488; background: #f0fdfa; }
        .upload-item { display: flex; align-items: center; justify-content: space-between; padding: 0.5rem 0.75rem; background: #f8fafc; border: 1px solid #e2e8f0; margin-top: 0.5rem; font-size: 0.8rem; }
        .upload-item .status-ok { color: #059669; font-weight: 600; }
        .upload-item .status-err { color: #dc2626; font-weight: 600; }

        #signatureCanvas { border: 1px solid #cbd5e1; background: #fff; touch-action: none; cursor: crosshair; }

        @media (max-width: 640px) {
            .fica-card { padding: 1.25rem; }
            .fica-grid { grid-template-columns: 1fr; }
            .fica-radio-group { flex-direction: column; gap: 0.75rem; }
        }
    </style>
</head>
<body>
    <div class="max-w-3xl mx-auto px-4 py-8" x-data="ficaForm()">

        {{-- ═══════════ AGENCY HEADER ═══════════ --}}
        <div class="fica-card" style="text-align: center; border-bottom: 3px solid #0d9488;">
            @if($agency->logo_path)
                <img src="{{ asset('storage/' . $agency->logo_path) }}" alt="{{ $agency->name }}" style="max-height: 60px; margin: 0 auto 1rem;">
            @endif
            <h1 style="font-size: 1.5rem; font-weight: 700; color: #0f172a; margin: 0;">FICA Verification Form</h1>
            <p style="color: #64748b; margin: 0.5rem 0 0; font-size: 0.875rem;">Financial Intelligence Centre Act — Client Due Diligence</p>
        </div>

        {{-- Server-side errors --}}
        @if ($errors->any())
            <div class="fica-card" style="border-left: 4px solid #dc2626; background: #fef2f2;">
                <p style="font-weight: 600; color: #dc2626; margin: 0 0 0.5rem;">Please correct the following errors:</p>
                <ul style="margin: 0; padding-left: 1.25rem; color: #991b1b; font-size: 0.875rem;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('fica.submit', $token) }}" @submit.prevent="submitForm" id="ficaForm">
            @csrf

            {{-- ═══════════ SECTION 1 — ENTITY TYPE ═══════════ --}}
            <div class="fica-card">
                <h2 class="fica-section-title">1. Entity Type</h2>
                <p class="fica-hint" style="margin-top: -0.75rem; margin-bottom: 1rem;">Select the type of entity completing this FICA form. All subsequent sections adapt based on this selection.</p>
                <div style="display: grid; gap: 0.625rem;">
                    <label class="fica-radio-label"><input type="radio" name="entity_type" value="natural" required x-model="entityType"> Natural Person (Individual)</label>
                    <label class="fica-radio-label"><input type="radio" name="entity_type" value="company" x-model="entityType"> Company / Close Corporation</label>
                    <label class="fica-radio-label"><input type="radio" name="entity_type" value="trust" x-model="entityType"> Trust</label>
                    <label class="fica-radio-label"><input type="radio" name="entity_type" value="partnership" x-model="entityType"> Partnership</label>
                </div>
            </div>

            {{-- ═══════════ SECTION 2 — PERSON COMPLETING THE FORM ═══════════ --}}
            <div class="fica-card" x-show="entityType" x-cloak x-transition>
                <h2 class="fica-section-title">2. Person Completing This Form</h2>
                <p class="fica-hint" style="margin-top: -0.75rem; margin-bottom: 1rem;" x-show="entityType === 'natural'">Your personal details as the client.</p>
                <p class="fica-hint" style="margin-top: -0.75rem; margin-bottom: 1rem;" x-show="entityType !== 'natural'" x-cloak>Details of the person acting on behalf of the entity.</p>

                <div class="fica-grid">
                    <div class="fica-grid-full">
                        <label class="fica-label">Full Name <span class="req">*</span></label>
                        <input type="text" name="personal[full_name]" class="fica-input" required x-model="personal.full_name">
                    </div>
                    <div class="fica-grid-full">
                        <label class="fica-label">SA Identity Number / Foreign Passport Number <span class="req">*</span></label>
                        <input type="text" name="personal[id_number]" class="fica-input" required x-model="personal.id_number">
                        <p class="fica-hint">Your SA ID or foreign passport must be inspected and a copy will be required</p>
                    </div>
                    <div class="fica-grid-full">
                        <label class="fica-label">Are you a South African citizen / permanent resident? <span class="req">*</span></label>
                        <div class="fica-radio-group">
                            <label class="fica-radio-label"><input type="radio" name="personal[sa_citizen]" value="yes" required x-model="personal.sa_citizen"> Yes</label>
                            <label class="fica-radio-label"><input type="radio" name="personal[sa_citizen]" value="no" x-model="personal.sa_citizen"> No</label>
                        </div>
                    </div>
                    <div class="fica-grid-full">
                        <label class="fica-label">Residential Address <span class="req">*</span></label>
                        <textarea name="personal[residential_address]" class="fica-input" rows="2" required x-model="personal.residential_address"></textarea>
                        <p class="fica-hint">A document less than 2 months old proving this address will be required</p>
                    </div>
                    <div>
                        <label class="fica-label">Telephone Number <span class="req">*</span></label>
                        <input type="text" name="personal[phone]" class="fica-input" required x-model="personal.phone">
                    </div>
                    <div>
                        <label class="fica-label">Email Address <span class="req">*</span></label>
                        <input type="email" name="personal[email]" class="fica-input" required x-model="personal.email">
                    </div>
                    <div x-show="entityType === 'natural'" x-cloak>
                        <label class="fica-label">SA Income Tax Number</label>
                        <input type="text" name="personal[tax_number]" class="fica-input" x-model="personal.tax_number">
                    </div>
                </div>
            </div>

            {{-- ═══════════ SECTION 3A — COMPANY / CC ═══════════ --}}
            <div class="fica-card" x-show="entityType === 'company'" x-cloak x-transition>
                <h2 class="fica-section-title">3. Company / Close Corporation Details</h2>

                <div class="fica-grid">
                    <div>
                        <label class="fica-label">Company / CC Name <span class="req">*</span></label>
                        <input type="text" name="entity[company_name]" class="fica-input" x-model="entity.company_name" :required="entityType === 'company'">
                    </div>
                    <div>
                        <label class="fica-label">Registration Number <span class="req">*</span></label>
                        <input type="text" name="entity[company_reg_number]" class="fica-input" x-model="entity.company_reg_number" :required="entityType === 'company'">
                        <p class="fica-hint">Documentary proof of existence will be required unless listed on a stock exchange</p>
                    </div>
                    <div class="fica-grid-full">
                        <label class="fica-label">Does the company have a presence in South Africa? If yes, provide details <span class="req">*</span></label>
                        <textarea name="entity[company_sa_presence]" class="fica-input" rows="2" x-model="entity.company_sa_presence" :required="entityType === 'company'"></textarea>
                    </div>
                    <div>
                        <label class="fica-label">Is the company listed on a stock exchange? If so, which?</label>
                        <input type="text" name="entity[company_stock_exchange]" class="fica-input" x-model="entity.company_stock_exchange">
                    </div>
                    <div>
                        <label class="fica-label">SARS Income Tax Number</label>
                        <input type="text" name="entity[company_tax_number]" class="fica-input" x-model="entity.company_tax_number">
                    </div>
                    <div>
                        <label class="fica-label">VAT Registration Number</label>
                        <input type="text" name="entity[company_vat_number]" class="fica-input" x-model="entity.company_vat_number">
                    </div>
                    <div class="fica-grid-full">
                        <label class="fica-label">Registered Address <span class="req">*</span></label>
                        <textarea name="entity[company_address]" class="fica-input" rows="2" x-model="entity.company_address" :required="entityType === 'company'"></textarea>
                    </div>
                    <div class="fica-grid-full">
                        <label class="fica-label">Source of authority to act on behalf of the company <span class="req">*</span></label>
                        <textarea name="entity[company_authority_source]" class="fica-input" rows="2" x-model="entity.company_authority_source" :required="entityType === 'company'"></textarea>
                        <div class="fica-tooltip"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg><span>If you're acting on behalf of someone else, explain your legal authority — e.g. power of attorney, authorisation letter, trust resolution. A copy will be required.</span></div>
                    </div>
                    <div class="fica-grid-full">
                        <label class="fica-label">Describe the company's business — industry, products/services <span class="req">*</span></label>
                        <textarea name="entity[company_business_description]" class="fica-input" rows="2" x-model="entity.company_business_description" :required="entityType === 'company'"></textarea>
                    </div>
                    <div class="fica-grid-full">
                        <label class="fica-label">Describe the ownership and control structure <span class="req">*</span></label>
                        <textarea name="entity[company_ownership_structure]" class="fica-input" rows="2" x-model="entity.company_ownership_structure" :required="entityType === 'company'"></textarea>
                        <p class="fica-hint">Is the company part of a simple or complex ownership structure?</p>
                    </div>
                </div>

                {{-- Beneficial Owners --}}
                <h3 class="fica-subsection-title">Beneficial Owners</h3>
                <p class="fica-hint" style="margin-bottom: 0.75rem;">Who are the ultimate beneficial owners? Choose the most suitable method of identifying them.</p>
                <div class="fica-tooltip" style="margin-bottom: 1rem;"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg><span>List all natural persons who individually or collectively own 5% or more of the company's shares or close corporation's members' interests, or who exercise control over the company.</span></div>

                <div style="margin-bottom: 1rem;">
                    <label class="fica-label">Method of Identification <span class="req">*</span></label>
                    <div class="fica-checkbox-group" style="margin-top: 0.5rem;">
                        <label class="fica-radio-label"><input type="radio" name="entity[beneficial_owner_method]" value="method_1" x-model="entity.beneficial_owner_method" :required="entityType === 'company'"> Method 1: Natural persons who individually or collectively own 5%+ of shares/interests</label>
                        <label class="fica-radio-label"><input type="radio" name="entity[beneficial_owner_method]" value="method_2" x-model="entity.beneficial_owner_method"> Method 2: Natural persons who individually or collectively control the company</label>
                        <label class="fica-radio-label"><input type="radio" name="entity[beneficial_owner_method]" value="method_3" x-model="entity.beneficial_owner_method"> Method 3: Executive managers of the company</label>
                    </div>
                </div>

                <template x-for="(bo, idx) in beneficialOwners" :key="idx">
                    <div class="repeatable-card">
                        <button type="button" @click="beneficialOwners.splice(idx, 1)" x-show="beneficialOwners.length > 1" class="repeatable-remove">&times;</button>
                        <div class="fica-grid">
                            <div>
                                <label class="fica-label">Full Name <span class="req">*</span></label>
                                <input type="text" :name="'entity[beneficial_owners]['+idx+'][name]'" class="fica-input" x-model="bo.name" :required="entityType === 'company'">
                            </div>
                            <div>
                                <label class="fica-label">SA ID / Passport <span class="req">*</span></label>
                                <input type="text" :name="'entity[beneficial_owners]['+idx+'][id_number]'" class="fica-input" x-model="bo.id_number" :required="entityType === 'company'">
                            </div>
                            <div class="fica-grid-full">
                                <label class="fica-label">Residential Address <span class="req">*</span></label>
                                <textarea :name="'entity[beneficial_owners]['+idx+'][address]'" class="fica-input" rows="2" x-model="bo.address" :required="entityType === 'company'"></textarea>
                            </div>
                            <div>
                                <label class="fica-label">Telephone</label>
                                <input type="text" :name="'entity[beneficial_owners]['+idx+'][phone]'" class="fica-input" x-model="bo.phone">
                            </div>
                            <div>
                                <label class="fica-label">Email</label>
                                <input type="text" :name="'entity[beneficial_owners]['+idx+'][email]'" class="fica-input" x-model="bo.email">
                            </div>
                        </div>
                    </div>
                </template>
                <p class="fica-hint" style="margin-bottom: 0.5rem;">ID copy and proof of address required for each beneficial owner</p>
                <button type="button" @click="beneficialOwners.push({name:'',id_number:'',address:'',phone:'',email:''})" class="add-btn">+ Add another beneficial owner</button>
            </div>

            {{-- ═══════════ SECTION 3B — TRUST ═══════════ --}}
            <div class="fica-card" x-show="entityType === 'trust'" x-cloak x-transition>
                <h2 class="fica-section-title">3. Trust Details</h2>

                <div class="fica-grid">
                    <div>
                        <label class="fica-label">Trust Name <span class="req">*</span></label>
                        <input type="text" name="entity[trust_name]" class="fica-input" x-model="entity.trust_name" :required="entityType === 'trust'">
                    </div>
                    <div>
                        <label class="fica-label">Master's Reference Number <span class="req">*</span></label>
                        <input type="text" name="entity[trust_master_ref]" class="fica-input" x-model="entity.trust_master_ref" :required="entityType === 'trust'">
                    </div>
                    <div class="fica-grid-full">
                        <label class="fica-label">Does the trust have a presence in South Africa? If yes, provide details <span class="req">*</span></label>
                        <textarea name="entity[trust_sa_presence]" class="fica-input" rows="2" x-model="entity.trust_sa_presence" :required="entityType === 'trust'"></textarea>
                    </div>
                    <div>
                        <label class="fica-label">Which Master of the High Court administers the trust? <span class="req">*</span></label>
                        <input type="text" name="entity[trust_master_court]" class="fica-input" x-model="entity.trust_master_court" :required="entityType === 'trust'">
                    </div>
                    <div>
                        <label class="fica-label">SARS Income Tax Number (if registered)</label>
                        <input type="text" name="entity[trust_tax_number]" class="fica-input" x-model="entity.trust_tax_number">
                    </div>
                    <div>
                        <label class="fica-label">VAT Number (if registered)</label>
                        <input type="text" name="entity[trust_vat_number]" class="fica-input" x-model="entity.trust_vat_number">
                    </div>
                    <div class="fica-grid-full">
                        <label class="fica-label">Source of authority to act on behalf of the trust <span class="req">*</span></label>
                        <textarea name="entity[trust_authority_source]" class="fica-input" rows="2" x-model="entity.trust_authority_source" :required="entityType === 'trust'"></textarea>
                        <div class="fica-tooltip"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg><span>If you're acting on behalf of someone else, explain your legal authority — e.g. power of attorney, authorisation letter, trust resolution. A copy will be required.</span></div>
                    </div>
                    <div class="fica-grid-full">
                        <label class="fica-label">Describe the trust's purpose or business <span class="req">*</span></label>
                        <textarea name="entity[trust_purpose]" class="fica-input" rows="2" x-model="entity.trust_purpose" :required="entityType === 'trust'"></textarea>
                    </div>
                </div>

                {{-- Donor --}}
                <h3 class="fica-subsection-title">Donor (Person who created the Trust)</h3>
                <div class="fica-grid">
                    <div class="fica-grid-full">
                        <label class="fica-label">Full Name of the Donor <span class="req">*</span></label>
                        <input type="text" name="entity[donor_name]" class="fica-input" x-model="entity.donor_name" :required="entityType === 'trust'">
                    </div>
                    <div>
                        <label class="fica-label">SA ID / Passport Number <span class="req">*</span></label>
                        <input type="text" name="entity[donor_id_number]" class="fica-input" x-model="entity.donor_id_number" :required="entityType === 'trust'">
                    </div>
                    <div class="fica-grid-full">
                        <label class="fica-label">Residential Address <span class="req">*</span></label>
                        <textarea name="entity[donor_address]" class="fica-input" rows="2" x-model="entity.donor_address" :required="entityType === 'trust'"></textarea>
                        <p class="fica-hint">Proof of address less than 2 months old required for the Donor</p>
                    </div>
                </div>

                {{-- Trustees --}}
                <h3 class="fica-subsection-title">Trustees</h3>
                <template x-for="(tr, idx) in trustees" :key="idx">
                    <div class="repeatable-card">
                        <button type="button" @click="trustees.splice(idx, 1)" x-show="trustees.length > 1" class="repeatable-remove">&times;</button>
                        <div class="fica-grid">
                            <div>
                                <label class="fica-label">Full Name <span class="req">*</span></label>
                                <input type="text" :name="'entity[trustees]['+idx+'][name]'" class="fica-input" x-model="tr.name" :required="entityType === 'trust'">
                            </div>
                            <div>
                                <label class="fica-label">SA ID / Passport <span class="req">*</span></label>
                                <input type="text" :name="'entity[trustees]['+idx+'][id_number]'" class="fica-input" x-model="tr.id_number" :required="entityType === 'trust'">
                            </div>
                            <div class="fica-grid-full">
                                <label class="fica-label">Residential Address <span class="req">*</span></label>
                                <textarea :name="'entity[trustees]['+idx+'][address]'" class="fica-input" rows="2" x-model="tr.address" :required="entityType === 'trust'"></textarea>
                            </div>
                        </div>
                    </div>
                </template>
                <p class="fica-hint" style="margin-bottom: 0.5rem;">ID copy and proof of address required for each Trustee</p>
                <button type="button" @click="trustees.push({name:'',id_number:'',address:''})" class="add-btn">+ Add another trustee</button>

                {{-- Beneficiaries --}}
                <h3 class="fica-subsection-title">Beneficiaries</h3>
                <div style="margin-bottom: 1rem;">
                    <label class="fica-label">Are there named beneficiaries? <span class="req">*</span></label>
                    <div class="fica-radio-group">
                        <label class="fica-radio-label"><input type="radio" name="entity[has_named_beneficiaries]" value="yes" x-model="entity.has_named_beneficiaries" :required="entityType === 'trust'"> Yes</label>
                        <label class="fica-radio-label"><input type="radio" name="entity[has_named_beneficiaries]" value="no" x-model="entity.has_named_beneficiaries"> No</label>
                    </div>
                </div>
                <div x-show="entity.has_named_beneficiaries === 'yes'" x-cloak>
                    <template x-for="(bn, idx) in beneficiaries" :key="idx">
                        <div class="repeatable-card">
                            <button type="button" @click="beneficiaries.splice(idx, 1)" x-show="beneficiaries.length > 1" class="repeatable-remove">&times;</button>
                            <div class="fica-grid">
                                <div>
                                    <label class="fica-label">Full Name <span class="req">*</span></label>
                                    <input type="text" :name="'entity[beneficiaries]['+idx+'][name]'" class="fica-input" x-model="bn.name">
                                </div>
                                <div>
                                    <label class="fica-label">SA ID / Passport <span class="req">*</span></label>
                                    <input type="text" :name="'entity[beneficiaries]['+idx+'][id_number]'" class="fica-input" x-model="bn.id_number">
                                </div>
                                <div class="fica-grid-full">
                                    <label class="fica-label">Residential Address <span class="req">*</span></label>
                                    <textarea :name="'entity[beneficiaries]['+idx+'][address]'" class="fica-input" rows="2" x-model="bn.address"></textarea>
                                </div>
                            </div>
                        </div>
                    </template>
                    <p class="fica-hint" style="margin-bottom: 0.5rem;">ID copy and proof of address required for each beneficiary</p>
                    <button type="button" @click="beneficiaries.push({name:'',id_number:'',address:''})" class="add-btn">+ Add another beneficiary</button>
                </div>
                <div x-show="entity.has_named_beneficiaries === 'no'" x-cloak style="margin-top: 0.5rem;">
                    <label class="fica-label">How are the beneficiaries determined? <span class="req">*</span></label>
                    <textarea name="entity[beneficiary_determination]" class="fica-input" rows="2" x-model="entity.beneficiary_determination"></textarea>
                </div>
            </div>

            {{-- ═══════════ SECTION 3C — PARTNERSHIP ═══════════ --}}
            <div class="fica-card" x-show="entityType === 'partnership'" x-cloak x-transition>
                <h2 class="fica-section-title">3. Partnership Details</h2>

                <div class="fica-grid">
                    <div class="fica-grid-full">
                        <label class="fica-label">Partnership Identifying Name / Trading Name <span class="req">*</span></label>
                        <input type="text" name="entity[partnership_name]" class="fica-input" x-model="entity.partnership_name" :required="entityType === 'partnership'">
                    </div>
                    <div class="fica-grid-full">
                        <label class="fica-label">Does the partnership have a presence in South Africa? If yes, provide details <span class="req">*</span></label>
                        <textarea name="entity[partnership_sa_presence]" class="fica-input" rows="2" x-model="entity.partnership_sa_presence" :required="entityType === 'partnership'"></textarea>
                    </div>
                    <div class="fica-grid-full">
                        <label class="fica-label">Source of authority to act on behalf of the partnership <span class="req">*</span></label>
                        <textarea name="entity[partnership_authority_source]" class="fica-input" rows="2" x-model="entity.partnership_authority_source" :required="entityType === 'partnership'"></textarea>
                        <div class="fica-tooltip"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg><span>If you're acting on behalf of someone else, explain your legal authority — e.g. power of attorney, authorisation letter, trust resolution. A copy will be required.</span></div>
                    </div>
                    <div class="fica-grid-full">
                        <label class="fica-label">Describe the partnership's business — industry, products/services <span class="req">*</span></label>
                        <textarea name="entity[partnership_business_description]" class="fica-input" rows="2" x-model="entity.partnership_business_description" :required="entityType === 'partnership'"></textarea>
                    </div>
                    <div class="fica-grid-full">
                        <label class="fica-label">Is this a professional partnership? <span class="req">*</span></label>
                        <div class="fica-radio-group">
                            <label class="fica-radio-label"><input type="radio" name="entity[is_professional_partnership]" value="yes" x-model="entity.is_professional_partnership" :required="entityType === 'partnership'"> Yes</label>
                            <label class="fica-radio-label"><input type="radio" name="entity[is_professional_partnership]" value="no" x-model="entity.is_professional_partnership"> No</label>
                        </div>
                    </div>
                    <div class="fica-grid-full" x-show="entity.is_professional_partnership === 'yes'" x-cloak>
                        <label class="fica-label">Who are the executive partners controlling day-to-day operations? <span class="req">*</span></label>
                        <textarea name="entity[executive_partners]" class="fica-input" rows="2" x-model="entity.executive_partners"></textarea>
                    </div>
                    <div class="fica-grid-full">
                        <label class="fica-label">Ownership and control structure <span class="req">*</span></label>
                        <textarea name="entity[partnership_ownership_structure]" class="fica-input" rows="2" x-model="entity.partnership_ownership_structure" :required="entityType === 'partnership'"></textarea>
                        <p class="fica-hint">Are partners all natural persons, companies, or a mixture?</p>
                    </div>
                    <div>
                        <label class="fica-label">SARS Income Tax Number (if registered)</label>
                        <input type="text" name="entity[partnership_tax_number]" class="fica-input" x-model="entity.partnership_tax_number">
                    </div>
                    <div>
                        <label class="fica-label">VAT Number (if registered)</label>
                        <input type="text" name="entity[partnership_vat_number]" class="fica-input" x-model="entity.partnership_vat_number">
                    </div>
                </div>

                {{-- Partners --}}
                <h3 class="fica-subsection-title">Partners</h3>
                <p class="fica-hint" style="margin-bottom: 0.75rem;">Include silent partners and partners en commandite</p>
                <template x-for="(pt, idx) in partners" :key="idx">
                    <div class="repeatable-card">
                        <button type="button" @click="partners.splice(idx, 1)" x-show="partners.length > 1" class="repeatable-remove">&times;</button>
                        <div class="fica-grid">
                            <div>
                                <label class="fica-label">Full Name <span class="req">*</span></label>
                                <input type="text" :name="'entity[partners]['+idx+'][name]'" class="fica-input" x-model="pt.name" :required="entityType === 'partnership'">
                            </div>
                            <div>
                                <label class="fica-label">SA ID / Passport <span class="req">*</span></label>
                                <input type="text" :name="'entity[partners]['+idx+'][id_number]'" class="fica-input" x-model="pt.id_number" :required="entityType === 'partnership'">
                            </div>
                            <div class="fica-grid-full">
                                <label class="fica-label">Residential Address <span class="req">*</span></label>
                                <textarea :name="'entity[partners]['+idx+'][address]'" class="fica-input" rows="2" x-model="pt.address" :required="entityType === 'partnership'"></textarea>
                            </div>
                            <div>
                                <label class="fica-label">Telephone</label>
                                <input type="text" :name="'entity[partners]['+idx+'][phone]'" class="fica-input" x-model="pt.phone">
                            </div>
                            <div>
                                <label class="fica-label">Email</label>
                                <input type="text" :name="'entity[partners]['+idx+'][email]'" class="fica-input" x-model="pt.email">
                            </div>
                        </div>
                    </div>
                </template>
                <p class="fica-hint" style="margin-bottom: 0.5rem;">ID copy and proof of address required for each partner</p>
                <button type="button" @click="partners.push({name:'',id_number:'',address:'',phone:'',email:''})" class="add-btn">+ Add another partner</button>
            </div>

            {{-- ═══════════ SECTION 4 — PRINCIPAL (Natural Person ONLY) ═══════════ --}}
            <div class="fica-card" x-show="entityType === 'natural'" x-cloak x-transition>
                <h2 class="fica-section-title">4. Principal</h2>
                <div style="margin-bottom: 1rem;">
                    <label class="fica-label">Are you dealing with us on behalf of another person? <span class="req">*</span></label>
                    <div class="fica-radio-group">
                        <label class="fica-radio-label"><input type="radio" name="principal[acting_on_behalf]" value="yes" x-model="principal.acting_on_behalf" :required="entityType === 'natural'"> Yes</label>
                        <label class="fica-radio-label"><input type="radio" name="principal[acting_on_behalf]" value="no" x-model="principal.acting_on_behalf"> No</label>
                    </div>
                </div>
                <div x-show="principal.acting_on_behalf === 'yes'" x-cloak>
                    <div class="fica-grid">
                        <div class="fica-grid-full">
                            <label class="fica-label">Principal's Full Name <span class="req">*</span></label>
                            <input type="text" name="principal[full_name]" class="fica-input" x-model="principal.full_name">
                        </div>
                        <div class="fica-grid-full">
                            <label class="fica-label">Principal's SA ID / Passport Number <span class="req">*</span></label>
                            <input type="text" name="principal[id_number]" class="fica-input" x-model="principal.id_number">
                            <p class="fica-hint">Their ID must be inspected. If original not available, certified copy required</p>
                        </div>
                        <div class="fica-grid-full">
                            <label class="fica-label">Is the Principal a South African citizen / permanent resident? <span class="req">*</span></label>
                            <div class="fica-radio-group">
                                <label class="fica-radio-label"><input type="radio" name="principal[sa_citizen]" value="yes" x-model="principal.sa_citizen"> Yes</label>
                                <label class="fica-radio-label"><input type="radio" name="principal[sa_citizen]" value="no" x-model="principal.sa_citizen"> No</label>
                            </div>
                        </div>
                        <div class="fica-grid-full">
                            <label class="fica-label">Principal's Residential Address <span class="req">*</span></label>
                            <textarea name="principal[residential_address]" class="fica-input" rows="2" x-model="principal.residential_address"></textarea>
                            <p class="fica-hint">Proof of address less than 3 months old required</p>
                        </div>
                        <div>
                            <label class="fica-label">Principal's Telephone <span class="req">*</span></label>
                            <input type="text" name="principal[phone]" class="fica-input" x-model="principal.phone">
                        </div>
                        <div>
                            <label class="fica-label">Principal's Email <span class="req">*</span></label>
                            <input type="email" name="principal[email]" class="fica-input" x-model="principal.email">
                        </div>
                        <div>
                            <label class="fica-label">Principal's SA Income Tax Number</label>
                            <input type="text" name="principal[tax_number]" class="fica-input" x-model="principal.tax_number">
                        </div>
                        <div class="fica-grid-full">
                            <label class="fica-label">Source of your authority to act on their behalf <span class="req">*</span></label>
                            <textarea name="principal[authority_source]" class="fica-input" rows="2" x-model="principal.authority_source"></textarea>
                            <div class="fica-tooltip"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg><span>If you're acting on behalf of someone else, explain your legal authority — e.g. power of attorney, authorisation letter, trust resolution. A copy will be required.</span></div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══════════ SECTION 5 — REPRESENTATIVE (Natural Person ONLY) ═══════════ --}}
            <div class="fica-card" x-show="entityType === 'natural'" x-cloak x-transition>
                <h2 class="fica-section-title">5. Representative</h2>
                <div style="margin-bottom: 1rem;">
                    <label class="fica-label">Will someone else deal with us on your behalf going forward? <span class="req">*</span></label>
                    <div class="fica-radio-group">
                        <label class="fica-radio-label"><input type="radio" name="representative[has_representative]" value="yes" x-model="representative.has_representative" :required="entityType === 'natural'"> Yes</label>
                        <label class="fica-radio-label"><input type="radio" name="representative[has_representative]" value="no" x-model="representative.has_representative"> No</label>
                    </div>
                </div>
                <div x-show="representative.has_representative === 'yes'" x-cloak>
                    <div class="fica-grid">
                        <div class="fica-grid-full">
                            <label class="fica-label">Representative's Full Name <span class="req">*</span></label>
                            <input type="text" name="representative[full_name]" class="fica-input" x-model="representative.full_name">
                        </div>
                        <div class="fica-grid-full">
                            <label class="fica-label">Representative's SA ID / Passport Number <span class="req">*</span></label>
                            <input type="text" name="representative[id_number]" class="fica-input" x-model="representative.id_number">
                        </div>
                        <div class="fica-grid-full">
                            <label class="fica-label">Source of representative's authority <span class="req">*</span></label>
                            <textarea name="representative[authority_source]" class="fica-input" rows="2" x-model="representative.authority_source"></textarea>
                            <p class="fica-hint">If in writing, a copy will be required</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══════════ SECTION 6 — SERVICE & PAYMENT ═══════════ --}}
            <div class="fica-card" x-show="entityType" x-cloak x-transition>
                <h2 class="fica-section-title"><span x-text="entityType === 'natural' ? '6' : '4'"></span>. Service & Payment</h2>

                <div style="margin-bottom: 1.25rem;">
                    <label class="fica-label">Purpose of Transaction <span class="req">*</span></label>
                    <div style="display: grid; gap: 0.5rem; margin-top: 0.25rem;">
                        <label class="fica-radio-label"><input type="radio" name="service[transaction_purpose]" value="sell" required x-model="service.transaction_purpose"> I/We wish to sell a property</label>
                        <label class="fica-radio-label"><input type="radio" name="service[transaction_purpose]" value="purchase" x-model="service.transaction_purpose"> I/We wish to purchase a property</label>
                        <label class="fica-radio-label"><input type="radio" name="service[transaction_purpose]" value="let_out" x-model="service.transaction_purpose"> I/We wish to let out a property</label>
                        <label class="fica-radio-label"><input type="radio" name="service[transaction_purpose]" value="rent" x-model="service.transaction_purpose"> I/We wish to rent a property</label>
                        <label class="fica-radio-label"><input type="radio" name="service[transaction_purpose]" value="other" x-model="service.transaction_purpose"> Other</label>
                    </div>
                    <div x-show="service.transaction_purpose === 'other'" x-cloak style="margin-top: 0.5rem;">
                        <input type="text" name="service[purpose_other]" class="fica-input" placeholder="Please specify..." x-model="service.purpose_other">
                    </div>
                </div>

                <div style="margin-bottom: 1.25rem;">
                    <label class="fica-label">How will payments be financed? <span class="req">*</span></label>
                    <textarea name="service[payment_method]" class="fica-input" rows="2" required x-model="service.payment_method"></textarea>
                    <div class="fica-tooltip"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg><span>This refers to how the property transaction is funded (mortgage bond, cash purchase, savings), not payments to the agency. All sale proceeds flow through the transferring attorney.</span></div>
                </div>

                <div>
                    <label class="fica-label">Will any payment involve R50,000 or more in cash? <span class="req">*</span></label>
                    <div class="fica-radio-group">
                        <label class="fica-radio-label"><input type="radio" name="service[cash_over_50k]" value="yes" required x-model="service.cash_over_50k"> Yes</label>
                        <label class="fica-radio-label"><input type="radio" name="service[cash_over_50k]" value="no" x-model="service.cash_over_50k"> No</label>
                    </div>
                    <div class="fica-tooltip"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg><span>Cash means physical paper money, coins, or traveller's cheques only. EFT/bank transfers are NOT cash. Home Finders Coastal does not accept physical cash — if unsure, answer 'No'.</span></div>
                </div>
            </div>

            {{-- ═══════════ SECTION 7 — POLITICALLY EXPOSED PERSON ═══════════ --}}
            <div class="fica-card" x-show="entityType" x-cloak x-transition>
                <h2 class="fica-section-title"><span x-text="entityType === 'natural' ? '7' : '5'"></span>. Politically Exposed Person (PEP)</h2>

                {{-- Foreign PEP --}}
                <h3 class="fica-subsection-title">Foreign PEP</h3>
                <p class="fica-hint" style="margin-bottom: 0.75rem;">Do you now occupy, or have you in the past 12 months occupied, any of the following positions in any country OTHER than South Africa?</p>
                <div class="fica-checkbox-group" style="margin-bottom: 1.25rem;">
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[foreign_pep][]" value="head_of_state" x-model="pep.foreign_pep"> Head of state</label>
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[foreign_pep][]" value="royal_family" x-model="pep.foreign_pep"> Member of the royal family</label>
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[foreign_pep][]" value="cabinet_member" x-model="pep.foreign_pep"> Cabinet member</label>
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[foreign_pep][]" value="political_party" x-model="pep.foreign_pep"> Senior member of a political party</label>
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[foreign_pep][]" value="judicial_officer" x-model="pep.foreign_pep"> Senior judicial officer</label>
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[foreign_pep][]" value="soe_executive" x-model="pep.foreign_pep"> Senior executive of a state-owned entity</label>
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[foreign_pep][]" value="military" x-model="pep.foreign_pep"> High rank in the military</label>
                </div>

                {{-- Domestic PEP --}}
                <h3 class="fica-subsection-title">Domestic PEP</h3>
                <p class="fica-hint" style="margin-bottom: 0.75rem;">Do you now occupy, or have you in the past 12 months occupied, any of the following positions in South Africa?</p>
                <div class="fica-checkbox-group" style="margin-bottom: 1.25rem;">
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[domestic_pep][]" value="president" x-model="pep.domestic_pep"> President or Deputy President of South Africa</label>
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[domestic_pep][]" value="cabinet_minister" x-model="pep.domestic_pep"> Cabinet Minister or Deputy Minister</label>
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[domestic_pep][]" value="premier" x-model="pep.domestic_pep"> Premier of a province</label>
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[domestic_pep][]" value="mec" x-model="pep.domestic_pep"> MEC of a province</label>
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[domestic_pep][]" value="mayor" x-model="pep.domestic_pep"> Mayor of a municipality</label>
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[domestic_pep][]" value="political_leader" x-model="pep.domestic_pep"> Leader of a political party</label>
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[domestic_pep][]" value="royal_family" x-model="pep.domestic_pep"> Member of a royal family</label>
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[domestic_pep][]" value="traditional_leader" x-model="pep.domestic_pep"> Senior traditional leader</label>
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[domestic_pep][]" value="dept_head" x-model="pep.domestic_pep"> Head, accounting officer or CFO of a national or provincial department</label>
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[domestic_pep][]" value="municipal_manager" x-model="pep.domestic_pep"> Manager or CFO of a municipality</label>
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[domestic_pep][]" value="public_entity" x-model="pep.domestic_pep"> Chairperson, CEO, accounting authority, CFO or chief investment officer of a public entity</label>
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[domestic_pep][]" value="judge" x-model="pep.domestic_pep"> Judge</label>
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[domestic_pep][]" value="ambassador" x-model="pep.domestic_pep"> Ambassador, high commissioner or other senior representative of a foreign country based in SA</label>
                    <label class="fica-checkbox-label"><input type="checkbox" name="pep[domestic_pep][]" value="govt_business" x-model="pep.domestic_pep"> Chairperson of board, chairperson of audit committee, executive officer or CFO of a company doing significant business with government</label>
                </div>

                {{-- Family / Associate --}}
                <h3 class="fica-subsection-title">Family Member or Close Associate</h3>
                <div style="margin-bottom: 1rem;">
                    <label class="fica-label">Are you a family member or close associate of any person in the above categories? <span class="req">*</span></label>
                    <div class="fica-radio-group">
                        <label class="fica-radio-label"><input type="radio" name="pep[is_family_associate]" value="yes" required x-model="pep.is_family_associate"> Yes</label>
                        <label class="fica-radio-label"><input type="radio" name="pep[is_family_associate]" value="no" x-model="pep.is_family_associate"> No</label>
                    </div>
                </div>
                <div x-show="pep.is_family_associate === 'yes'" x-cloak>
                    <label class="fica-label">Name the person and indicate their position <span class="req">*</span></label>
                    <textarea name="pep[family_associate_details]" class="fica-input" rows="2" x-model="pep.family_associate_details"></textarea>
                </div>

                {{-- Source of Wealth --}}
                <div x-show="pep.foreign_pep.length > 0 || pep.domestic_pep.length > 0 || pep.is_family_associate === 'yes'" x-cloak style="margin-top: 1.25rem;">
                    <h3 class="fica-subsection-title">Source of Wealth</h3>
                    <label class="fica-label">Please indicate your source of wealth <span class="req">*</span></label>
                    <textarea name="pep[source_of_wealth]" class="fica-input" rows="3" x-model="pep.source_of_wealth"></textarea>
                    <div class="fica-tooltip"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg><span>Describe how you accumulated your wealth — e.g. employment income, business profits, inheritance, investments.</span></div>
                </div>
            </div>

            {{-- ═══════════ SECTION 8 — DOCUMENT UPLOADS ═══════════ --}}
            <div class="fica-card" x-show="entityType" x-cloak x-transition>
                <h2 class="fica-section-title"><span x-text="entityType === 'natural' ? '8' : '6'"></span>. Document Uploads</h2>
                <p class="fica-hint" style="margin-top: -0.75rem; margin-bottom: 1rem;">PDF, JPG, PNG, or HEIC. Maximum 10MB per file.</p>

                <template x-for="docType in computedUploadTypes" :key="docType.key">
                    <div style="margin-bottom: 1.25rem;" x-show="docType.show">
                        <label class="fica-label"><span x-text="docType.label"></span> <span class="req" x-show="docType.required">*</span></label>
                        <template x-if="docType.tooltip">
                            <div class="fica-tooltip"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg><span x-text="docType.tooltip"></span></div>
                        </template>
                        <div class="upload-zone"
                             @click="document.getElementById('fileInput_' + docType.key).click()"
                             @dragover.prevent="$event.currentTarget.classList.add('dragover')"
                             @dragleave.prevent="$event.currentTarget.classList.remove('dragover')"
                             @drop.prevent="handleDrop($event, docType.key); $event.currentTarget.classList.remove('dragover')"
                             x-show="!uploads[docType.key]">
                            <p style="margin: 0; color: #64748b; font-size: 0.875rem;">Click or drag file here</p>
                        </div>
                        <input type="file" :id="'fileInput_' + docType.key" accept=".pdf,.jpg,.jpeg,.png,.heic" style="display:none;"
                               @change="handleFileSelect($event, docType.key)">
                        <div x-show="uploads[docType.key]" class="upload-item">
                            <span x-text="uploads[docType.key]?.name || ''"></span>
                            <span>
                                <span x-show="uploads[docType.key]?.status === 'uploading'" style="color: #d97706;">Uploading...</span>
                                <span x-show="uploads[docType.key]?.status === 'done'" class="status-ok">Uploaded</span>
                                <span x-show="uploads[docType.key]?.status === 'error'" class="status-err">Failed</span>
                                <button type="button" @click="removeUpload(docType.key)" style="margin-left: 0.5rem; color: #dc2626; background: none; border: none; cursor: pointer; font-size: 1rem;">&times;</button>
                            </span>
                        </div>
                    </div>
                </template>
            </div>

            {{-- ═══════════ SECTION 9 — DECLARATION & SIGNATURE ═══════════ --}}
            <div class="fica-card" x-show="entityType" x-cloak x-transition>
                <h2 class="fica-section-title"><span x-text="entityType === 'natural' ? '9' : '7'"></span>. Declaration & Signature</h2>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 1rem; margin-bottom: 1.5rem; font-size: 0.875rem; color: #334155; line-height: 1.7;">
                    I hereby declare that the information provided above is true, correct and complete. I understand that providing false or misleading information may constitute a criminal offence under the Financial Intelligence Centre Act (Act 38 of 2001) as amended.
                </div>

                <div class="fica-grid" style="margin-bottom: 1.25rem;">
                    <div>
                        <label class="fica-label">Signed at (location) <span class="req">*</span></label>
                        <input type="text" name="declaration[signed_at_location]" class="fica-input" required x-model="declaration.signed_at_location" placeholder="e.g. Durban, South Africa">
                    </div>
                    <div>
                        <label class="fica-label">Date</label>
                        <input type="text" class="fica-input" value="{{ now()->format('d/m/Y') }}" readonly style="background: #f8fafc;">
                    </div>
                </div>

                <label class="fica-label">Your Signature <span class="req">*</span></label>
                <div style="position: relative; margin-bottom: 0.5rem;">
                    <canvas id="signatureCanvas" x-ref="signatureCanvas" width="560" height="180" style="width: 100%; max-width: 560px;"></canvas>
                    <button type="button" @click="clearSignature()" style="position: absolute; top: 0.5rem; right: 0.5rem; font-size: 0.75rem; color: #64748b; background: #fff; border: 1px solid #e2e8f0; padding: 0.25rem 0.5rem; cursor: pointer;">Clear</button>
                </div>
                <input type="hidden" name="signature_data" x-model="signatureDataUrl">

                <div style="margin-top: 2rem; text-align: center;">
                    <button type="submit" class="fica-btn" :disabled="submitting">
                        <span x-show="!submitting">Submit FICA Declaration</span>
                        <span x-show="submitting" x-cloak>Submitting...</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    @vite(['resources/js/app.js'])
    <script>
    function ficaForm() {
        const addressTooltip = 'A utility bill, bank statement, or municipal rates account dated within the last 2 months. An emailed statement is acceptable — we will need to see the original email and attachment.';
        return {
            submitting: false,
            signatureDataUrl: '',
            signaturePad: null,
            uploads: {},

            entityType: '',

            personal: {
                full_name: @json($contact->full_name ?? ''),
                id_number: @json($contact->id_number ?? ''),
                sa_citizen: '',
                residential_address: @json($contact->address ?? ''),
                phone: @json($contact->phone ?? ''),
                email: @json($contact->email ?? ''),
                tax_number: '',
            },

            entity: {
                company_name: '', company_reg_number: '', company_sa_presence: '',
                company_stock_exchange: '', company_tax_number: '', company_vat_number: '',
                company_address: '', company_authority_source: '',
                company_business_description: '', company_ownership_structure: '',
                beneficial_owner_method: '',
                trust_name: '', trust_master_ref: '', trust_sa_presence: '',
                trust_master_court: '', trust_tax_number: '', trust_vat_number: '',
                trust_authority_source: '', trust_purpose: '',
                donor_name: '', donor_id_number: '', donor_address: '',
                has_named_beneficiaries: '', beneficiary_determination: '',
                partnership_name: '', partnership_sa_presence: '',
                partnership_authority_source: '', partnership_business_description: '',
                is_professional_partnership: '', executive_partners: '',
                partnership_ownership_structure: '',
                partnership_tax_number: '', partnership_vat_number: '',
            },
            beneficialOwners: [{ name: '', id_number: '', address: '', phone: '', email: '' }],
            trustees: [{ name: '', id_number: '', address: '' }],
            beneficiaries: [{ name: '', id_number: '', address: '' }],
            partners: [{ name: '', id_number: '', address: '', phone: '', email: '' }],

            principal: {
                acting_on_behalf: '',
                full_name: '', id_number: '', sa_citizen: '',
                residential_address: '', phone: '', email: '',
                tax_number: '', authority_source: '',
            },

            representative: {
                has_representative: '',
                full_name: '', id_number: '', authority_source: '',
            },

            service: {
                transaction_purpose: '', purpose_other: '',
                payment_method: '', cash_over_50k: '',
            },

            pep: {
                foreign_pep: [],
                domestic_pep: [],
                is_family_associate: '',
                family_associate_details: '',
                source_of_wealth: '',
            },

            declaration: { signed_at_location: '' },

            get computedUploadTypes() {
                const t = [
                    { key: 'id_copy', label: 'Certified ID Copy / Passport of person completing form', required: true, show: true, tooltip: null },
                    { key: 'proof_of_address', label: 'Proof of residential address (less than 2 months old)', required: true, show: true, tooltip: addressTooltip },
                ];
                if (this.principal.acting_on_behalf === 'yes' && this.entityType === 'natural') {
                    t.push({ key: 'principal_id', label: "Principal's ID Copy / Passport", required: true, show: true, tooltip: null });
                    t.push({ key: 'principal_address', label: "Principal's proof of address", required: true, show: true, tooltip: addressTooltip });
                    t.push({ key: 'principal_authority', label: 'Authority document (to act on behalf of principal)', required: true, show: true, tooltip: null });
                }
                if (this.representative.has_representative === 'yes' && this.entityType === 'natural') {
                    t.push({ key: 'representative_authority', label: "Representative's authority document", required: true, show: true, tooltip: null });
                }
                if (this.entityType === 'company') {
                    t.push({ key: 'company_registration', label: 'Proof of company/CC existence — registration document', required: true, show: true, tooltip: null });
                    t.push({ key: 'company_authority', label: 'Authority document to act on behalf of company', required: true, show: true, tooltip: null });
                    t.push({ key: 'beneficial_owner_ids', label: 'ID copies of all beneficial owners', required: true, show: true, tooltip: null });
                    t.push({ key: 'beneficial_owner_addresses', label: 'Proof of address of all beneficial owners', required: true, show: true, tooltip: addressTooltip });
                }
                if (this.entityType === 'trust') {
                    t.push({ key: 'trust_deed', label: 'Trust deed or letters of authority', required: true, show: true, tooltip: null });
                    t.push({ key: 'trust_authority', label: 'Authority document — trust resolution etc', required: true, show: true, tooltip: null });
                    t.push({ key: 'donor_id', label: "Donor's ID copy", required: true, show: true, tooltip: null });
                    t.push({ key: 'donor_address', label: "Donor's proof of address", required: true, show: true, tooltip: addressTooltip });
                    t.push({ key: 'trustee_ids', label: 'ID copies of all trustees', required: true, show: true, tooltip: null });
                    t.push({ key: 'trustee_addresses', label: 'Proof of address of all trustees', required: true, show: true, tooltip: addressTooltip });
                    if (this.entity.has_named_beneficiaries === 'yes') {
                        t.push({ key: 'beneficiary_ids', label: 'ID copies of named beneficiaries', required: true, show: true, tooltip: null });
                        t.push({ key: 'beneficiary_addresses', label: 'Proof of address of named beneficiaries', required: true, show: true, tooltip: addressTooltip });
                    }
                }
                if (this.entityType === 'partnership') {
                    t.push({ key: 'partnership_authority', label: 'Authority document', required: true, show: true, tooltip: null });
                    t.push({ key: 'partner_ids', label: 'ID copies of all partners', required: true, show: true, tooltip: null });
                    t.push({ key: 'partner_addresses', label: 'Proof of address of all partners', required: true, show: true, tooltip: addressTooltip });
                }
                return t;
            },

            init() { this.$nextTick(() => this.initSignaturePad()); },

            initSignaturePad() {
                const canvas = this.$refs.signatureCanvas;
                if (!canvas) return;
                const ctx = canvas.getContext('2d');
                let drawing = false, lastX, lastY;
                const getPos = (e) => {
                    const r = canvas.getBoundingClientRect();
                    const sx = canvas.width / r.width, sy = canvas.height / r.height;
                    if (e.touches) return { x: (e.touches[0].clientX - r.left) * sx, y: (e.touches[0].clientY - r.top) * sy };
                    return { x: (e.clientX - r.left) * sx, y: (e.clientY - r.top) * sy };
                };
                const start = (e) => { e.preventDefault(); drawing = true; const p = getPos(e); lastX = p.x; lastY = p.y; };
                const move = (e) => { if (!drawing) return; e.preventDefault(); const p = getPos(e); ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(p.x, p.y); ctx.strokeStyle = '#0f172a'; ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.stroke(); lastX = p.x; lastY = p.y; };
                const end = () => { drawing = false; };
                canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move);
                canvas.addEventListener('mouseup', end); canvas.addEventListener('mouseleave', end);
                canvas.addEventListener('touchstart', start); canvas.addEventListener('touchmove', move); canvas.addEventListener('touchend', end);
                this.signaturePad = { canvas, ctx };
            },

            clearSignature() {
                if (!this.signaturePad) return;
                this.signaturePad.ctx.clearRect(0, 0, this.signaturePad.canvas.width, this.signaturePad.canvas.height);
                this.signatureDataUrl = '';
            },

            async handleFileSelect(e, k) { const f = e.target.files[0]; if (f) await this.uploadFile(f, k); },
            async handleDrop(e, k) { const f = e.dataTransfer.files[0]; if (f) await this.uploadFile(f, k); },
            async uploadFile(file, docType) {
                if (file.size > 10485760) { alert('File is too large. Maximum size is 10MB.'); return; }
                this.uploads[docType] = { name: file.name, status: 'uploading' };
                const fd = new FormData(); fd.append('file', file); fd.append('document_type', docType);
                try {
                    const res = await fetch('{{ route("fica.upload", $token) }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: fd });
                    if (res.ok) { const d = await res.json(); this.uploads[docType] = { name: file.name, status: 'done', id: d.id }; }
                    else { this.uploads[docType] = { name: file.name, status: 'error' }; }
                } catch (err) { this.uploads[docType] = { name: file.name, status: 'error' }; }
            },
            removeUpload(k) { delete this.uploads[k]; this.uploads = { ...this.uploads }; },

            submitForm() {
                if (this.signaturePad) {
                    const c = this.signaturePad.canvas, ctx = this.signaturePad.ctx;
                    const px = ctx.getImageData(0, 0, c.width, c.height).data;
                    let has = false; for (let i = 3; i < px.length; i += 4) { if (px[i] > 0) { has = true; break; } }
                    if (!has) { alert('Please provide your signature before submitting.'); return; }
                    this.signatureDataUrl = c.toDataURL('image/png');
                }
                const req = this.computedUploadTypes.filter(t => t.required && t.show);
                for (const u of req) { if (!this.uploads[u.key] || this.uploads[u.key].status !== 'done') { alert('Please upload: ' + u.label); return; } }
                this.submitting = true;
                this.$nextTick(() => document.getElementById('ficaForm').submit());
            }
        };
    }
    </script>
</body>
</html>
