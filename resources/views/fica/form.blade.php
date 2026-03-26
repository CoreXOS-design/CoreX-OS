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
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f1f5f9; }
        .fica-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 0; padding: 2rem; margin-bottom: 1.5rem; }
        .fica-section-title { font-size: 1.125rem; font-weight: 700; color: #0f172a; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid #0d9488; }
        .fica-label { display: block; font-weight: 600; font-size: 0.875rem; color: #334155; margin-bottom: 0.25rem; }
        .fica-input { width: 100%; padding: 0.625rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 0; font-size: 0.875rem; background: #fff; transition: border-color 0.15s; }
        .fica-input:focus { outline: none; border-color: #0d9488; box-shadow: 0 0 0 2px rgba(13,148,136,0.15); }
        .fica-radio-group { display: flex; gap: 1.5rem; margin-top: 0.25rem; }
        .fica-radio-label { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; color: #334155; cursor: pointer; }
        .fica-btn { display: inline-block; padding: 0.75rem 2rem; background: #0f172a; color: #fff; font-weight: 600; font-size: 1rem; border: none; border-radius: 0; cursor: pointer; transition: background 0.15s; }
        .fica-btn:hover { background: #1e293b; }
        .fica-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .fica-error { color: #dc2626; font-size: 0.8rem; margin-top: 0.25rem; }
        .repeatable-row { display: flex; gap: 0.75rem; align-items: end; margin-bottom: 0.5rem; }
        .repeatable-row .fica-input { flex: 1; }
        .upload-zone { border: 2px dashed #cbd5e1; border-radius: 0; padding: 1.5rem; text-align: center; cursor: pointer; transition: border-color 0.15s, background 0.15s; }
        .upload-zone:hover, .upload-zone.dragover { border-color: #0d9488; background: #f0fdfa; }
        .upload-item { display: flex; align-items: center; justify-content: space-between; padding: 0.5rem 0.75rem; background: #f8fafc; border: 1px solid #e2e8f0; margin-top: 0.5rem; font-size: 0.8rem; }
        .upload-item .status-ok { color: #059669; font-weight: 600; }
        .upload-item .status-err { color: #dc2626; font-weight: 600; }
        #signatureCanvas { border: 1px solid #cbd5e1; background: #fff; touch-action: none; cursor: crosshair; }
        @media (max-width: 640px) {
            .fica-card { padding: 1.25rem; }
            .repeatable-row { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="max-w-3xl mx-auto px-4 py-8" x-data="ficaForm()">

        {{-- Agency Header --}}
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

            {{-- SECTION 1: Personal Details --}}
            <div class="fica-card">
                <h2 class="fica-section-title">1. Personal Details</h2>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div style="grid-column: span 2;">
                        <label class="fica-label">Full Name *</label>
                        <input type="text" name="full_name" class="fica-input" required x-model="form.full_name">
                    </div>
                    <div>
                        <label class="fica-label">SA ID / Passport Number *</label>
                        <input type="text" name="id_number" class="fica-input" required x-model="form.id_number">
                    </div>
                    <div>
                        <label class="fica-label">Date of Birth *</label>
                        <input type="date" name="date_of_birth" class="fica-input" required x-model="form.date_of_birth">
                    </div>
                    <div>
                        <label class="fica-label">Nationality *</label>
                        <input type="text" name="nationality" class="fica-input" required value="South African" x-model="form.nationality">
                    </div>
                    <div>
                        <label class="fica-label">Phone Number *</label>
                        <input type="text" name="phone" class="fica-input" required x-model="form.phone">
                    </div>
                    <div style="grid-column: span 2;">
                        <label class="fica-label">Email Address *</label>
                        <input type="email" name="email" class="fica-input" required x-model="form.email">
                    </div>
                    <div style="grid-column: span 2;">
                        <label class="fica-label">Residential Address *</label>
                        <textarea name="residential_address" class="fica-input" rows="2" required x-model="form.residential_address"></textarea>
                    </div>
                    <div style="grid-column: span 2;">
                        <label class="fica-label">Postal Address</label>
                        <textarea name="postal_address" class="fica-input" rows="2" x-model="form.postal_address"></textarea>
                    </div>
                </div>
            </div>

            {{-- SECTION 2: Source of Funds --}}
            <div class="fica-card">
                <h2 class="fica-section-title">2. Source of Funds</h2>
                <div style="display: grid; gap: 1rem;">
                    <div>
                        <label class="fica-label">How will payments be financed? *</label>
                        <textarea name="payment_method" class="fica-input" rows="2" required x-model="form.payment_method"></textarea>
                    </div>
                    <div>
                        <label class="fica-label">Will any payment involve R50,000+ in cash? *</label>
                        <div class="fica-radio-group">
                            <label class="fica-radio-label"><input type="radio" name="cash_over_50k" value="yes" required x-model="form.cash_over_50k"> Yes</label>
                            <label class="fica-radio-label"><input type="radio" name="cash_over_50k" value="no" x-model="form.cash_over_50k"> No</label>
                        </div>
                    </div>
                    <div>
                        <label class="fica-label">Source of Income *</label>
                        <textarea name="source_of_income" class="fica-input" rows="2" required x-model="form.source_of_income"></textarea>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <label class="fica-label">Occupation / Business Type *</label>
                            <input type="text" name="occupation" class="fica-input" required x-model="form.occupation">
                        </div>
                        <div>
                            <label class="fica-label">Employer Name</label>
                            <input type="text" name="employer" class="fica-input" x-model="form.employer">
                        </div>
                    </div>
                </div>
            </div>

            {{-- SECTION 3: Purpose of Transaction --}}
            <div class="fica-card">
                <h2 class="fica-section-title">3. Purpose of Transaction</h2>
                <div style="display: grid; gap: 0.5rem;">
                    <label class="fica-radio-label"><input type="radio" name="transaction_purpose" value="Purchase a property" required x-model="form.transaction_purpose"> Purchase a property</label>
                    <label class="fica-radio-label"><input type="radio" name="transaction_purpose" value="Sell a property" x-model="form.transaction_purpose"> Sell a property</label>
                    <label class="fica-radio-label"><input type="radio" name="transaction_purpose" value="Rent a property" x-model="form.transaction_purpose"> Rent a property</label>
                    <label class="fica-radio-label"><input type="radio" name="transaction_purpose" value="Let out a property" x-model="form.transaction_purpose"> Let out a property</label>
                    <label class="fica-radio-label"><input type="radio" name="transaction_purpose" value="Other" x-model="form.transaction_purpose"> Other</label>
                    <div x-show="form.transaction_purpose === 'Other'" x-cloak style="margin-top: 0.5rem;">
                        <input type="text" name="purpose_other" class="fica-input" placeholder="Please specify..." x-model="form.purpose_other">
                    </div>
                </div>
            </div>

            {{-- SECTION 4: Entity Type --}}
            <div class="fica-card">
                <h2 class="fica-section-title">4. Entity Type</h2>
                <div style="display: grid; gap: 0.5rem; margin-bottom: 1rem;">
                    <label class="fica-radio-label"><input type="radio" name="entity_type" value="natural" required x-model="form.entity_type"> Natural Person (Individual)</label>
                    <label class="fica-radio-label"><input type="radio" name="entity_type" value="company" x-model="form.entity_type"> Company / CC</label>
                    <label class="fica-radio-label"><input type="radio" name="entity_type" value="trust" x-model="form.entity_type"> Trust</label>
                    <label class="fica-radio-label"><input type="radio" name="entity_type" value="partnership" x-model="form.entity_type"> Partnership</label>
                </div>

                {{-- Company sub-section --}}
                <div x-show="form.entity_type === 'company'" x-cloak style="border-top: 1px solid #e2e8f0; padding-top: 1rem;">
                    <h3 style="font-weight: 600; color: #0f172a; margin-bottom: 0.75rem;">Company / CC Details</h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <label class="fica-label">Company Name *</label>
                            <input type="text" name="company_name" class="fica-input" x-model="form.company_name" :required="form.entity_type === 'company'">
                        </div>
                        <div>
                            <label class="fica-label">Registration Number *</label>
                            <input type="text" name="company_reg_number" class="fica-input" x-model="form.company_reg_number" :required="form.entity_type === 'company'">
                        </div>
                        <div style="grid-column: span 2;">
                            <label class="fica-label">Registered Address *</label>
                            <textarea name="company_address" class="fica-input" rows="2" x-model="form.company_address" :required="form.entity_type === 'company'"></textarea>
                        </div>
                    </div>
                    <div style="margin-top: 1rem;">
                        <label class="fica-label">Directors / Members</label>
                        <template x-for="(dir, idx) in form.directors" :key="idx">
                            <div class="repeatable-row">
                                <input type="text" :name="'directors['+idx+'][name]'" class="fica-input" placeholder="Full Name" x-model="dir.name">
                                <input type="text" :name="'directors['+idx+'][id_number]'" class="fica-input" placeholder="ID Number" x-model="dir.id_number">
                                <button type="button" @click="form.directors.splice(idx, 1)" x-show="form.directors.length > 1" style="color: #dc2626; font-size: 1.25rem; background: none; border: none; cursor: pointer;">&times;</button>
                            </div>
                        </template>
                        <button type="button" @click="form.directors.push({name:'',id_number:''})" style="font-size: 0.8rem; color: #0d9488; background: none; border: none; cursor: pointer; font-weight: 600;">+ Add Director</button>
                    </div>
                </div>

                {{-- Trust sub-section --}}
                <div x-show="form.entity_type === 'trust'" x-cloak style="border-top: 1px solid #e2e8f0; padding-top: 1rem;">
                    <h3 style="font-weight: 600; color: #0f172a; margin-bottom: 0.75rem;">Trust Details</h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <label class="fica-label">Trust Name *</label>
                            <input type="text" name="trust_name" class="fica-input" x-model="form.trust_name" :required="form.entity_type === 'trust'">
                        </div>
                        <div>
                            <label class="fica-label">Trust Number *</label>
                            <input type="text" name="trust_number" class="fica-input" x-model="form.trust_number" :required="form.entity_type === 'trust'">
                        </div>
                    </div>
                    <div style="margin-top: 1rem;">
                        <label class="fica-label">Trustees</label>
                        <template x-for="(tr, idx) in form.trustees" :key="idx">
                            <div class="repeatable-row">
                                <input type="text" :name="'trustees['+idx+'][name]'" class="fica-input" placeholder="Full Name" x-model="tr.name">
                                <input type="text" :name="'trustees['+idx+'][id_number]'" class="fica-input" placeholder="ID Number" x-model="tr.id_number">
                                <button type="button" @click="form.trustees.splice(idx, 1)" x-show="form.trustees.length > 1" style="color: #dc2626; font-size: 1.25rem; background: none; border: none; cursor: pointer;">&times;</button>
                            </div>
                        </template>
                        <button type="button" @click="form.trustees.push({name:'',id_number:''})" style="font-size: 0.8rem; color: #0d9488; background: none; border: none; cursor: pointer; font-weight: 600;">+ Add Trustee</button>
                    </div>
                    <div style="margin-top: 1rem;">
                        <label class="fica-label">Beneficiaries</label>
                        <template x-for="(bn, idx) in form.beneficiaries" :key="idx">
                            <div class="repeatable-row">
                                <input type="text" :name="'beneficiaries['+idx+'][name]'" class="fica-input" placeholder="Full Name" x-model="bn.name">
                                <input type="text" :name="'beneficiaries['+idx+'][id_number]'" class="fica-input" placeholder="ID Number" x-model="bn.id_number">
                                <button type="button" @click="form.beneficiaries.splice(idx, 1)" x-show="form.beneficiaries.length > 1" style="color: #dc2626; font-size: 1.25rem; background: none; border: none; cursor: pointer;">&times;</button>
                            </div>
                        </template>
                        <button type="button" @click="form.beneficiaries.push({name:'',id_number:''})" style="font-size: 0.8rem; color: #0d9488; background: none; border: none; cursor: pointer; font-weight: 600;">+ Add Beneficiary</button>
                    </div>
                </div>

                {{-- Partnership sub-section --}}
                <div x-show="form.entity_type === 'partnership'" x-cloak style="border-top: 1px solid #e2e8f0; padding-top: 1rem;">
                    <h3 style="font-weight: 600; color: #0f172a; margin-bottom: 0.75rem;">Partnership Details</h3>
                    <div style="margin-bottom: 1rem;">
                        <label class="fica-label">Partnership Name *</label>
                        <input type="text" name="partnership_name" class="fica-input" x-model="form.partnership_name" :required="form.entity_type === 'partnership'">
                    </div>
                    <div>
                        <label class="fica-label">Partners</label>
                        <template x-for="(pt, idx) in form.partners" :key="idx">
                            <div class="repeatable-row">
                                <input type="text" :name="'partners['+idx+'][name]'" class="fica-input" placeholder="Full Name" x-model="pt.name">
                                <input type="text" :name="'partners['+idx+'][id_number]'" class="fica-input" placeholder="ID Number" x-model="pt.id_number">
                                <button type="button" @click="form.partners.splice(idx, 1)" x-show="form.partners.length > 1" style="color: #dc2626; font-size: 1.25rem; background: none; border: none; cursor: pointer;">&times;</button>
                            </div>
                        </template>
                        <button type="button" @click="form.partners.push({name:'',id_number:''})" style="font-size: 0.8rem; color: #0d9488; background: none; border: none; cursor: pointer; font-weight: 600;">+ Add Partner</button>
                    </div>
                    <div style="margin-top: 1rem;">
                        <label class="fica-label">Authority Document Reference</label>
                        <input type="text" name="authority_reference" class="fica-input" x-model="form.authority_reference">
                    </div>
                </div>
            </div>

            {{-- SECTION 5: PEP --}}
            <div class="fica-card">
                <h2 class="fica-section-title">5. Politically Exposed Person (PEP)</h2>
                <div style="display: grid; gap: 1rem;">
                    <div>
                        <label class="fica-label">Are you a domestic PEP? *</label>
                        <div class="fica-radio-group">
                            <label class="fica-radio-label"><input type="radio" name="pep_domestic" value="yes" required x-model="form.pep_domestic"> Yes</label>
                            <label class="fica-radio-label"><input type="radio" name="pep_domestic" value="no" x-model="form.pep_domestic"> No</label>
                        </div>
                    </div>
                    <div>
                        <label class="fica-label">Are you a foreign PEP? *</label>
                        <div class="fica-radio-group">
                            <label class="fica-radio-label"><input type="radio" name="pep_foreign" value="yes" required x-model="form.pep_foreign"> Yes</label>
                            <label class="fica-radio-label"><input type="radio" name="pep_foreign" value="no" x-model="form.pep_foreign"> No</label>
                        </div>
                    </div>
                    <div>
                        <label class="fica-label">Family member of a PEP? *</label>
                        <div class="fica-radio-group">
                            <label class="fica-radio-label"><input type="radio" name="pep_family" value="yes" required x-model="form.pep_family"> Yes</label>
                            <label class="fica-radio-label"><input type="radio" name="pep_family" value="no" x-model="form.pep_family"> No</label>
                        </div>
                    </div>
                    <div>
                        <label class="fica-label">Close associate of a PEP? *</label>
                        <div class="fica-radio-group">
                            <label class="fica-radio-label"><input type="radio" name="pep_associate" value="yes" required x-model="form.pep_associate"> Yes</label>
                            <label class="fica-radio-label"><input type="radio" name="pep_associate" value="no" x-model="form.pep_associate"> No</label>
                        </div>
                    </div>
                    <div x-show="form.pep_domestic === 'yes' || form.pep_foreign === 'yes' || form.pep_family === 'yes' || form.pep_associate === 'yes'" x-cloak>
                        <label class="fica-label">Please provide details *</label>
                        <textarea name="pep_details" class="fica-input" rows="3" x-model="form.pep_details"
                            :required="form.pep_domestic === 'yes' || form.pep_foreign === 'yes' || form.pep_family === 'yes' || form.pep_associate === 'yes'"></textarea>
                    </div>
                </div>
            </div>

            {{-- SECTION 6: Document Uploads --}}
            <div class="fica-card">
                <h2 class="fica-section-title">6. Document Uploads</h2>
                <p style="font-size: 0.8rem; color: #64748b; margin-bottom: 1rem;">PDF, JPG, PNG, or HEIC. Maximum 10MB per file.</p>

                <template x-for="docType in uploadTypes" :key="docType.key">
                    <div style="margin-bottom: 1.25rem;">
                        <label class="fica-label" x-text="docType.label + (docType.required ? ' *' : '')"></label>
                        <div class="upload-zone"
                             @click="$refs['fileInput_' + docType.key].click()"
                             @dragover.prevent="$event.currentTarget.classList.add('dragover')"
                             @dragleave.prevent="$event.currentTarget.classList.remove('dragover')"
                             @drop.prevent="handleDrop($event, docType.key); $event.currentTarget.classList.remove('dragover')"
                             x-show="!uploads[docType.key]">
                            <p style="margin: 0; color: #64748b; font-size: 0.875rem;">Click or drag file here</p>
                        </div>
                        <input type="file" x-ref="fileInput" :data-type="docType.key" accept=".pdf,.jpg,.jpeg,.png,.heic" style="display:none;"
                               @change="handleFileSelect($event, docType.key)"
                               x-init="$watch('docType', () => {})"
                               :id="'fileInput_' + docType.key">
                        {{-- Workaround: individual file inputs --}}
                        <div x-show="uploads[docType.key]" class="upload-item">
                            <span x-text="uploads[docType.key]?.name || ''"></span>
                            <span>
                                <span x-show="uploads[docType.key]?.status === 'uploading'" style="color: #d97706;">Uploading...</span>
                                <span x-show="uploads[docType.key]?.status === 'done'" class="status-ok">Uploaded</span>
                                <span x-show="uploads[docType.key]?.status === 'error'" class="status-err">Failed</span>
                                <button type="button" @click="removeUpload(docType.key)" style="margin-left: 0.5rem; color: #dc2626; background: none; border: none; cursor: pointer;">&times;</button>
                            </span>
                        </div>
                    </div>
                </template>
            </div>

            {{-- SECTION 7: Declaration & Signature --}}
            <div class="fica-card">
                <h2 class="fica-section-title">7. Declaration & Signature</h2>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 1rem; margin-bottom: 1.5rem; font-size: 0.875rem; color: #334155; line-height: 1.7;">
                    I hereby declare that the information provided above is true, correct, and complete. I understand that providing false or misleading information may constitute a criminal offence under the Financial Intelligence Centre Act (FICA).
                </div>

                <label class="fica-label">Your Signature *</label>
                <div style="position: relative; margin-bottom: 0.5rem;">
                    <canvas id="signatureCanvas" x-ref="signatureCanvas" width="560" height="180" style="width: 100%; max-width: 560px;"></canvas>
                    <button type="button" @click="clearSignature()" style="position: absolute; top: 0.5rem; right: 0.5rem; font-size: 0.75rem; color: #64748b; background: #fff; border: 1px solid #e2e8f0; padding: 0.25rem 0.5rem; cursor: pointer;">Clear</button>
                </div>
                <input type="hidden" name="signature_data" x-model="signatureDataUrl">

                <div style="display: flex; align-items: center; gap: 1rem; margin-top: 1rem;">
                    <div>
                        <label class="fica-label">Date</label>
                        <input type="text" class="fica-input" value="{{ now()->format('d/m/Y') }}" readonly style="width: 140px; background: #f8fafc;">
                    </div>
                </div>

                <div style="margin-top: 2rem; text-align: center;">
                    <button type="submit" class="fica-btn" :disabled="submitting">
                        <span x-show="!submitting">Submit FICA Declaration</span>
                        <span x-show="submitting" x-cloak>Submitting...</span>
                    </button>
                </div>
            </div>

            <input type="hidden" name="signature_data" x-model="signatureDataUrl">
        </form>
    </div>

    @vite(['resources/js/app.js'])
    <script>
        function ficaForm() {
            return {
                submitting: false,
                signatureDataUrl: '',
                form: {
                    full_name: '{{ $contact->full_name ?? '' }}',
                    id_number: '{{ $contact->id_number ?? '' }}',
                    date_of_birth: '',
                    nationality: 'South African',
                    residential_address: '{{ $contact->address ?? '' }}',
                    postal_address: '',
                    phone: '{{ $contact->phone ?? '' }}',
                    email: '{{ $contact->email ?? '' }}',
                    payment_method: '',
                    cash_over_50k: '',
                    source_of_income: '',
                    occupation: '',
                    employer: '',
                    transaction_purpose: '',
                    purpose_other: '',
                    entity_type: 'natural',
                    company_name: '', company_reg_number: '', company_address: '',
                    directors: [{name:'', id_number:''}],
                    trust_name: '', trust_number: '',
                    trustees: [{name:'', id_number:''}],
                    beneficiaries: [{name:'', id_number:''}],
                    partnership_name: '',
                    partners: [{name:'', id_number:''}],
                    authority_reference: '',
                    pep_domestic: '', pep_foreign: '', pep_family: '', pep_associate: '',
                    pep_details: '',
                },
                uploads: {},
                uploadTypes: [
                    { key: 'id_copy', label: 'ID Copy', required: true },
                    { key: 'proof_of_address', label: 'Proof of Residential Address (less than 3 months)', required: true },
                    { key: 'bank_statement', label: 'Proof of Income / Bank Statement', required: false },
                    { key: 'company_registration', label: 'Company / Trust / Partnership Documents', required: false },
                    { key: 'authority', label: 'Authority Letter (if representative)', required: false },
                ],
                signaturePad: null,

                init() {
                    this.$nextTick(() => { this.initSignaturePad(); });

                    // Create file input refs dynamically
                    this.$nextTick(() => {
                        this.uploadTypes.forEach(dt => {
                            const el = document.getElementById('fileInput_' + dt.key);
                            if (el) this.$refs['fileInput_' + dt.key] = el;
                        });
                    });
                },

                initSignaturePad() {
                    const canvas = this.$refs.signatureCanvas;
                    if (!canvas) return;
                    const ctx = canvas.getContext('2d');
                    let drawing = false;
                    let lastX, lastY;

                    const getPos = (e) => {
                        const rect = canvas.getBoundingClientRect();
                        const scaleX = canvas.width / rect.width;
                        const scaleY = canvas.height / rect.height;
                        if (e.touches) {
                            return { x: (e.touches[0].clientX - rect.left) * scaleX, y: (e.touches[0].clientY - rect.top) * scaleY };
                        }
                        return { x: (e.clientX - rect.left) * scaleX, y: (e.clientY - rect.top) * scaleY };
                    };

                    const startDraw = (e) => {
                        e.preventDefault();
                        drawing = true;
                        const pos = getPos(e);
                        lastX = pos.x;
                        lastY = pos.y;
                    };
                    const draw = (e) => {
                        if (!drawing) return;
                        e.preventDefault();
                        const pos = getPos(e);
                        ctx.beginPath();
                        ctx.moveTo(lastX, lastY);
                        ctx.lineTo(pos.x, pos.y);
                        ctx.strokeStyle = '#0f172a';
                        ctx.lineWidth = 2;
                        ctx.lineCap = 'round';
                        ctx.stroke();
                        lastX = pos.x;
                        lastY = pos.y;
                    };
                    const endDraw = () => { drawing = false; };

                    canvas.addEventListener('mousedown', startDraw);
                    canvas.addEventListener('mousemove', draw);
                    canvas.addEventListener('mouseup', endDraw);
                    canvas.addEventListener('mouseleave', endDraw);
                    canvas.addEventListener('touchstart', startDraw);
                    canvas.addEventListener('touchmove', draw);
                    canvas.addEventListener('touchend', endDraw);

                    this.signaturePad = { canvas, ctx };
                },

                clearSignature() {
                    if (!this.signaturePad) return;
                    const { canvas, ctx } = this.signaturePad;
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    this.signatureDataUrl = '';
                },

                async handleFileSelect(event, docType) {
                    const file = event.target.files[0];
                    if (file) await this.uploadFile(file, docType);
                },

                async handleDrop(event, docType) {
                    const file = event.dataTransfer.files[0];
                    if (file) await this.uploadFile(file, docType);
                },

                async uploadFile(file, docType) {
                    if (file.size > 10 * 1024 * 1024) {
                        alert('File is too large. Maximum size is 10MB.');
                        return;
                    }

                    this.uploads[docType] = { name: file.name, status: 'uploading' };

                    const formData = new FormData();
                    formData.append('file', file);
                    formData.append('document_type', docType);

                    try {
                        const response = await fetch('{{ route("fica.upload", $token) }}', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                            body: formData
                        });
                        if (response.ok) {
                            const data = await response.json();
                            this.uploads[docType] = { name: file.name, status: 'done', id: data.id };
                        } else {
                            this.uploads[docType] = { name: file.name, status: 'error' };
                        }
                    } catch (err) {
                        this.uploads[docType] = { name: file.name, status: 'error' };
                    }
                },

                removeUpload(docType) {
                    delete this.uploads[docType];
                    this.uploads = { ...this.uploads };
                },

                submitForm() {
                    // Capture signature
                    if (this.signaturePad) {
                        const canvas = this.signaturePad.canvas;
                        const ctx = this.signaturePad.ctx;
                        const pixels = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
                        let hasContent = false;
                        for (let i = 3; i < pixels.length; i += 4) {
                            if (pixels[i] > 0) { hasContent = true; break; }
                        }
                        if (!hasContent) {
                            alert('Please provide your signature before submitting.');
                            return;
                        }
                        this.signatureDataUrl = canvas.toDataURL('image/png');
                    }

                    // Check required uploads
                    const requiredUploads = this.uploadTypes.filter(t => t.required);
                    for (const ut of requiredUploads) {
                        if (!this.uploads[ut.key] || this.uploads[ut.key].status !== 'done') {
                            alert('Please upload: ' + ut.label);
                            return;
                        }
                    }

                    this.submitting = true;
                    this.$nextTick(() => {
                        document.getElementById('ficaForm').submit();
                    });
                }
            };
        }
    </script>
</body>
</html>
