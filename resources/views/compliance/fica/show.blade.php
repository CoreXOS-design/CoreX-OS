{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
    // Status badge sits on the navy branded header — render as a white pill with a
    // semantic-coloured label so every status stays legible against the navy background.
    $statusTextMap = [
        'draft'                 => 'var(--text-muted,#9ca3af)',
        'submitted'             => 'var(--ds-navy,#0b2a4a)',
        'under_review'          => 'var(--ds-amber,#f59e0b)',
        'agent_approved'        => 'var(--ds-navy,#0b2a4a)',
        'corrections_requested' => 'var(--ds-amber,#f59e0b)',
        'approved'              => 'var(--ds-green,#059669)',
        'rejected'              => 'var(--ds-crimson,#c41e3a)',
    ];
    $statusTextColor = $statusTextMap[$submission->status] ?? 'var(--ds-navy,#0b2a4a)';
    $riskColors = [1 => 'var(--ds-green,#059669)', 2 => 'var(--ds-amber,#f59e0b)', 3 => 'var(--ds-crimson,#c41e3a)'];
    $riskLabels = [1 => 'Low', 2 => 'Medium', 3 => 'High'];
@endphp

<div class="w-full space-y-5" x-data="ficaReview()">

    {{-- Page header (branded — Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
            <div class="min-w-0">
                <a href="{{ route('compliance.fica.index') }}" class="inline-flex items-center gap-1 text-xs font-medium text-white/70 hover:text-white transition mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    Back to Compliance
                </a>
                <div class="flex items-center flex-wrap gap-2">
                    <h1 class="text-xl font-bold text-white leading-tight">FICA Review</h1>
                    <span class="ds-badge" style="background:#fff; color:{{ $statusTextColor }};">{{ $submission->status_label }}</span>
                    @if($submission->isWetInk())
                        <span class="ds-badge" style="background:rgba(255,255,255,0.15); color:#fff;">
                            Wet-Ink Intake &mdash; Received {{ $submission->wet_ink_received_date?->format('d M Y') }}
                        </span>
                    @else
                        <span class="ds-badge" style="background:rgba(255,255,255,0.15); color:#fff;">Online Intake</span>
                    @endif
                </div>
                <p class="text-sm text-white/60 mt-1">
                    {{ $submission->contact ? $submission->contact->full_name : 'Unknown contact' }}
                    &mdash; Requested by {{ $submission->requestedBy->name ?? 'Unknown' }} on {{ $submission->created_at->format('d M Y') }}
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap flex-shrink-0">
                @if($submission->status === 'approved')
                    <a href="{{ route('compliance.fica.pdf', $submission) }}" target="_blank" class="corex-btn-outline corex-btn-on-brand text-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                        Download PDF
                    </a>
                @endif
                @if($submission->status === 'agent_approved' && auth()->user()->isComplianceOfficer())
                    <a href="{{ route('compliance.fica.compliance-review', $submission) }}" class="corex-btn-primary text-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                        Compliance Review
                    </a>
                @endif
            </div>
        </div>
    </div>

    {{-- Recipient Form Link (online intake only) --}}
    @if(!$submission->isWetInk() && $submission->token)
    <div class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
        <div class="flex flex-col sm:flex-row sm:items-center gap-2">
            <span class="text-xs font-semibold whitespace-nowrap" style="color:var(--text-secondary);">Recipient Form Link</span>
            <input type="text" value="{{ url('/fica/' . $submission->token) }}" readonly
                   class="flex-1 rounded-md px-3 py-2 text-sm select-all focus:outline-none"
                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <button type="button"
                    onclick="ficaCopyToClipboard('{{ url('/fica/' . $submission->token) }}', this)"
                    class="corex-btn-outline text-sm whitespace-nowrap">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.5a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m0 0a2.625 2.625 0 1 1 5.25 0" /></svg>
                <span>Copy Link</span>
            </button>
        </div>
    </div>
    @endif

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background:color-mix(in srgb, var(--ds-green,#059669) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green,#059669) 30%, transparent); color:var(--text-primary);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 flex-shrink-0" style="color:var(--ds-green,#059669);"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif

    {{-- CO Corrections Banner --}}
    @if($submission->status === 'corrections_requested' && $submission->co_notes)
        <div class="rounded-md px-4 py-4"
             style="background:color-mix(in srgb, var(--ds-amber,#f59e0b) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber,#f59e0b) 30%, transparent); color:var(--text-primary);">
            <div class="flex items-start justify-between gap-4">
                <div class="flex-1">
                    <h4 class="text-sm font-bold mb-1" style="color:var(--text-primary);">Compliance Officer Requested Corrections</h4>
                    <p class="text-sm" style="color:var(--text-primary);">{{ $submission->co_notes }}</p>
                    @if($submission->coVerifiedBy)
                        <p class="text-xs mt-2" style="color:var(--text-muted);">&mdash; {{ $submission->coVerifiedBy->name }}, {{ $submission->co_verified_at?->format('d M Y H:i') }}</p>
                    @endif
                </div>
                @php
                    $canResubmit = $submission->requested_by === auth()->id() || auth()->user()->isOwnerRole() || auth()->user()->hasPermission('manage_compliance');
                @endphp
                @if($canResubmit)
                    <form method="POST" action="{{ route('compliance.fica.resubmit-corrections', $submission) }}" class="flex-shrink-0">
                        @csrf
                        <button type="submit" class="corex-btn-primary text-sm" onclick="return confirm('Resubmit this FICA for compliance officer review?')">
                            Resubmit for CO Review
                        </button>
                    </form>
                @endif
            </div>
        </div>
    @endif

    @php
        $data = $submission->form_data ?? [];
        $personal = $data['personal'] ?? [];
        $entity = $data['entity'] ?? [];
        $service = $data['service'] ?? [];
        $pepData = $data['pep'] ?? [];
        $principalData = $data['principal'] ?? [];
        $repData = $data['representative'] ?? [];
        $declData = $data['declaration'] ?? [];
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        {{-- LEFT PANEL: Submitted Data --}}
        <div class="lg:col-span-2 space-y-4">
            @if($submission->isWetInk())
                {{-- Wet-ink: contact basics + uploaded documents --}}
                <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                    <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Client Details (from contact record)</h3>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div><span class="text-xs" style="color:var(--text-muted);">Name</span><div class="font-medium" style="color:var(--text-primary);">{{ $personal['first_name'] ?? '' }} {{ $personal['last_name'] ?? '' }}</div></div>
                        <div><span class="text-xs" style="color:var(--text-muted);">ID Number</span><div style="color:var(--text-primary);">{{ $personal['id_number'] ?? 'Not set' }}</div></div>
                        <div><span class="text-xs" style="color:var(--text-muted);">Email</span><div style="color:var(--text-primary);">{{ $personal['email'] ?? 'Not set' }}</div></div>
                        <div><span class="text-xs" style="color:var(--text-muted);">Phone</span><div style="color:var(--text-primary);">{{ $personal['phone'] ?? 'Not set' }}</div></div>
                        <div><span class="text-xs" style="color:var(--text-muted);">Entity Type</span><div class="capitalize" style="color:var(--text-primary);">{{ $entity['type'] ?? $submission->entity_type ?? '—' }}</div></div>
                        <div><span class="text-xs" style="color:var(--text-muted);">Received By</span><div style="color:var(--text-primary);">{{ $submission->form_data['intake']['received_by'] ?? '—' }}</div></div>
                    </div>
                </div>

                {{-- Uploaded documents --}}
                <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                    <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Uploaded Documents</h3>
                    @php
                        $canRemove = in_array($submission->status, ['submitted', 'under_review', 'corrections_requested'])
                            && ($submission->requested_by === auth()->id() || auth()->user()->isOwnerRole() || auth()->user()->hasPermission('manage_compliance'));
                    @endphp
                    @if($submission->documents->isEmpty())
                        <p class="text-sm" style="color:var(--text-muted);">No documents uploaded.</p>
                    @else
                        <div class="space-y-3">
                            @foreach($submission->documents as $doc)
                            <div class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
                                <div class="flex items-center justify-between px-4 py-2" style="background:var(--surface-2);">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-semibold" style="color:var(--text-primary);">{{ $doc->document_type_label }}</span>
                                        <span class="text-[10px]" style="color:var(--text-muted);">{{ $doc->file_name }}</span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <a href="{{ asset('storage/' . $doc->file_path) }}" target="_blank" class="text-xs font-medium" style="color:var(--brand-icon,#0ea5e9); text-decoration:none;">Open in new tab</a>
                                        @if($canRemove)
                                        <form method="POST" action="{{ route('compliance.fica.documents.remove', [$submission, $doc]) }}" onsubmit="return confirm('Remove this document? It will be archived — an admin can recover it.');" style="display:inline;">
                                            @csrf
                                            <button type="submit" class="text-xs font-medium" style="color:var(--ds-crimson,#c41e3a); background:none; border:none; cursor:pointer; padding:0;">Remove</button>
                                        </form>
                                        @endif
                                    </div>
                                </div>
                                @php $isImage = in_array($doc->mime_type, ['image/jpeg', 'image/png', 'image/jpg']); @endphp
                                <div style="max-height:400px; overflow:auto;">
                                    @if($isImage)
                                        <img src="{{ asset('storage/' . $doc->file_path) }}" alt="{{ $doc->document_type_label }}" style="width:100%; display:block;">
                                    @else
                                        <iframe src="{{ asset('storage/' . $doc->file_path) }}" style="width:100%; height:350px; border:none;"></iframe>
                                    @endif
                                </div>
                            </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Paper form notice --}}
                <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
                     style="background:color-mix(in srgb, var(--ds-amber,#f59e0b) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber,#f59e0b) 30%, transparent); color:var(--text-primary);">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 flex-shrink-0" style="color:var(--ds-amber,#f59e0b);"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>
                    <div class="flex-1">Client data captured on signed paper form &mdash; see uploaded FICA form above for full client responses.</div>
                </div>
            @else
                @include('compliance.fica.partials.submitted-data', ['submission' => $submission, 'personal' => $personal, 'entity' => $entity, 'service' => $service, 'pepData' => $pepData, 'principalData' => $principalData, 'repData' => $repData, 'declData' => $declData])
            @endif

            {{-- Agent Upload Panel --}}
            @php
                $canUpload = in_array($submission->status, ['submitted', 'under_review', 'corrections_requested'])
                    && ($submission->requested_by === auth()->id() || auth()->user()->isOwnerRole() || auth()->user()->hasPermission('manage_compliance'));
            @endphp
            @if($canUpload)
                <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);" x-data="{ uploading: false, rows: [0], next: 1, addRow() { this.rows.push(this.next++); }, removeRow(r) { this.rows = this.rows.filter(x => x !== r); } }">
                    <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Upload Supporting Documents</h3>
                    <p class="text-xs mb-3" style="color:var(--text-secondary);">Attach one or more documents received from the client (e.g. ID copy, proof of address, bank statement). Use "Add another document" for each one — every document keeps its own type.</p>
                    <form method="POST" action="{{ route('compliance.fica.agent-upload', $submission) }}" enctype="multipart/form-data" @submit="uploading = true">
                        @csrf
                        <template x-for="r in rows" :key="r">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3 pb-3" style="border-bottom:1px dashed var(--border);">
                                <div>
                                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Document Type *</label>
                                    <select name="document_type[]" required class="w-full rounded-md px-3 py-2 text-sm focus:outline-none" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        <option value="">Select type...</option>
                                        <option value="id_copy">ID Copy</option>
                                        <option value="proof_of_address">Proof of Address</option>
                                        <option value="fica_form">FICA Form</option>
                                        <option value="authority">Authority Document</option>
                                        <option value="bank_statement">Bank Statement</option>
                                        <option value="tax_clearance">Tax Clearance</option>
                                        <option value="company_registration">Company Registration</option>
                                        <option value="trust_deed">Trust Deed</option>
                                        <option value="supporting">Supporting Document</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">File * <span class="font-normal" style="color:var(--text-muted);">(PDF, JPG, PNG — max 10 MB)</span></label>
                                    <input type="file" name="file[]" required accept=".pdf,.jpg,.jpeg,.png,.heic" class="w-full text-sm file:mr-2 file:py-1 file:px-3 file:rounded-md file:border file:text-xs file:font-semibold" style="color:var(--text-secondary);">
                                    <button type="button" x-show="rows.length > 1" @click="removeRow(r)" class="mt-1 text-xs font-medium" style="color:var(--ds-crimson,#c41e3a); background:none; border:none; cursor:pointer; padding:0;">Remove this row</button>
                                </div>
                            </div>
                        </template>
                        <div class="flex items-center gap-3">
                            <button type="button" @click="addRow()" class="corex-btn-outline text-sm">+ Add another document</button>
                            <button type="submit" class="corex-btn-primary text-sm" :disabled="uploading">
                                <span x-show="!uploading">Upload Documents</span>
                                <span x-show="uploading" x-cloak>Uploading...</span>
                            </button>
                        </div>
                    </form>
                    @error('file') <p class="text-xs mt-1" style="color:var(--ds-crimson,#c41e3a);">{{ $message }}</p> @enderror
                    @error('file.*') <p class="text-xs mt-1" style="color:var(--ds-crimson,#c41e3a);">{{ $message }}</p> @enderror
                    @error('document_type') <p class="text-xs mt-1" style="color:var(--ds-crimson,#c41e3a);">{{ $message }}</p> @enderror
                    @error('document_type.*') <p class="text-xs mt-1" style="color:var(--ds-crimson,#c41e3a);">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>

        {{-- RIGHT PANEL: Verification --}}
        <div class="space-y-4">
            {{-- Agent verification summary (visible when agent has approved) --}}
            @if(in_array($submission->status, ['agent_approved', 'corrections_requested', 'approved', 'rejected']) && $submission->agent_verified_by)
                <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                    <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Agent Verification</h3>
                    <dl class="space-y-2 text-sm">
                        <div><dt class="text-xs" style="color:var(--text-muted);">Agent</dt><dd style="color:var(--text-primary);">{{ $submission->agentVerifiedBy->name ?? '—' }}</dd></div>
                        <div><dt class="text-xs" style="color:var(--text-muted);">Date</dt><dd style="color:var(--text-primary);">{{ $submission->agent_verified_at?->format('d M Y H:i') }}</dd></div>
                        @if($submission->risk_rating)
                        <div><dt class="text-xs" style="color:var(--text-muted);">Risk Rating</dt><dd class="font-semibold" style="color:{{ $riskColors[$submission->risk_rating] ?? 'var(--text-primary)' }};">{{ $riskLabels[$submission->risk_rating] ?? '' }}</dd></div>
                        @endif
                        @if($submission->verification_method)
                        <div><dt class="text-xs" style="color:var(--text-muted);">Method</dt><dd class="flex flex-wrap gap-1 mt-1">@foreach($submission->verification_method as $m)<span class="ds-badge ds-badge-default">{{ str_replace('_', ' ', ucfirst($m)) }}</span>@endforeach</dd></div>
                        @endif
                        @if($submission->agent_notes)
                        <div><dt class="text-xs" style="color:var(--text-muted);">Notes</dt><dd class="text-xs" style="color:var(--text-primary);">{{ $submission->agent_notes }}</dd></div>
                        @endif
                    </dl>
                </div>
            @endif

            {{-- CO verification summary (visible when CO has approved) --}}
            @if(in_array($submission->status, ['approved', 'rejected']) && $submission->co_verified_by)
                <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                    <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Compliance Officer Verification</h3>
                    <dl class="space-y-2 text-sm">
                        <div><dt class="text-xs" style="color:var(--text-muted);">Officer</dt><dd style="color:var(--text-primary);">{{ $submission->coVerifiedBy->name ?? '—' }}</dd></div>
                        <div><dt class="text-xs" style="color:var(--text-muted);">Date</dt><dd style="color:var(--text-primary);">{{ $submission->co_verified_at?->format('d M Y H:i') }}</dd></div>
                        @if($submission->co_notes)
                        <div><dt class="text-xs" style="color:var(--text-muted);">Notes</dt><dd class="text-xs" style="color:var(--text-primary);">{{ $submission->co_notes }}</dd></div>
                        @endif
                        @if($submission->co_signature_data)
                        <div><dt class="text-xs" style="color:var(--text-muted);">Signature</dt><dd><img src="{{ $submission->co_signature_data }}" alt="CO Signature" class="rounded-md" style="max-height:60px; border:1px solid var(--border); padding:0.25rem; background:#fff;"></dd></div>
                        @endif
                    </dl>
                </div>
            @endif

            {{-- Agent approval form (for submitted/under_review/corrections_requested) --}}
            @if(in_array($submission->status, ['submitted', 'under_review', 'corrections_requested']))
                {{-- Verification Checklist --}}
                <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                    <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Verification Checklist</h3>
                    <div class="space-y-3 text-sm">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Identity document(s) proving IDENTITY provided?</label>
                            <div class="flex gap-3">
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.identity_docs" value="yes"> <span class="text-xs" style="color:var(--text-primary);">Yes</span></label>
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.identity_docs" value="no"> <span class="text-xs" style="color:var(--text-primary);">No</span></label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Document(s) proving ADDRESS provided?</label>
                            <div class="flex gap-3">
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.address_docs" value="yes"> <span class="text-xs" style="color:var(--text-primary);">Yes</span></label>
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.address_docs" value="no"> <span class="text-xs" style="color:var(--text-primary);">No</span></label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Document proving AUTHORITY provided?</label>
                            <div class="flex gap-3">
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.authority_docs" value="yes"> <span class="text-xs" style="color:var(--text-primary);">Yes</span></label>
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.authority_docs" value="no"> <span class="text-xs" style="color:var(--text-primary);">No</span></label>
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.authority_docs" value="na"> <span class="text-xs" style="color:var(--text-primary);">N/A</span></label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Is the client a VIP / PEP?</label>
                            <div class="flex gap-3">
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.is_vip" value="yes"> <span class="text-xs" style="color:var(--text-primary);">Yes</span></label>
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.is_vip" value="no"> <span class="text-xs" style="color:var(--text-primary);">No</span></label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Anything suspicious or unusual?</label>
                            <div class="flex gap-3">
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.suspicious" value="yes"> <span class="text-xs" style="color:var(--text-primary);">Yes</span></label>
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.suspicious" value="no"> <span class="text-xs" style="color:var(--text-primary);">No</span></label>
                            </div>
                            <div x-show="checklist.suspicious === 'yes'" x-cloak class="mt-1">
                                <textarea x-model="checklist.suspicious_details" rows="2" class="w-full rounded-md px-3 py-2 text-xs focus:outline-none" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);" placeholder="Details..."></textarea>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Transaction consistent with knowledge of client?</label>
                            <div class="flex gap-3">
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.consistent" value="yes"> <span class="text-xs" style="color:var(--text-primary);">Yes</span></label>
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.consistent" value="no"> <span class="text-xs" style="color:var(--text-primary);">No</span></label>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- TFS Screening Panel --}}
                @include('compliance.fica.partials.tfs-panel', ['submission' => $submission])

                {{-- Agent Approve Form --}}
                <form method="POST" action="{{ route('compliance.fica.agent-approve', $submission) }}">
                    @csrf
                    <input type="hidden" name="checklist[identity_docs]" :value="checklist.identity_docs">
                    <input type="hidden" name="checklist[address_docs]" :value="checklist.address_docs">
                    <input type="hidden" name="checklist[authority_docs]" :value="checklist.authority_docs">
                    <input type="hidden" name="checklist[is_vip]" :value="checklist.is_vip">
                    <input type="hidden" name="checklist[suspicious]" :value="checklist.suspicious">
                    <input type="hidden" name="checklist[suspicious_details]" :value="checklist.suspicious_details">
                    <input type="hidden" name="checklist[consistent]" :value="checklist.consistent">
                    <div class="rounded-md p-5 space-y-4" style="background:var(--surface); border:1px solid var(--border);">
                        <h3 class="text-sm font-bold pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Agent Approval</h3>
                        <p class="text-xs" style="color:var(--text-secondary);">Your approval sends this to the Compliance Officer for final sign-off.</p>

                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Risk Rating *</label>
                            <div class="flex gap-4 text-sm">
                                <label class="flex items-center gap-1"><input type="radio" name="risk_rating" value="1" required> <span class="font-medium" style="color:var(--ds-green,#059669);">Low</span></label>
                                <label class="flex items-center gap-1"><input type="radio" name="risk_rating" value="2"> <span class="font-medium" style="color:var(--ds-amber,#f59e0b);">Medium</span></label>
                                <label class="flex items-center gap-1"><input type="radio" name="risk_rating" value="3"> <span class="font-medium" style="color:var(--ds-crimson,#c41e3a);">High</span></label>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Verification Method *</label>
                            <div class="space-y-1 text-sm" style="color:var(--text-primary);">
                                <label class="flex items-center gap-2"><input type="checkbox" name="verification_method[]" value="whatsapp_video"> WhatsApp video call</label>
                                <label class="flex items-center gap-2"><input type="checkbox" name="verification_method[]" value="physically_met"> Physically met with client</label>
                                <label class="flex items-center gap-2"><input type="checkbox" name="verification_method[]" value="video_call_id"> Video call with ID and newspaper</label>
                                <label class="flex items-center gap-2"><input type="checkbox" name="verification_method[]" value="certified_copies"> Certified copies received</label>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Employee</label>
                            <input type="text" value="{{ auth()->user()->name }}" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);" readonly>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Notes</label>
                            <textarea name="reviewer_notes" rows="3" class="w-full rounded-md px-3 py-2 text-sm focus:outline-none" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);" placeholder="Optional notes..."></textarea>
                        </div>

                        <button type="submit" class="corex-btn-primary w-full justify-center text-sm">
                            Approve (Send to Compliance Officer)
                        </button>
                    </div>
                </form>

                {{-- Request Corrections --}}
                <form method="POST" action="{{ route('compliance.fica.request-corrections', $submission) }}">
                    @csrf
                    <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                        <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Request Corrections</h3>
                        <textarea name="reviewer_notes" rows="3" class="w-full rounded-md px-3 py-2 text-sm focus:outline-none mb-3" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);" placeholder="Describe what needs to be corrected..." required></textarea>
                        <button type="submit" class="corex-btn-primary w-full justify-center text-sm" style="background:var(--ds-amber,#f59e0b); box-shadow:none;">
                            Request Corrections
                        </button>
                    </div>
                </form>

                {{-- Reject --}}
                <form method="POST" action="{{ route('compliance.fica.reject', $submission) }}">
                    @csrf
                    <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                        <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Reject</h3>
                        <textarea name="reviewer_notes" rows="2" class="w-full rounded-md px-3 py-2 text-sm focus:outline-none mb-3" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);" placeholder="Reason for rejection..." required></textarea>
                        <button type="submit" class="corex-btn-primary w-full justify-center text-sm" style="background:var(--ds-crimson,#c41e3a); box-shadow:none;" onclick="return confirm('Are you sure you want to reject this FICA submission?')">
                            Reject
                        </button>
                    </div>
                </form>

                {{-- AT-236 — Escalate to CO (third action for a non-primary-CO reviewer) --}}
                @include('compliance.fica.partials.refer-to-co', ['submission' => $submission, 'referralEnabled' => $referralEnabled ?? true, 'viewerIsPrimaryCo' => $viewerIsPrimaryCo ?? false])
            @endif

            {{-- RO Approvals stage — an authorized reviewer (RO) can review via the CO
                 review screen, or escalate straight from here. --}}
            @if($submission->status === 'agent_approved')
                @if(auth()->user()->isComplianceOfficer())
                    <a href="{{ route('compliance.fica.compliance-review', $submission) }}" class="corex-btn-primary w-full justify-center text-sm mb-3">Open review</a>
                    @include('compliance.fica.partials.refer-to-co', ['submission' => $submission, 'referralEnabled' => $referralEnabled ?? true, 'viewerIsPrimaryCo' => $viewerIsPrimaryCo ?? false])
                @else
                    <div class="rounded-md p-5 text-sm"
                         style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 8%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 25%, transparent); color:var(--text-primary);">
                        <p class="font-semibold">In RO Approvals</p>
                        <p class="mt-1 text-xs" style="color:var(--text-secondary);">The agent has reviewed this submission; it is now with the Reporting / Compliance Officers for their decision.</p>
                    </div>
                @endif
            @endif

            {{-- Final approved summary --}}
            @if($submission->status === 'approved')
                <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                    <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Final Status: Approved</h3>
                    <dl class="space-y-2 text-sm">
                        <div><dt class="text-xs" style="color:var(--text-muted);">Final Approved By</dt><dd style="color:var(--text-primary);">{{ $submission->coVerifiedBy->name ?? $submission->verifiedBy->name ?? '—' }}</dd></div>
                        <div><dt class="text-xs" style="color:var(--text-muted);">Approved At</dt><dd style="color:var(--text-primary);">{{ $submission->co_verified_at?->format('d M Y H:i') ?? $submission->verified_at?->format('d M Y H:i') }}</dd></div>
                        @if($submission->risk_rating)
                        <div><dt class="text-xs" style="color:var(--text-muted);">Risk Rating</dt><dd class="font-semibold" style="color:{{ $riskColors[$submission->risk_rating] ?? 'var(--text-primary)' }};">{{ $riskLabels[$submission->risk_rating] ?? '' }}</dd></div>
                        @endif
                    </dl>
                </div>
            @endif

            @if($submission->status === 'rejected')
                <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);" x-data="{ reopenOpen: false }">
                    <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Rejected</h3>
                    <dl class="space-y-2 text-sm">
                        <div><dt class="text-xs" style="color:var(--text-muted);">Rejected By</dt><dd style="color:var(--text-primary);">{{ $submission->verifiedBy->name ?? '—' }}</dd></div>
                        <div><dt class="text-xs" style="color:var(--text-muted);">Date</dt><dd style="color:var(--text-primary);">{{ $submission->verified_at?->format('d M Y H:i') }}</dd></div>
                        @if($submission->reviewer_notes)
                        <div><dt class="text-xs" style="color:var(--text-muted);">Reason</dt><dd style="color:var(--text-primary);">{{ $submission->reviewer_notes }}</dd></div>
                        @endif
                    </dl>

                    {{-- CO/Admin: Reopen button --}}
                    @if(auth()->user()->isComplianceOfficer() || auth()->user()->isOwnerRole() || auth()->user()->hasPermission('compliance.fica.approve') || in_array(auth()->user()->role, ['admin', 'super_admin']))
                    <div class="mt-4 pt-3" style="border-top:1px solid var(--border);">
                        <button type="button" @click="reopenOpen = true" class="corex-btn-primary text-sm" style="background:var(--ds-amber,#f59e0b); box-shadow:none;">
                            Reopen for Corrections
                        </button>
                    </div>

                    {{-- Reopen modal --}}
                    <template x-teleport="body">
                    <div x-show="reopenOpen" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center p-4" x-transition.opacity>
                        <div class="absolute inset-0" style="background:rgba(0,0,0,0.55);" @click="reopenOpen = false"></div>
                        <div class="relative rounded-md shadow-2xl p-5" style="width:480px; max-width:95vw; background:var(--surface); border:1px solid var(--border);">
                            <h3 class="text-base font-bold mb-2" style="color:var(--text-primary);">Reopen FICA for Corrections</h3>
                            <p class="text-xs mb-4" style="color:var(--text-secondary);">The agent will see your correction notes and be able to upload new documents. The original rejection is preserved in the audit trail.</p>
                            <form method="POST" action="{{ route('compliance.fica.reopen', $submission) }}">
                                @csrf
                                <textarea name="reopen_notes" required rows="4" minlength="10" maxlength="2000"
                                          class="w-full rounded-md text-sm px-3 py-2 mb-3 focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                          placeholder="What needs to be corrected (min 10 characters)..."></textarea>
                                <div class="flex justify-end gap-3">
                                    <button type="button" @click="reopenOpen = false" class="corex-btn-outline text-sm">Cancel</button>
                                    <button type="submit" class="corex-btn-primary text-sm" style="background:var(--ds-amber,#f59e0b); box-shadow:none;">Reopen Submission</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    </template>
                    @else
                    {{-- Agent: helpful note --}}
                    <div class="mt-4 pt-3" style="border-top:1px solid var(--border);">
                        <p class="text-xs" style="color:var(--text-secondary);">Need to fix this? Please contact your compliance officer to reopen this submission for corrections.</p>
                    </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>

<script>
    function ficaReview() {
        return {
            checklist: {
                identity_docs: '', address_docs: '', authority_docs: '',
                is_vip: '', suspicious: '', suspicious_details: '', consistent: '',
            }
        };
    }

    function ficaCopyToClipboard(text, btn) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        var span = btn.querySelector('span');
        if (span) { span.textContent = 'Copied!'; setTimeout(function() { span.textContent = 'Copy Link'; }, 2000); }
    }
</script>
@endsection
