@extends('layouts.corex-app')

@section('corex-content')
<div class="p-6 lg:p-8" x-data="ficaReview()">
    {{-- Header --}}
    <div class="mb-6">
        <a href="{{ route('compliance.fica.index') }}" class="text-sm text-slate-500 hover:text-slate-700 mb-2 inline-block">&larr; Back to Compliance</a>
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold text-slate-900">FICA Review</h1>
            @php
                $colors = ['draft' => 'bg-slate-100 text-slate-600', 'submitted' => 'bg-blue-100 text-blue-700', 'under_review' => 'bg-yellow-100 text-yellow-700', 'corrections_requested' => 'bg-amber-100 text-amber-700', 'approved' => 'bg-emerald-100 text-emerald-700', 'rejected' => 'bg-red-100 text-red-700'];
            @endphp
            <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold {{ $colors[$submission->status] ?? 'bg-slate-100 text-slate-600' }}">
                {{ $submission->status_label }}
            </span>
        </div>
        <p class="text-sm text-slate-500 mt-1">
            {{ $submission->contact ? $submission->contact->full_name : 'Unknown contact' }}
            — Requested by {{ $submission->requestedBy->name ?? 'Unknown' }} on {{ $submission->created_at->format('d M Y') }}
        </p>
    </div>

    @if(session('success'))
        <div class="mb-4 p-3 bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">{{ session('success') }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- LEFT PANEL: Submitted Data --}}
        <div class="lg:col-span-2 space-y-4">
            @php $data = $submission->form_data ?? []; @endphp

            {{-- Personal Details --}}
            <div class="bg-white border border-slate-200 p-5">
                <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-teal-500">Personal Details</h3>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="text-slate-400 text-xs">Full Name</dt><dd class="text-slate-900 font-medium">{{ $data['full_name'] ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">ID / Passport</dt><dd class="text-slate-900 font-medium">{{ $data['id_number'] ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Date of Birth</dt><dd class="text-slate-900">{{ $data['date_of_birth'] ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Nationality</dt><dd class="text-slate-900">{{ $data['nationality'] ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Phone</dt><dd class="text-slate-900">{{ $data['phone'] ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Email</dt><dd class="text-slate-900">{{ $data['email'] ?? '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">Residential Address</dt><dd class="text-slate-900">{{ $data['residential_address'] ?? '—' }}</dd></div>
                    @if(!empty($data['postal_address']))
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">Postal Address</dt><dd class="text-slate-900">{{ $data['postal_address'] }}</dd></div>
                    @endif
                </dl>
            </div>

            {{-- Source of Funds --}}
            <div class="bg-white border border-slate-200 p-5">
                <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-teal-500">Source of Funds</h3>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">Payment Method</dt><dd class="text-slate-900">{{ $data['payment_method'] ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Cash Over R50,000</dt><dd class="text-slate-900 font-semibold {{ ($data['cash_over_50k'] ?? '') === 'yes' ? 'text-red-600' : '' }}">{{ ucfirst($data['cash_over_50k'] ?? '—') }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Occupation</dt><dd class="text-slate-900">{{ $data['occupation'] ?? '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">Source of Income</dt><dd class="text-slate-900">{{ $data['source_of_income'] ?? '—' }}</dd></div>
                    @if(!empty($data['employer']))
                    <div><dt class="text-slate-400 text-xs">Employer</dt><dd class="text-slate-900">{{ $data['employer'] }}</dd></div>
                    @endif
                </dl>
            </div>

            {{-- Purpose & Entity --}}
            <div class="bg-white border border-slate-200 p-5">
                <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-teal-500">Transaction & Entity</h3>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="text-slate-400 text-xs">Purpose</dt><dd class="text-slate-900">{{ $data['transaction_purpose'] ?? '—' }}{{ !empty($data['purpose_other']) ? ': ' . $data['purpose_other'] : '' }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Entity Type</dt><dd class="text-slate-900 capitalize">{{ $submission->entity_type }}</dd></div>
                </dl>

                @if($submission->entity_type === 'company' && (!empty($data['company_name']) || !empty($data['directors'])))
                    <div class="mt-3 pt-3 border-t border-slate-100 text-sm">
                        <p class="font-semibold text-slate-700 mb-1">Company: {{ $data['company_name'] ?? '' }} ({{ $data['company_reg_number'] ?? '' }})</p>
                        <p class="text-slate-600 text-xs">{{ $data['company_address'] ?? '' }}</p>
                        @if(!empty($data['directors']))
                            <p class="font-semibold text-slate-700 mt-2 mb-1 text-xs">Directors:</p>
                            @foreach($data['directors'] as $dir)
                                <p class="text-slate-600 text-xs">{{ $dir['name'] ?? '' }} — {{ $dir['id_number'] ?? '' }}</p>
                            @endforeach
                        @endif
                    </div>
                @endif

                @if($submission->entity_type === 'trust' && (!empty($data['trust_name']) || !empty($data['trustees'])))
                    <div class="mt-3 pt-3 border-t border-slate-100 text-sm">
                        <p class="font-semibold text-slate-700 mb-1">Trust: {{ $data['trust_name'] ?? '' }} ({{ $data['trust_number'] ?? '' }})</p>
                        @if(!empty($data['trustees']))
                            <p class="font-semibold text-slate-700 mt-2 mb-1 text-xs">Trustees:</p>
                            @foreach($data['trustees'] as $tr)
                                <p class="text-slate-600 text-xs">{{ $tr['name'] ?? '' }} — {{ $tr['id_number'] ?? '' }}</p>
                            @endforeach
                        @endif
                        @if(!empty($data['beneficiaries']))
                            <p class="font-semibold text-slate-700 mt-2 mb-1 text-xs">Beneficiaries:</p>
                            @foreach($data['beneficiaries'] as $bn)
                                <p class="text-slate-600 text-xs">{{ $bn['name'] ?? '' }} — {{ $bn['id_number'] ?? '' }}</p>
                            @endforeach
                        @endif
                    </div>
                @endif

                @if($submission->entity_type === 'partnership' && (!empty($data['partnership_name']) || !empty($data['partners'])))
                    <div class="mt-3 pt-3 border-t border-slate-100 text-sm">
                        <p class="font-semibold text-slate-700 mb-1">Partnership: {{ $data['partnership_name'] ?? '' }}</p>
                        @if(!empty($data['partners']))
                            <p class="font-semibold text-slate-700 mt-2 mb-1 text-xs">Partners:</p>
                            @foreach($data['partners'] as $pt)
                                <p class="text-slate-600 text-xs">{{ $pt['name'] ?? '' }} — {{ $pt['id_number'] ?? '' }}</p>
                            @endforeach
                        @endif
                        @if(!empty($data['authority_reference']))
                            <p class="text-xs text-slate-500 mt-1">Authority ref: {{ $data['authority_reference'] }}</p>
                        @endif
                    </div>
                @endif
            </div>

            {{-- PEP --}}
            <div class="bg-white border border-slate-200 p-5">
                <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-teal-500">Politically Exposed Person</h3>
                @php
                    $pepYes = ($data['pep_domestic'] ?? '') === 'yes' || ($data['pep_foreign'] ?? '') === 'yes' || ($data['pep_family'] ?? '') === 'yes' || ($data['pep_associate'] ?? '') === 'yes';
                @endphp
                <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    <div><dt class="text-slate-400 text-xs">Domestic PEP</dt><dd class="{{ ($data['pep_domestic'] ?? '') === 'yes' ? 'text-red-600 font-semibold' : 'text-slate-900' }}">{{ ucfirst($data['pep_domestic'] ?? '—') }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Foreign PEP</dt><dd class="{{ ($data['pep_foreign'] ?? '') === 'yes' ? 'text-red-600 font-semibold' : 'text-slate-900' }}">{{ ucfirst($data['pep_foreign'] ?? '—') }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Family of PEP</dt><dd class="{{ ($data['pep_family'] ?? '') === 'yes' ? 'text-red-600 font-semibold' : 'text-slate-900' }}">{{ ucfirst($data['pep_family'] ?? '—') }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Associate of PEP</dt><dd class="{{ ($data['pep_associate'] ?? '') === 'yes' ? 'text-red-600 font-semibold' : 'text-slate-900' }}">{{ ucfirst($data['pep_associate'] ?? '—') }}</dd></div>
                </dl>
                @if($pepYes && !empty($data['pep_details']))
                    <div class="mt-3 p-3 bg-red-50 border border-red-200 text-red-800 text-sm">
                        <strong>PEP Details:</strong> {{ $data['pep_details'] }}
                    </div>
                @endif
            </div>

            {{-- Documents --}}
            <div class="bg-white border border-slate-200 p-5">
                <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-teal-500">Uploaded Documents</h3>
                @forelse($submission->documents as $doc)
                    <div class="flex items-center justify-between py-2 {{ !$loop->last ? 'border-b border-slate-100' : '' }}">
                        <div>
                            <span class="text-xs font-semibold text-slate-500 uppercase">{{ $doc->document_type_label }}</span>
                            <p class="text-sm text-slate-900">{{ $doc->file_name }}</p>
                            <p class="text-xs text-slate-400">{{ number_format($doc->file_size / 1024) }} KB — {{ $doc->uploaded_at?->format('d M Y H:i') }}</p>
                        </div>
                        <a href="{{ Storage::url($doc->file_path) }}" target="_blank" class="text-teal-600 hover:text-teal-800 text-xs font-medium">View</a>
                    </div>
                @empty
                    <p class="text-slate-400 text-sm">No documents uploaded.</p>
                @endforelse
            </div>

            {{-- Signature --}}
            @if($submission->signature_data)
            <div class="bg-white border border-slate-200 p-5">
                <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-teal-500">Electronic Signature</h3>
                <img src="{{ $submission->signature_data }}" alt="Recipient Signature" style="max-height: 120px; border: 1px solid #e2e8f0; padding: 0.5rem; background: #fff;">
                <p class="text-xs text-slate-400 mt-1">Signed at: {{ $submission->signed_at?->format('d M Y H:i') }}</p>
            </div>
            @endif
        </div>

        {{-- RIGHT PANEL: Verification --}}
        <div class="space-y-4">
            @if(in_array($submission->status, ['submitted', 'under_review', 'corrections_requested']))
                {{-- Verification Checklist --}}
                <div class="bg-white border border-slate-200 p-5">
                    <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-teal-500">Verification Checklist</h3>
                    <div class="space-y-2">
                        <label class="flex items-start gap-2 text-sm"><input type="checkbox" x-model="checklist.id_verified" class="mt-0.5"> <span>Identity document verified</span></label>
                        <label class="flex items-start gap-2 text-sm"><input type="checkbox" x-model="checklist.address_verified" class="mt-0.5"> <span>Address proof verified (&lt; 3 months)</span></label>
                        <label class="flex items-start gap-2 text-sm"><input type="checkbox" x-model="checklist.source_verified" class="mt-0.5"> <span>Source of funds verified</span></label>
                        <label class="flex items-start gap-2 text-sm"><input type="checkbox" x-model="checklist.authority_verified" class="mt-0.5"> <span>Authority document verified (if applicable)</span></label>
                        <label class="flex items-start gap-2 text-sm"><input type="checkbox" x-model="checklist.pep_screened" class="mt-0.5"> <span>PEP screening completed</span></label>
                    </div>
                </div>

                {{-- Approve Form --}}
                <form method="POST" action="{{ route('compliance.fica.approve', $submission) }}">
                    @csrf
                    <div class="bg-white border border-slate-200 p-5 space-y-4">
                        <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-teal-500">Approval</h3>

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
                                <label class="flex items-center gap-2"><input type="checkbox" name="verification_method[]" value="physically_met"> Physically met with client</label>
                                <label class="flex items-center gap-2"><input type="checkbox" name="verification_method[]" value="whatsapp_video"> WhatsApp video call</label>
                                <label class="flex items-center gap-2"><input type="checkbox" name="verification_method[]" value="video_call_id"> Video call with ID and newspaper</label>
                                <label class="flex items-center gap-2"><input type="checkbox" name="verification_method[]" value="certified_copies"> Certified copies received</label>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Verifying Employee</label>
                            <input type="text" value="{{ auth()->user()->name }}" class="w-full px-3 py-2 border border-slate-200 text-sm bg-slate-50" readonly>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Notes</label>
                            <textarea name="reviewer_notes" rows="3" class="w-full px-3 py-2 border border-slate-300 text-sm focus:outline-none focus:border-teal-500" placeholder="Optional notes..."></textarea>
                        </div>

                        <button type="submit" class="w-full px-4 py-2 bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 transition">
                            Approve
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

            {{-- Approved/Rejected summary --}}
            @if(in_array($submission->status, ['approved', 'rejected']))
                <div class="bg-white border border-slate-200 p-5">
                    <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-teal-500">Review Summary</h3>
                    <dl class="space-y-2 text-sm">
                        <div><dt class="text-slate-400 text-xs">Status</dt><dd class="font-semibold {{ $submission->status === 'approved' ? 'text-emerald-600' : 'text-red-600' }}">{{ $submission->status_label }}</dd></div>
                        <div><dt class="text-slate-400 text-xs">Reviewed By</dt><dd class="text-slate-900">{{ $submission->verifiedBy->name ?? '—' }}</dd></div>
                        <div><dt class="text-slate-400 text-xs">Reviewed At</dt><dd class="text-slate-900">{{ $submission->verified_at?->format('d M Y H:i') }}</dd></div>
                        @if($submission->risk_rating)
                        <div><dt class="text-slate-400 text-xs">Risk Rating</dt><dd class="font-semibold {{ [1 => 'text-emerald-600', 2 => 'text-amber-600', 3 => 'text-red-600'][$submission->risk_rating] ?? '' }}">{{ [1 => 'Low', 2 => 'Medium', 3 => 'High'][$submission->risk_rating] ?? $submission->risk_rating }}</dd></div>
                        @endif
                        @if($submission->verification_method)
                        <div>
                            <dt class="text-slate-400 text-xs">Verification Method</dt>
                            <dd class="text-slate-900">
                                @foreach($submission->verification_method as $method)
                                    <span class="inline-block px-2 py-0.5 bg-slate-100 text-slate-600 text-xs mr-1 mb-1">{{ str_replace('_', ' ', ucfirst($method)) }}</span>
                                @endforeach
                            </dd>
                        </div>
                        @endif
                        @if($submission->reviewer_notes)
                        <div><dt class="text-slate-400 text-xs">Notes</dt><dd class="text-slate-900">{{ $submission->reviewer_notes }}</dd></div>
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
                id_verified: false,
                address_verified: false,
                source_verified: false,
                authority_verified: false,
                pep_screened: false,
            }
        };
    }
</script>
@endsection
