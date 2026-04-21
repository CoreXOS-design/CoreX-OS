@extends('layouts.corex-app')

@section('corex-content')
<div class="p-6 lg:p-8" x-data="ficaReview()">
    {{-- Header --}}
    <div class="mb-6">
        <a href="{{ route('compliance.fica.index') }}" class="text-sm text-slate-500 hover:text-slate-700 mb-2 inline-block">&larr; Back to Compliance</a>
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold text-slate-900">FICA Review</h1>
            @php
                $colors = ['draft' => 'bg-slate-100 text-slate-600', 'submitted' => 'bg-blue-100 text-blue-700', 'under_review' => 'bg-yellow-100 text-yellow-700', 'agent_approved' => 'bg-indigo-100 text-indigo-700', 'corrections_requested' => 'bg-amber-100 text-amber-700', 'approved' => 'bg-emerald-100 text-emerald-700', 'rejected' => 'bg-red-100 text-red-700'];
            @endphp
            <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold {{ $colors[$submission->status] ?? 'bg-slate-100 text-slate-600' }}">
                {{ $submission->status_label }}
            </span>
            @if($submission->isWetInk())
                <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold" style="background:rgba(245,158,11,0.12); color:#d97706; border-radius:3px;">
                    Wet-Ink Intake — Received {{ $submission->wet_ink_received_date?->format('d M Y') }}
                </span>
            @else
                <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold" style="background:rgba(0,212,170,0.12); color:#00d4aa; border-radius:3px;">
                    Online Intake
                </span>
            @endif
        </div>
        <p class="text-sm text-slate-500 mt-1">
            {{ $submission->contact ? $submission->contact->full_name : 'Unknown contact' }}
            — Requested by {{ $submission->requestedBy->name ?? 'Unknown' }} on {{ $submission->created_at->format('d M Y') }}
        </p>

        {{-- Recipient Form Link (online intake only) --}}
        @if(!$submission->isWetInk() && $submission->token)
        <div class="mt-3 flex items-center gap-2">
            <span class="text-xs font-semibold text-slate-500 whitespace-nowrap">Recipient Form Link:</span>
            <input type="text" value="{{ url('/fica/' . $submission->token) }}" readonly
                   class="flex-1 px-2 py-1 border border-slate-200 bg-slate-50 text-xs text-slate-700 select-all focus:outline-none focus:border-teal-500">
            <button type="button"
                    onclick="ficaCopyToClipboard('{{ url('/fica/' . $submission->token) }}', this)"
                    class="inline-flex items-center gap-1 px-2.5 py-1 border border-slate-300 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition whitespace-nowrap">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.5a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m0 0a2.625 2.625 0 1 1 5.25 0" /></svg>
                <span>Copy Link</span>
            </button>
        </div>
        @endif

        {{-- Action buttons row --}}
        <div class="mt-3 flex gap-2">
            @if($submission->status === 'approved')
                <a href="{{ route('compliance.fica.pdf', $submission) }}" target="_blank" class="inline-flex items-center gap-1 px-3 py-1.5 bg-slate-900 text-white text-xs font-semibold hover:bg-slate-800 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    Download PDF
                </a>
            @endif
            @if($submission->status === 'agent_approved' && auth()->user()->isComplianceOfficer())
                <a href="{{ route('compliance.fica.compliance-review', $submission) }}" class="inline-flex items-center gap-1 px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold hover:bg-indigo-700 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                    Compliance Review
                </a>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div class="mb-4 p-3 bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">{{ session('success') }}</div>
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

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- LEFT PANEL: Submitted Data --}}
        <div class="lg:col-span-2 space-y-4">
            @if($submission->isWetInk())
                {{-- Wet-ink: contact basics + uploaded documents --}}
                <div class="bg-white border border-slate-200 p-5">
                    <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2" style="border-bottom:2px solid #d97706; font-family:'Plus Jakarta Sans',sans-serif;">Client Details (from contact record)</h3>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div><span class="text-xs text-slate-400">Name</span><div class="text-slate-900 font-medium">{{ $personal['first_name'] ?? '' }} {{ $personal['last_name'] ?? '' }}</div></div>
                        <div><span class="text-xs text-slate-400">ID Number</span><div class="text-slate-900">{{ $personal['id_number'] ?? 'Not set' }}</div></div>
                        <div><span class="text-xs text-slate-400">Email</span><div class="text-slate-900">{{ $personal['email'] ?? 'Not set' }}</div></div>
                        <div><span class="text-xs text-slate-400">Phone</span><div class="text-slate-900">{{ $personal['cell'] ?? 'Not set' }}</div></div>
                        <div><span class="text-xs text-slate-400">Entity Type</span><div class="text-slate-900 capitalize">{{ $entity['type'] ?? $submission->entity_type ?? '—' }}</div></div>
                        <div><span class="text-xs text-slate-400">Received By</span><div class="text-slate-900">{{ $submission->form_data['intake']['received_by'] ?? '—' }}</div></div>
                    </div>
                </div>

                {{-- Uploaded documents --}}
                <div class="bg-white border border-slate-200 p-5">
                    <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2" style="border-bottom:2px solid #d97706; font-family:'Plus Jakarta Sans',sans-serif;">Uploaded Documents</h3>
                    @if($submission->documents->isEmpty())
                        <p class="text-sm text-slate-400">No documents uploaded.</p>
                    @else
                        <div class="space-y-3">
                            @foreach($submission->documents as $doc)
                            <div style="border:1px solid var(--border, #e2e8f0); border-radius:3px; overflow:hidden;">
                                <div class="flex items-center justify-between px-4 py-2" style="background:var(--surface-2, #f8fafc);">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-semibold text-slate-700">{{ $doc->document_type_label }}</span>
                                        <span class="text-[10px] text-slate-400">{{ $doc->file_name }}</span>
                                    </div>
                                    <a href="{{ asset('storage/' . $doc->file_path) }}" target="_blank" class="text-xs font-medium" style="color:#00d4aa; text-decoration:none;">Open in new tab</a>
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
                <div class="p-4 text-sm" style="background:rgba(245,158,11,0.06); border:1px solid rgba(245,158,11,0.2); border-radius:3px; color:#92400e;">
                    Client data captured on signed paper form — see uploaded FICA form above for full client responses.
                </div>
            @else
                @include('compliance.fica.partials.submitted-data', ['submission' => $submission, 'personal' => $personal, 'entity' => $entity, 'service' => $service, 'pepData' => $pepData, 'principalData' => $principalData, 'repData' => $repData, 'declData' => $declData])
            @endif
        </div>

        {{-- RIGHT PANEL: Verification --}}
        <div class="space-y-4">
            {{-- Agent verification summary (visible when agent has approved) --}}
            @if(in_array($submission->status, ['agent_approved', 'approved', 'rejected']) && $submission->agent_verified_by)
                <div class="bg-white border border-slate-200 p-5">
                    <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-indigo-500">Agent Verification</h3>
                    <dl class="space-y-2 text-sm">
                        <div><dt class="text-slate-400 text-xs">Agent</dt><dd class="text-slate-900">{{ $submission->agentVerifiedBy->name ?? '—' }}</dd></div>
                        <div><dt class="text-slate-400 text-xs">Date</dt><dd class="text-slate-900">{{ $submission->agent_verified_at?->format('d M Y H:i') }}</dd></div>
                        @if($submission->risk_rating)
                        <div><dt class="text-slate-400 text-xs">Risk Rating</dt><dd class="font-semibold {{ [1 => 'text-emerald-600', 2 => 'text-amber-600', 3 => 'text-red-600'][$submission->risk_rating] ?? '' }}">{{ [1 => 'Low', 2 => 'Medium', 3 => 'High'][$submission->risk_rating] ?? '' }}</dd></div>
                        @endif
                        @if($submission->verification_method)
                        <div><dt class="text-slate-400 text-xs">Method</dt><dd>@foreach($submission->verification_method as $m)<span class="inline-block px-1.5 py-0.5 bg-slate-100 text-slate-600 text-xs mr-1 mb-1">{{ str_replace('_', ' ', ucfirst($m)) }}</span>@endforeach</dd></div>
                        @endif
                        @if($submission->agent_notes)
                        <div><dt class="text-slate-400 text-xs">Notes</dt><dd class="text-slate-900 text-xs">{{ $submission->agent_notes }}</dd></div>
                        @endif
                    </dl>
                </div>
            @endif

            {{-- CO verification summary (visible when CO has approved) --}}
            @if(in_array($submission->status, ['approved', 'rejected']) && $submission->co_verified_by)
                <div class="bg-white border border-slate-200 p-5">
                    <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-emerald-500">Compliance Officer Verification</h3>
                    <dl class="space-y-2 text-sm">
                        <div><dt class="text-slate-400 text-xs">Officer</dt><dd class="text-slate-900">{{ $submission->coVerifiedBy->name ?? '—' }}</dd></div>
                        <div><dt class="text-slate-400 text-xs">Date</dt><dd class="text-slate-900">{{ $submission->co_verified_at?->format('d M Y H:i') }}</dd></div>
                        @if($submission->co_notes)
                        <div><dt class="text-slate-400 text-xs">Notes</dt><dd class="text-slate-900 text-xs">{{ $submission->co_notes }}</dd></div>
                        @endif
                        @if($submission->co_signature_data)
                        <div><dt class="text-slate-400 text-xs">Signature</dt><dd><img src="{{ $submission->co_signature_data }}" alt="CO Signature" style="max-height: 60px; border: 1px solid #e2e8f0; padding: 0.25rem;"></dd></div>
                        @endif
                    </dl>
                </div>
            @endif

            {{-- Agent approval form (for submitted/under_review/corrections_requested) --}}
            @if(in_array($submission->status, ['submitted', 'under_review', 'corrections_requested']))
                {{-- Verification Checklist --}}
                <div class="bg-white border border-slate-200 p-5">
                    <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-teal-500">Verification Checklist</h3>
                    <div class="space-y-3 text-sm">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Identity document(s) proving IDENTITY provided?</label>
                            <div class="flex gap-3">
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.identity_docs" value="yes"> <span class="text-xs">Yes</span></label>
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.identity_docs" value="no"> <span class="text-xs">No</span></label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Document(s) proving ADDRESS provided?</label>
                            <div class="flex gap-3">
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.address_docs" value="yes"> <span class="text-xs">Yes</span></label>
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.address_docs" value="no"> <span class="text-xs">No</span></label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Document proving AUTHORITY provided?</label>
                            <div class="flex gap-3">
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.authority_docs" value="yes"> <span class="text-xs">Yes</span></label>
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.authority_docs" value="no"> <span class="text-xs">No</span></label>
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.authority_docs" value="na"> <span class="text-xs">N/A</span></label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Is the client a VIP / PEP?</label>
                            <div class="flex gap-3">
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.is_vip" value="yes"> <span class="text-xs">Yes</span></label>
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.is_vip" value="no"> <span class="text-xs">No</span></label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Anything suspicious or unusual?</label>
                            <div class="flex gap-3">
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.suspicious" value="yes"> <span class="text-xs">Yes</span></label>
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.suspicious" value="no"> <span class="text-xs">No</span></label>
                            </div>
                            <div x-show="checklist.suspicious === 'yes'" x-cloak class="mt-1">
                                <textarea x-model="checklist.suspicious_details" rows="2" class="w-full px-2 py-1 border border-slate-300 text-xs focus:outline-none focus:border-teal-500" placeholder="Details..."></textarea>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Transaction consistent with knowledge of client?</label>
                            <div class="flex gap-3">
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.consistent" value="yes"> <span class="text-xs">Yes</span></label>
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.consistent" value="no"> <span class="text-xs">No</span></label>
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
                    <div class="bg-white border border-slate-200 p-5 space-y-4">
                        <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-teal-500">Agent Approval</h3>
                        <p class="text-xs text-slate-500">Your approval sends this to the Compliance Officer for final sign-off.</p>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Risk Rating *</label>
                            <div class="flex gap-4 text-sm">
                                <label class="flex items-center gap-1"><input type="radio" name="risk_rating" value="1" required> <span class="text-emerald-600 font-medium">Low</span></label>
                                <label class="flex items-center gap-1"><input type="radio" name="risk_rating" value="2"> <span class="text-amber-600 font-medium">Medium</span></label>
                                <label class="flex items-center gap-1"><input type="radio" name="risk_rating" value="3"> <span class="text-red-600 font-medium">High</span></label>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Verification Method *</label>
                            <div class="space-y-1 text-sm">
                                <label class="flex items-center gap-2"><input type="checkbox" name="verification_method[]" value="whatsapp_video"> WhatsApp video call</label>
                                <label class="flex items-center gap-2"><input type="checkbox" name="verification_method[]" value="physically_met"> Physically met with client</label>
                                <label class="flex items-center gap-2"><input type="checkbox" name="verification_method[]" value="video_call_id"> Video call with ID and newspaper</label>
                                <label class="flex items-center gap-2"><input type="checkbox" name="verification_method[]" value="certified_copies"> Certified copies received</label>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Employee</label>
                            <input type="text" value="{{ auth()->user()->name }}" class="w-full px-3 py-2 border border-slate-200 text-sm bg-slate-50" readonly>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Notes</label>
                            <textarea name="reviewer_notes" rows="3" class="w-full px-3 py-2 border border-slate-300 text-sm focus:outline-none focus:border-teal-500" placeholder="Optional notes..."></textarea>
                        </div>

                        <button type="submit" class="w-full px-4 py-2 bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition">
                            Approve (Send to Compliance Officer)
                        </button>
                    </div>
                </form>

                {{-- Request Corrections --}}
                <form method="POST" action="{{ route('compliance.fica.request-corrections', $submission) }}">
                    @csrf
                    <div class="bg-white border border-slate-200 p-5">
                        <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-amber-500">Request Corrections</h3>
                        <textarea name="reviewer_notes" rows="3" class="w-full px-3 py-2 border border-slate-300 text-sm focus:outline-none focus:border-amber-500 mb-3" placeholder="Describe what needs to be corrected..." required></textarea>
                        <button type="submit" class="w-full px-4 py-2 bg-amber-500 text-white text-sm font-semibold hover:bg-amber-600 transition">
                            Request Corrections
                        </button>
                    </div>
                </form>

                {{-- Reject --}}
                <form method="POST" action="{{ route('compliance.fica.reject', $submission) }}">
                    @csrf
                    <div class="bg-white border border-slate-200 p-5">
                        <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-red-500">Reject</h3>
                        <textarea name="reviewer_notes" rows="2" class="w-full px-3 py-2 border border-slate-300 text-sm focus:outline-none focus:border-red-500 mb-3" placeholder="Reason for rejection..." required></textarea>
                        <button type="submit" class="w-full px-4 py-2 bg-red-600 text-white text-sm font-semibold hover:bg-red-700 transition" onclick="return confirm('Are you sure you want to reject this FICA submission?')">
                            Reject
                        </button>
                    </div>
                </form>
            @endif

            {{-- Awaiting CO — message for non-CO users --}}
            @if($submission->status === 'agent_approved' && !auth()->user()->isComplianceOfficer())
                <div class="bg-indigo-50 border border-indigo-200 p-5 text-sm text-indigo-800">
                    <p class="font-semibold">Awaiting Compliance Officer Review</p>
                    <p class="mt-1 text-xs">This submission has been approved by the agent and is now waiting for a compliance officer to perform the final review.</p>
                </div>
            @endif

            {{-- Final approved/rejected summary --}}
            @if($submission->status === 'approved')
                <div class="bg-white border border-slate-200 p-5">
                    <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-emerald-500">Final Status: Approved</h3>
                    <dl class="space-y-2 text-sm">
                        <div><dt class="text-slate-400 text-xs">Final Approved By</dt><dd class="text-slate-900">{{ $submission->coVerifiedBy->name ?? $submission->verifiedBy->name ?? '—' }}</dd></div>
                        <div><dt class="text-slate-400 text-xs">Approved At</dt><dd class="text-slate-900">{{ $submission->co_verified_at?->format('d M Y H:i') ?? $submission->verified_at?->format('d M Y H:i') }}</dd></div>
                        @if($submission->risk_rating)
                        <div><dt class="text-slate-400 text-xs">Risk Rating</dt><dd class="font-semibold {{ [1 => 'text-emerald-600', 2 => 'text-amber-600', 3 => 'text-red-600'][$submission->risk_rating] ?? '' }}">{{ [1 => 'Low', 2 => 'Medium', 3 => 'High'][$submission->risk_rating] ?? '' }}</dd></div>
                        @endif
                    </dl>
                </div>
            @endif

            @if($submission->status === 'rejected')
                <div class="bg-white border border-slate-200 p-5">
                    <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-red-500">Rejected</h3>
                    <dl class="space-y-2 text-sm">
                        <div><dt class="text-slate-400 text-xs">Rejected By</dt><dd class="text-slate-900">{{ $submission->verifiedBy->name ?? '—' }}</dd></div>
                        <div><dt class="text-slate-400 text-xs">Date</dt><dd class="text-slate-900">{{ $submission->verified_at?->format('d M Y H:i') }}</dd></div>
                        @if($submission->reviewer_notes)
                        <div><dt class="text-slate-400 text-xs">Reason</dt><dd class="text-slate-900">{{ $submission->reviewer_notes }}</dd></div>
                        @endif
                    </dl>
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
