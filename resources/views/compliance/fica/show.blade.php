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

            {{-- Person Completing Form --}}
            <div class="bg-white border border-slate-200 p-5">
                <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-teal-500">Person Completing Form</h3>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="text-slate-400 text-xs">Full Name</dt><dd class="text-slate-900 font-medium">{{ $personal['full_name'] ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">ID / Passport</dt><dd class="text-slate-900 font-medium">{{ $personal['id_number'] ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">SA Citizen/Resident</dt><dd class="text-slate-900">{{ ucfirst($personal['sa_citizen'] ?? '—') }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Phone</dt><dd class="text-slate-900">{{ $personal['phone'] ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Email</dt><dd class="text-slate-900">{{ $personal['email'] ?? '—' }}</dd></div>
                    @if(!empty($personal['tax_number']))
                    <div><dt class="text-slate-400 text-xs">Tax Number</dt><dd class="text-slate-900">{{ $personal['tax_number'] }}</dd></div>
                    @endif
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">Residential Address</dt><dd class="text-slate-900">{{ $personal['residential_address'] ?? '—' }}</dd></div>
                </dl>
            </div>

            {{-- Entity Details --}}
            @if($submission->entity_type !== 'natural')
            <div class="bg-white border border-slate-200 p-5">
                <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-teal-500">
                    {{ ['company' => 'Company / CC', 'trust' => 'Trust', 'partnership' => 'Partnership'][$submission->entity_type] ?? 'Entity' }} Details
                </h3>

                @if($submission->entity_type === 'company')
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="text-slate-400 text-xs">Company Name</dt><dd class="text-slate-900 font-medium">{{ $entity['company_name'] ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Registration No</dt><dd class="text-slate-900">{{ $entity['company_reg_number'] ?? '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">SA Presence</dt><dd class="text-slate-900">{{ $entity['company_sa_presence'] ?? '—' }}</dd></div>
                    @if(!empty($entity['company_stock_exchange']))<div><dt class="text-slate-400 text-xs">Stock Exchange</dt><dd class="text-slate-900">{{ $entity['company_stock_exchange'] }}</dd></div>@endif
                    @if(!empty($entity['company_tax_number']))<div><dt class="text-slate-400 text-xs">Tax Number</dt><dd class="text-slate-900">{{ $entity['company_tax_number'] }}</dd></div>@endif
                    @if(!empty($entity['company_vat_number']))<div><dt class="text-slate-400 text-xs">VAT</dt><dd class="text-slate-900">{{ $entity['company_vat_number'] }}</dd></div>@endif
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">Registered Address</dt><dd class="text-slate-900">{{ $entity['company_address'] ?? '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">Authority to Act</dt><dd class="text-slate-900">{{ $entity['company_authority_source'] ?? '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">Business Description</dt><dd class="text-slate-900">{{ $entity['company_business_description'] ?? '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">Ownership Structure</dt><dd class="text-slate-900">{{ $entity['company_ownership_structure'] ?? '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">BO Method</dt><dd class="text-slate-900">{{ str_replace('_', ' ', ucfirst($entity['beneficial_owner_method'] ?? '—')) }}</dd></div>
                </dl>
                @if(!empty($entity['beneficial_owners']))
                    <div class="mt-3 pt-3 border-t border-slate-100">
                        <p class="text-xs font-semibold text-slate-700 mb-2">Beneficial Owners:</p>
                        @foreach($entity['beneficial_owners'] as $bo)
                        <div class="text-xs text-slate-600 mb-1">{{ $bo['name'] ?? '' }} — ID: {{ $bo['id_number'] ?? '' }} — {{ $bo['address'] ?? '' }}</div>
                        @endforeach
                    </div>
                @endif
                @endif

                @if($submission->entity_type === 'trust')
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="text-slate-400 text-xs">Trust Name</dt><dd class="text-slate-900 font-medium">{{ $entity['trust_name'] ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Master's Ref</dt><dd class="text-slate-900">{{ $entity['trust_master_ref'] ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Master of High Court</dt><dd class="text-slate-900">{{ $entity['trust_master_court'] ?? '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">SA Presence</dt><dd class="text-slate-900">{{ $entity['trust_sa_presence'] ?? '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">Authority to Act</dt><dd class="text-slate-900">{{ $entity['trust_authority_source'] ?? '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">Trust Purpose</dt><dd class="text-slate-900">{{ $entity['trust_purpose'] ?? '—' }}</dd></div>
                </dl>
                <div class="mt-3 pt-3 border-t border-slate-100 text-xs">
                    <p class="font-semibold text-slate-700 mb-1">Donor: {{ $entity['donor_name'] ?? '' }} — ID: {{ $entity['donor_id_number'] ?? '' }}</p>
                    <p class="text-slate-600">{{ $entity['donor_address'] ?? '' }}</p>
                </div>
                @if(!empty($entity['trustees']))
                    <div class="mt-2 pt-2 border-t border-slate-100">
                        <p class="text-xs font-semibold text-slate-700 mb-1">Trustees:</p>
                        @foreach($entity['trustees'] as $tr)
                        <div class="text-xs text-slate-600 mb-1">{{ $tr['name'] ?? '' }} — ID: {{ $tr['id_number'] ?? '' }} — {{ $tr['address'] ?? '' }}</div>
                        @endforeach
                    </div>
                @endif
                @if(!empty($entity['beneficiaries']))
                    <div class="mt-2 pt-2 border-t border-slate-100">
                        <p class="text-xs font-semibold text-slate-700 mb-1">Beneficiaries:</p>
                        @foreach($entity['beneficiaries'] as $bn)
                        <div class="text-xs text-slate-600 mb-1">{{ $bn['name'] ?? '' }} — ID: {{ $bn['id_number'] ?? '' }}</div>
                        @endforeach
                    </div>
                @elseif(!empty($entity['beneficiary_determination']))
                    <div class="mt-2 pt-2 border-t border-slate-100 text-xs text-slate-600">Beneficiary determination: {{ $entity['beneficiary_determination'] }}</div>
                @endif
                @endif

                @if($submission->entity_type === 'partnership')
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="text-slate-400 text-xs">Partnership Name</dt><dd class="text-slate-900 font-medium">{{ $entity['partnership_name'] ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Professional</dt><dd class="text-slate-900">{{ ucfirst($entity['is_professional_partnership'] ?? '—') }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">SA Presence</dt><dd class="text-slate-900">{{ $entity['partnership_sa_presence'] ?? '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">Authority to Act</dt><dd class="text-slate-900">{{ $entity['partnership_authority_source'] ?? '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">Business Description</dt><dd class="text-slate-900">{{ $entity['partnership_business_description'] ?? '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">Ownership Structure</dt><dd class="text-slate-900">{{ $entity['partnership_ownership_structure'] ?? '—' }}</dd></div>
                </dl>
                @if(!empty($entity['partners']))
                    <div class="mt-3 pt-3 border-t border-slate-100">
                        <p class="text-xs font-semibold text-slate-700 mb-1">Partners:</p>
                        @foreach($entity['partners'] as $pt)
                        <div class="text-xs text-slate-600 mb-1">{{ $pt['name'] ?? '' }} — ID: {{ $pt['id_number'] ?? '' }} — {{ $pt['address'] ?? '' }}</div>
                        @endforeach
                    </div>
                @endif
                @endif
            </div>
            @endif

            {{-- Principal & Representative --}}
            @if($submission->entity_type === 'natural' && ($principalData['acting_on_behalf'] ?? '') === 'yes')
            <div class="bg-white border border-slate-200 p-5">
                <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-teal-500">Principal (Acting on Behalf)</h3>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="text-slate-400 text-xs">Full Name</dt><dd class="text-slate-900 font-medium">{{ $principalData['full_name'] ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">ID / Passport</dt><dd class="text-slate-900">{{ $principalData['id_number'] ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">SA Citizen</dt><dd class="text-slate-900">{{ ucfirst($principalData['sa_citizen'] ?? '—') }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Phone</dt><dd class="text-slate-900">{{ $principalData['phone'] ?? '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">Address</dt><dd class="text-slate-900">{{ $principalData['residential_address'] ?? '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">Authority Source</dt><dd class="text-slate-900">{{ $principalData['authority_source'] ?? '—' }}</dd></div>
                </dl>
            </div>
            @endif

            @if($submission->entity_type === 'natural' && ($repData['has_representative'] ?? '') === 'yes')
            <div class="bg-white border border-slate-200 p-5">
                <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-teal-500">Representative</h3>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="text-slate-400 text-xs">Full Name</dt><dd class="text-slate-900 font-medium">{{ $repData['full_name'] ?? '—' }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">ID / Passport</dt><dd class="text-slate-900">{{ $repData['id_number'] ?? '—' }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">Authority Source</dt><dd class="text-slate-900">{{ $repData['authority_source'] ?? '—' }}</dd></div>
                </dl>
            </div>
            @endif

            {{-- Service & Payment --}}
            <div class="bg-white border border-slate-200 p-5">
                <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-teal-500">Service & Payment</h3>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="text-slate-400 text-xs">Purpose</dt><dd class="text-slate-900">{{ $service['transaction_purpose'] ?? '—' }}{{ !empty($service['purpose_other']) ? ': ' . $service['purpose_other'] : '' }}</dd></div>
                    <div><dt class="text-slate-400 text-xs">Cash Over R50,000</dt><dd class="text-slate-900 font-semibold {{ ($service['cash_over_50k'] ?? '') === 'yes' ? 'text-red-600' : '' }}">{{ ucfirst($service['cash_over_50k'] ?? '—') }}</dd></div>
                    <div class="col-span-2"><dt class="text-slate-400 text-xs">Payment Method</dt><dd class="text-slate-900">{{ $service['payment_method'] ?? '—' }}</dd></div>
                </dl>
            </div>

            {{-- PEP --}}
            <div class="bg-white border border-slate-200 p-5">
                <h3 class="text-sm font-bold text-slate-900 mb-3 pb-2 border-b border-teal-500">Politically Exposed Person</h3>
                @php
                    $foreignPep = $pepData['foreign_pep'] ?? [];
                    $domesticPep = $pepData['domestic_pep'] ?? [];
                    $hasPep = !empty($foreignPep) || !empty($domesticPep) || ($pepData['is_family_associate'] ?? '') === 'yes';
                @endphp

                @if(!empty($foreignPep))
                <div class="mb-2">
                    <p class="text-xs font-semibold text-red-600">Foreign PEP:</p>
                    @foreach($foreignPep as $pos)
                    <span class="inline-block px-2 py-0.5 bg-red-50 text-red-700 text-xs mr-1 mb-1">{{ str_replace('_', ' ', ucfirst($pos)) }}</span>
                    @endforeach
                </div>
                @endif

                @if(!empty($domesticPep))
                <div class="mb-2">
                    <p class="text-xs font-semibold text-red-600">Domestic PEP:</p>
                    @foreach($domesticPep as $pos)
                    <span class="inline-block px-2 py-0.5 bg-red-50 text-red-700 text-xs mr-1 mb-1">{{ str_replace('_', ' ', ucfirst($pos)) }}</span>
                    @endforeach
                </div>
                @endif

                <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    <div><dt class="text-slate-400 text-xs">Family/Associate</dt><dd class="{{ ($pepData['is_family_associate'] ?? '') === 'yes' ? 'text-red-600 font-semibold' : 'text-slate-900' }}">{{ ucfirst($pepData['is_family_associate'] ?? '—') }}</dd></div>
                </dl>

                @if(!empty($pepData['family_associate_details']))
                <div class="mt-2 p-2 bg-red-50 border border-red-200 text-red-800 text-xs">{{ $pepData['family_associate_details'] }}</div>
                @endif
                @if(!empty($pepData['source_of_wealth']))
                <div class="mt-2 text-sm"><dt class="text-slate-400 text-xs">Source of Wealth</dt><dd class="text-slate-900">{{ $pepData['source_of_wealth'] }}</dd></div>
                @endif
                @if(!$hasPep)
                <p class="text-emerald-600 text-sm font-medium">No PEP indicators</p>
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
                <p class="text-xs text-slate-400 mt-1">
                    Signed at: {{ $declData['signed_at_location'] ?? '' }} — {{ $submission->signed_at?->format('d M Y H:i') }}
                </p>
            </div>
            @endif
        </div>

        {{-- RIGHT PANEL: Verification --}}
        <div class="space-y-4">
            @if(in_array($submission->status, ['submitted', 'under_review', 'corrections_requested']))
                {{-- Section 10 — Staff Verification Checklist --}}
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
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Document DELEGATING authority provided?</label>
                            <div class="flex gap-3">
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.delegating_docs" value="yes"> <span class="text-xs">Yes</span></label>
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.delegating_docs" value="no"> <span class="text-xs">No</span></label>
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.delegating_docs" value="na"> <span class="text-xs">N/A</span></label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Is the client a VIP in terms of FICA compliance?</label>
                            <div class="flex gap-3">
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.is_vip" value="yes"> <span class="text-xs">Yes</span></label>
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.is_vip" value="no"> <span class="text-xs">No</span></label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Is there anything suspicious or unusual to note?</label>
                            <div class="flex gap-3">
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.suspicious" value="yes"> <span class="text-xs">Yes</span></label>
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.suspicious" value="no"> <span class="text-xs">No</span></label>
                            </div>
                            <div x-show="checklist.suspicious === 'yes'" x-cloak class="mt-1">
                                <textarea x-model="checklist.suspicious_details" rows="2" class="w-full px-2 py-1 border border-slate-300 text-xs focus:outline-none focus:border-teal-500" placeholder="Details..."></textarea>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Is the proposed transaction consistent with our knowledge of the client?</label>
                            <div class="flex gap-3">
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.consistent" value="yes"> <span class="text-xs">Yes</span></label>
                                <label class="flex items-center gap-1"><input type="radio" x-model="checklist.consistent" value="no"> <span class="text-xs">No</span></label>
                            </div>
                        </div>
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
                                <label class="flex items-center gap-2"><input type="checkbox" name="verification_method[]" value="whatsapp_video"> WhatsApp video call</label>
                                <label class="flex items-center gap-2"><input type="checkbox" name="verification_method[]" value="physically_met"> Physically met with client</label>
                                <label class="flex items-center gap-2"><input type="checkbox" name="verification_method[]" value="video_call_id"> Video call with identity document and newspaper</label>
                                <label class="flex items-center gap-2"><input type="checkbox" name="verification_method[]" value="certified_copies"> Certified copies received</label>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Outstanding Requirements</label>
                            <textarea name="outstanding_requirements" rows="2" class="w-full px-3 py-2 border border-slate-300 text-sm focus:outline-none focus:border-teal-500" placeholder="Any outstanding items..."></textarea>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Employee Name</label>
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
                identity_docs: '',
                address_docs: '',
                authority_docs: '',
                delegating_docs: '',
                is_vip: '',
                suspicious: '',
                suspicious_details: '',
                consistent: '',
            }
        };
    }
</script>
@endsection
