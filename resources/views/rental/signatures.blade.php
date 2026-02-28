@extends('layouts.nexus')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6"
     x-data="{
        showRejectModal: false,
        rejectDocId: null,
        rejectDocName: '',
        showUploadOnBehalfModal: false,
        uploadOnBehalfDocId: null,
        uploadOnBehalfRequestId: null,
        uploadOnBehalfPartyName: '',
        showRejected: false,
        savedIndicators: {},
        async saveMetadata(docId, field, value) {
            const body = {};
            body[field] = value || null;
            try {
                const resp = await fetch(`/rental/signatures/${docId}/assign-metadata`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                    body: JSON.stringify(body)
                });
                if (resp.ok) {
                    this.savedIndicators[docId + '-' + field] = true;
                    setTimeout(() => { this.savedIndicators[docId + '-' + field] = false; }, 2000);
                }
            } catch (e) { console.error('Save failed', e); }
        },
        async saveExpiry(docId, date) {
            try {
                const resp = await fetch(`/rental/signatures/${docId}/set-expiry`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                    body: JSON.stringify({ lease_expiry_date: date })
                });
                if (resp.ok) {
                    this.savedIndicators[docId + '-expiry'] = true;
                    setTimeout(() => { this.savedIndicators[docId + '-expiry'] = false; }, 2000);
                }
            } catch (e) { console.error('Save failed', e); }
        }
     }">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Electronic Signatures</h2>
            <div class="text-sm text-white/60">
                <a href="{{ route('rental.dashboard') }}" class="text-white/60 hover:text-white">&larr; Rentals</a>
                &middot; Manage rental document signing workflows.
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    {{-- Status summary cards --}}
    <div class="grid grid-cols-2 md:grid-cols-6 gap-4">
        @if($counts['pending_approval'] > 0)
        <a href="#section-pending-approval" onclick="event.preventDefault(); scrollToSection('section-pending-approval')"
           class="ds-status-card p-4 text-center border-2 border-amber-400 bg-amber-50 hover:shadow-md hover:border-amber-500 transition cursor-pointer block">
            <div class="text-2xl font-bold text-amber-700" id="pending-approval-count">{{ $counts['pending_approval'] }}</div>
            <div class="text-xs text-amber-600 mt-1 font-semibold">Needs Approval</div>
        </a>
        @endif
        <a href="#section-draft" onclick="event.preventDefault(); scrollToSection('section-draft')"
           class="ds-status-card p-4 text-center hover:shadow-md hover:border-slate-300 transition cursor-pointer block">
            <div class="text-2xl font-bold {{ $counts['draft'] > 0 ? 'text-slate-700' : 'text-slate-300' }}">{{ $counts['draft'] }}</div>
            <div class="text-xs {{ $counts['draft'] > 0 ? 'text-slate-500' : 'text-slate-300' }} mt-1">Draft</div>
        </a>
        <a href="#section-ready" onclick="event.preventDefault(); scrollToSection('section-ready')"
           class="ds-status-card p-4 text-center hover:shadow-md hover:border-blue-300 transition cursor-pointer block">
            <div class="text-2xl font-bold {{ $counts['ready_to_sign'] > 0 ? 'text-blue-600' : 'text-slate-300' }}">{{ $counts['ready_to_sign'] }}</div>
            <div class="text-xs {{ $counts['ready_to_sign'] > 0 ? 'text-slate-500' : 'text-slate-300' }} mt-1">Ready to Sign</div>
        </a>
        <a href="#section-awaiting" onclick="event.preventDefault(); scrollToSection('section-awaiting')"
           class="ds-status-card p-4 text-center hover:shadow-md hover:border-amber-300 transition cursor-pointer block">
            <div class="text-2xl font-bold {{ $counts['awaiting_signatures'] > 0 ? 'text-amber-600' : 'text-slate-300' }}">{{ $counts['awaiting_signatures'] }}</div>
            <div class="text-xs {{ $counts['awaiting_signatures'] > 0 ? 'text-slate-500' : 'text-slate-300' }} mt-1">Awaiting Signatures</div>
        </a>
        <a href="#section-completed" onclick="event.preventDefault(); scrollToSection('section-completed')"
           class="ds-status-card p-4 text-center hover:shadow-md hover:border-emerald-300 transition cursor-pointer block">
            <div class="text-2xl font-bold {{ $counts['completed'] > 0 ? 'text-emerald-600' : 'text-slate-300' }}">{{ $counts['completed'] }}</div>
            <div class="text-xs {{ $counts['completed'] > 0 ? 'text-slate-500' : 'text-slate-300' }} mt-1">Completed</div>
        </a>
        <a href="{{ route('rental.active-leases') }}"
           class="ds-status-card p-4 text-center hover:shadow-md hover:border-green-300 transition cursor-pointer block">
            <div class="text-2xl font-bold {{ $activeLeaseCount > 0 ? 'text-green-600' : 'text-slate-300' }}">{{ $activeLeaseCount }}</div>
            <div class="text-xs {{ $activeLeaseCount > 0 ? 'text-slate-500' : 'text-slate-300' }} mt-1">Active Leases</div>
        </a>
    </div>

    {{-- Upcoming Renewals --}}
    @if($upcomingRenewals->isNotEmpty())
    <div class="space-y-2">
        <h3 class="text-sm font-semibold text-orange-700 uppercase tracking-wider">Upcoming Renewals</h3>
        <div class="space-y-3">
            @foreach($upcomingRenewals as $lease)
                @php
                    $daysLeft = $lease->daysUntilExpiry();
                    $urgencyColor = match(true) {
                        $daysLeft <= 0  => 'border-red-300 bg-red-50',
                        $daysLeft <= 30 => 'border-red-200 bg-red-50',
                        $daysLeft <= 60 => 'border-amber-200 bg-amber-50',
                        default         => 'border-emerald-200 bg-emerald-50',
                    };
                    $urgencyBadge = match(true) {
                        $daysLeft <= 0  => 'bg-red-100 text-red-800',
                        $daysLeft <= 30 => 'bg-red-100 text-red-700',
                        $daysLeft <= 60 => 'bg-amber-100 text-amber-700',
                        default         => 'bg-emerald-100 text-emerald-700',
                    };
                    $rental = number_format((float) $lease->rental_amount, 0, '.', ' ');
                @endphp
                <div class="rounded-2xl border {{ $urgencyColor }} p-4">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <div class="font-semibold text-slate-800">{{ $lease->property_address }}</div>
                            <div class="text-xs text-slate-600 mt-1">
                                Tenant: {{ $lease->tenant_name }} | Landlord: {{ $lease->landlord_name }}
                            </div>
                            <div class="text-xs text-slate-600 mt-0.5">
                                Rental: R {{ $rental }}/mo | Expires: {{ $lease->lease_end_date?->format('d M Y') }}
                            </div>
                            <div class="mt-1.5">
                                <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $urgencyBadge }}">
                                    @if($daysLeft <= 0)
                                        EXPIRED
                                    @elseif($daysLeft <= 30)
                                        {{ $daysLeft }} days remaining — URGENT
                                    @else
                                        {{ $daysLeft }} days remaining
                                    @endif
                                </span>
                            </div>
                        </div>
                        <div class="flex flex-col gap-1.5 ml-4">
                            <form method="POST" action="{{ route('docuperfect.leases.renew', $lease) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-xs px-3 py-1 rounded-lg bg-blue-600 text-white hover:bg-blue-700" onclick="return confirm('Renew lease for {{ $lease->property_address }}?')">
                                    Renew Lease
                                </button>
                            </form>
                            <button type="button" class="text-xs px-3 py-1 rounded-lg border border-red-300 text-red-600 hover:bg-red-50"
                                    onclick="document.getElementById('terminate-modal-{{ $lease->id }}').classList.remove('hidden')">
                                Terminate
                            </button>
                            <a href="{{ route('docuperfect.leases.history', $lease) }}" class="text-xs px-3 py-1 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50 text-center">
                                History
                            </a>
                        </div>
                    </div>
                </div>

                {{-- Terminate modal --}}
                <div id="terminate-modal-{{ $lease->id }}" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
                    <div class="bg-white rounded-2xl p-6 max-w-md w-full">
                        <h4 class="font-semibold text-slate-800 mb-3">Terminate Lease</h4>
                        <p class="text-sm text-slate-600 mb-4">{{ $lease->property_address }}</p>
                        <form method="POST" action="{{ route('docuperfect.leases.terminate', $lease) }}">
                            @csrf
                            <div class="mb-3">
                                <label class="block text-xs font-medium text-slate-700 mb-1">Termination Date</label>
                                <input type="date" name="termination_date" value="{{ now()->format('Y-m-d') }}" required
                                       class="w-full rounded-lg border-slate-300 text-sm">
                            </div>
                            <div class="mb-4">
                                <label class="block text-xs font-medium text-slate-700 mb-1">Reason (optional)</label>
                                <textarea name="reason" rows="2" maxlength="500" class="w-full rounded-lg border-slate-300 text-sm" placeholder="Reason for termination..."></textarea>
                            </div>
                            <div class="flex gap-2 justify-end">
                                <button type="button" class="text-xs px-3 py-1.5 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50"
                                        onclick="this.closest('[id^=terminate-modal]').classList.add('hidden')">
                                    Cancel
                                </button>
                                <button type="submit" class="text-xs px-3 py-1.5 rounded-lg bg-red-600 text-white hover:bg-red-700">
                                    Confirm Termination
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Expired Leases --}}
    @if($expiredLeases->isNotEmpty())
    <div class="space-y-2">
        <h3 class="text-sm font-semibold text-red-700 uppercase tracking-wider">Recently Expired Leases</h3>
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <table class="w-full text-sm ds-table">
                <thead>
                    <tr>
                        <th class="text-left px-4 py-3">Property</th>
                        <th class="text-left px-4 py-3">Tenant</th>
                        <th class="text-left px-4 py-3">Expired</th>
                        <th class="text-right px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($expiredLeases as $lease)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $lease->property_address }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $lease->tenant_name }}</td>
                        <td class="px-4 py-3 text-red-600 text-xs">{{ $lease->lease_end_date?->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-right">
                            <form method="POST" action="{{ route('docuperfect.leases.renew', $lease) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-blue-600 hover:underline text-xs" onclick="return confirm('Renew lease for {{ $lease->property_address }}?')">Renew</button>
                            </form>
                            <a href="{{ route('docuperfect.leases.history', $lease) }}" class="text-slate-600 hover:underline text-xs ml-2">History</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Needs Your Approval --}}
    @if($groups['pending_approval']->isNotEmpty())
    <div id="section-pending-approval" class="space-y-2 scroll-mt-4">
        <h3 class="text-sm font-semibold text-amber-700 uppercase tracking-wider flex items-center gap-2">
            <span class="inline-flex items-center justify-center w-5 h-5 bg-amber-500 text-white text-[10px] font-bold rounded-full">{{ $groups['pending_approval']->count() }}</span>
            Needs Your Approval
        </h3>
        <div class="space-y-3">
            @foreach($groups['pending_approval'] as $doc)
                @php
                    $sigTemplate = $signatureTemplates->get($doc->id);
                    $requests = $sigTemplate ? $sigTemplate->requests->keyBy('party_role') : collect();
                    $completedReq = $sigTemplate ? $sigTemplate->requests->where('status', 'completed')->where('party_role', '!=', 'agent')->sortByDesc('completed_at')->first() : null;
                    $wetInkReq = $sigTemplate ? $sigTemplate->requests->first(fn($r) => $r->wet_ink_status === 'uploaded_pending_review') : null;
                    $isWetInkApproval = (bool) $wetInkReq;
                @endphp
                <div class="rounded-2xl border-2 border-amber-300 bg-amber-50 p-4">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <div class="font-semibold text-slate-800">
                                {{ $doc->name }}
                                @if($doc->document_type)
                                    <span class="inline-block ml-2 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-200 text-slate-600">{{ ucwords(str_replace('_', ' ', $doc->document_type)) }}</span>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-2 mt-2">
                                @foreach(['agent', 'tenant', 'landlord'] as $role)
                                    @php $req = $requests->get($role); @endphp
                                    @if($req)
                                        @if($req->status === 'completed')
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-800">
                                                &#10003; {{ ucfirst($role) }} signed
                                                @if($req->signing_method === 'wet_ink')
                                                    <span class="text-slate-400">(wet ink)</span>
                                                @endif
                                            </span>
                                        @elseif($req->wet_ink_status === 'uploaded_pending_review')
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-200 text-amber-800">
                                                &#9888; {{ ucfirst($role) }} wet ink — pending review
                                            </span>
                                        @elseif($req->status === 'waiting')
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-500">
                                                &#128274; {{ ucfirst($role) }} waiting
                                            </span>
                                        @elseif(in_array($req->status, ['pending', 'viewed', 'partially_signed']))
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-blue-100 text-blue-700">
                                                &#9993; {{ ucfirst($role) }} — {{ $req->status === 'viewed' ? 'viewed' : ($req->status === 'partially_signed' ? 'signing' : 'sent') }}
                                            </span>
                                        @endif
                                    @endif
                                @endforeach
                            </div>
                            @if($completedReq)
                                <div class="text-xs text-amber-700 mt-2">
                                    {{ ucfirst($completedReq->party_role) }} <strong>{{ $completedReq->signer_name }}</strong>
                                    signed {{ $completedReq->completed_at?->diffForHumans() }}
                                </div>
                            @endif
                            {{-- Inline metadata dropdowns --}}
                            <div class="flex flex-wrap items-center gap-3 mt-3 pt-3 border-t border-amber-200">
                                <div class="flex items-center gap-1.5">
                                    <label class="text-[10px] text-slate-500 font-medium whitespace-nowrap">Type:</label>
                                    <select class="text-xs border-slate-300 rounded-lg py-1 px-2 bg-white"
                                            @change="saveMetadata({{ $doc->id }}, 'document_type_id', $event.target.value)">
                                        <option value="">-- None --</option>
                                        @foreach($documentTypes as $dt)
                                            <option value="{{ $dt->id }}" {{ ($doc->document_type ?? '') === $dt->slug ? 'selected' : '' }}>{{ $dt->name }}</option>
                                        @endforeach
                                    </select>
                                    <span class="text-emerald-600 text-[10px] font-medium transition-opacity" :class="savedIndicators[{{ $doc->id }} + '-document_type_id'] ? 'opacity-100' : 'opacity-0'">Saved</span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <label class="text-[10px] text-slate-500 font-medium whitespace-nowrap">Property:</label>
                                    <select class="text-xs border-slate-300 rounded-lg py-1 px-2 bg-white"
                                            @change="saveMetadata({{ $doc->id }}, 'property_id', $event.target.value)">
                                        <option value="">-- None --</option>
                                        @foreach($properties as $prop)
                                            <option value="{{ $prop->id }}" {{ ($doc->property_id ?? '') == $prop->id ? 'selected' : '' }}>{{ $prop->full_address }}</option>
                                        @endforeach
                                    </select>
                                    <span class="text-emerald-600 text-[10px] font-medium transition-opacity" :class="savedIndicators[{{ $doc->id }} + '-property_id'] ? 'opacity-100' : 'opacity-0'">Saved</span>
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-col gap-1.5 ml-4">
                            @if($isWetInkApproval)
                                <a href="{{ route('docuperfect.signatures.wetInkReview', ['document' => $doc->id, 'signingRequest' => $wetInkReq->id]) }}"
                                   class="inline-flex items-center px-4 py-2 bg-amber-600 text-white text-xs font-medium rounded-lg hover:bg-amber-700 whitespace-nowrap">
                                    Review Wet Ink
                                </a>
                            @else
                                <a href="{{ route('docuperfect.signatures.review', $doc) }}"
                                   class="inline-flex items-center px-4 py-2 bg-amber-600 text-white text-xs font-medium rounded-lg hover:bg-amber-700 whitespace-nowrap">
                                    Review &amp; Approve
                                </a>
                            @endif
                            <button type="button"
                                    @click="rejectDocId = {{ $doc->id }}; rejectDocName = {{ Js::from($doc->name) }}; showRejectModal = true"
                                    class="text-xs text-red-500 hover:text-red-700 text-center">
                                Reject / Redo
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Awaiting Signatures --}}
    @if($groups['awaiting_signatures']->isNotEmpty())
    <div id="section-awaiting" class="space-y-2 scroll-mt-4">
        <h3 class="text-sm font-semibold text-amber-700 uppercase tracking-wider">Awaiting Signatures</h3>
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <table class="w-full text-sm ds-table">
                <thead>
                    <tr>
                        <th class="text-left px-4 py-3">Document</th>
                        <th class="text-left px-4 py-3">Type</th>
                        <th class="text-left px-4 py-3">Property</th>
                        <th class="text-left px-4 py-3">Signing Progress</th>
                        @if($user->isAdmin() || $user->isBranchManager())
                        <th class="text-left px-4 py-3">Agent</th>
                        @endif
                        <th class="text-right px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($groups['awaiting_signatures'] as $doc)
                    @php
                        $sigTemplate = $signatureTemplates->get($doc->id);
                        $requests = $sigTemplate ? $sigTemplate->requests->keyBy('party_role') : collect();
                    @endphp
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $doc->name }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1">
                                <select class="text-xs border-slate-300 rounded py-0.5 px-1 bg-white w-28"
                                        @change="saveMetadata({{ $doc->id }}, 'document_type_id', $event.target.value)">
                                    <option value="">--</option>
                                    @foreach($documentTypes as $dt)
                                        <option value="{{ $dt->id }}" {{ ($doc->document_type ?? '') === $dt->slug ? 'selected' : '' }}>{{ $dt->name }}</option>
                                    @endforeach
                                </select>
                                <span class="text-emerald-600 text-[10px]" :class="savedIndicators[{{ $doc->id }} + '-document_type_id'] ? 'opacity-100' : 'opacity-0'">&#10003;</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1">
                                <select class="text-xs border-slate-300 rounded py-0.5 px-1 bg-white w-36"
                                        @change="saveMetadata({{ $doc->id }}, 'property_id', $event.target.value)">
                                    <option value="">--</option>
                                    @foreach($properties as $prop)
                                        <option value="{{ $prop->id }}" {{ ($doc->property_id ?? '') == $prop->id ? 'selected' : '' }}>{{ $prop->full_address }}</option>
                                    @endforeach
                                </select>
                                <span class="text-emerald-600 text-[10px]" :class="savedIndicators[{{ $doc->id }} + '-property_id'] ? 'opacity-100' : 'opacity-0'">&#10003;</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            @if($sigTemplate)
                            <div class="flex flex-col gap-1.5">
                                @foreach(['agent', 'tenant', 'landlord'] as $role)
                                    @php $req = $requests->get($role); @endphp
                                    @if($req)
                                    <div class="flex items-start gap-1.5 text-xs">
                                        @if($req->status === 'completed')
                                            <span class="text-emerald-500 mt-0.5" title="Completed">&#10003;</span>
                                            <div>
                                                <span class="text-slate-600 capitalize">{{ $role }}</span>
                                                <span class="text-emerald-600 font-medium">
                                                    {{ $req->signer_name }}
                                                    @if($req->signing_method === 'wet_ink')
                                                        <span class="text-xs text-slate-400">(wet ink)</span>
                                                    @endif
                                                </span>
                                            </div>
                                        @elseif($req->wet_ink_status === 'uploaded_pending_review')
                                            <span class="text-amber-500 mt-0.5" title="Wet ink uploaded">&#9888;</span>
                                            <div>
                                                <span class="text-slate-600 capitalize">{{ $role }}</span>
                                                <span class="text-amber-600 font-medium">wet ink — pending review</span>
                                            </div>
                                        @elseif(in_array($req->status, ['pending', 'viewed', 'partially_signed']))
                                            @php
                                                $days = $req->daysSinceSent();
                                                $dayColor = $days <= 3 ? 'text-emerald-600' : ($days <= 7 ? 'text-amber-600' : 'text-red-600');
                                                $dayBg = $days <= 3 ? 'bg-emerald-50' : ($days <= 7 ? 'bg-amber-50' : 'bg-red-50');
                                            @endphp
                                            <span class="text-blue-400 mt-0.5" title="Awaiting">&#9993;</span>
                                            <div>
                                                <div>
                                                    <span class="text-slate-600 capitalize">{{ $role }}</span>
                                                    <span class="text-blue-600">
                                                        {{ $req->signer_name }}
                                                        — {{ $req->status === 'viewed' ? 'viewed' : ($req->status === 'partially_signed' ? 'signing' : 'sent') }}
                                                    </span>
                                                </div>
                                                <div class="flex items-center gap-2 mt-0.5">
                                                    <span class="inline-block px-1.5 py-0.5 rounded {{ $dayBg }} {{ $dayColor }} text-[10px] font-medium">
                                                        {{ $days }}d ago
                                                    </span>
                                                    @if($req->viewed_at)
                                                        <span class="text-slate-400 text-[10px]">viewed {{ $req->viewed_at->format('d M H:i') }}</span>
                                                    @endif
                                                    @if($req->reminder_count > 0)
                                                        <span class="text-slate-400 text-[10px]">{{ $req->reminder_count }} reminder{{ $req->reminder_count > 1 ? 's' : '' }} sent</span>
                                                    @endif
                                                </div>
                                                @if($days >= 7)
                                                    <div class="text-red-500 text-[10px] font-medium mt-0.5">&#9888; {{ $days }} days without signing — follow up recommended</div>
                                                @endif
                                            </div>
                                        @elseif($req->status === 'waiting')
                                            <span class="text-slate-300 mt-0.5" title="Waiting for previous party">&#128274;</span>
                                            <div>
                                                <span class="text-slate-400 capitalize">{{ $role }}</span>
                                                <span class="text-slate-400">waiting</span>
                                            </div>
                                        @endif
                                    </div>
                                    @endif
                                @endforeach
                            </div>
                            @endif
                        </td>
                        @if($user->isAdmin() || $user->isBranchManager())
                        <td class="px-4 py-3 text-slate-500">{{ $doc->owner->name ?? '-' }}</td>
                        @endif
                        <td class="px-4 py-3 text-right">
                            <div class="flex flex-col items-end gap-1">
                                <a href="{{ route('docuperfect.signatures.setup', $doc) }}" class="text-blue-600 hover:underline text-xs">View</a>
                                @if($sigTemplate)
                                    @php
                                        $activeReq = $sigTemplate->requests->first(fn($r) => in_array($r->status, ['pending', 'viewed', 'partially_signed']));
                                    @endphp
                                    @if($activeReq)
                                        <form method="POST" action="{{ route('docuperfect.signatures.sendReminder', ['document' => $doc->id, 'signatureRequest' => $activeReq->id]) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-amber-600 hover:underline text-xs" onclick="return confirm('Send reminder to {{ $activeReq->signer_name }}?')">
                                                Send Reminder
                                            </button>
                                        </form>
                                        <button type="button"
                                                @click="uploadOnBehalfDocId = {{ $doc->id }}; uploadOnBehalfRequestId = {{ $activeReq->id }}; uploadOnBehalfPartyName = {{ Js::from($activeReq->signer_name) }}; showUploadOnBehalfModal = true"
                                                class="text-indigo-600 hover:underline text-xs">
                                            Upload on Behalf
                                        </button>
                                    @endif
                                @endif
                                <button type="button"
                                        @click="rejectDocId = {{ $doc->id }}; rejectDocName = {{ Js::from($doc->name) }}; showRejectModal = true"
                                        class="text-red-500 hover:underline text-xs">
                                    Reject / Redo
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Ready to Sign --}}
    @if($groups['ready_to_sign']->isNotEmpty())
    <div id="section-ready" class="space-y-2 scroll-mt-4">
        <h3 class="text-sm font-semibold text-blue-700 uppercase tracking-wider">Ready to Sign</h3>
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <table class="w-full text-sm ds-table">
                <thead>
                    <tr>
                        <th class="text-left px-4 py-3">Document</th>
                        <th class="text-left px-4 py-3">Type</th>
                        <th class="text-left px-4 py-3">Status</th>
                        @if($user->isAdmin() || $user->isBranchManager())
                        <th class="text-left px-4 py-3">Agent</th>
                        @endif
                        <th class="text-right px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($groups['ready_to_sign'] as $doc)
                    @php
                        $sigTemplate = $signatureTemplates->get($doc->id);
                        $hasSigTemplate = $sigTemplate !== null;
                    @endphp
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $doc->name }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $doc->template->documentType->name ?? '-' }}</td>
                        <td class="px-4 py-3">
                            @if($hasSigTemplate)
                                <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold bg-blue-100 text-blue-800">Signature setup started</span>
                            @else
                                <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-800">All fields complete</span>
                            @endif
                        </td>
                        @if($user->isAdmin() || $user->isBranchManager())
                        <td class="px-4 py-3 text-slate-500">{{ $doc->owner->name ?? '-' }}</td>
                        @endif
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('docuperfect.signatures.setup', $doc) }}" class="inline-flex items-center px-3 py-1 bg-blue-600 text-white text-xs rounded-lg hover:bg-blue-700">Set Up Signatures</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Draft --}}
    @if($groups['draft']->isNotEmpty())
    <div id="section-draft" class="space-y-2 scroll-mt-4">
        <h3 class="text-sm font-semibold text-slate-500 uppercase tracking-wider">Draft — Fields Incomplete</h3>
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <table class="w-full text-sm ds-table">
                <thead>
                    <tr>
                        <th class="text-left px-4 py-3">Document</th>
                        <th class="text-left px-4 py-3">Type</th>
                        <th class="text-left px-4 py-3">Field Progress</th>
                        @if($user->isAdmin() || $user->isBranchManager())
                        <th class="text-left px-4 py-3">Agent</th>
                        @endif
                        <th class="text-right px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($groups['draft'] as $doc)
                    @php
                        $fs = $fieldStatus[$doc->id] ?? null;
                    @endphp
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $doc->name }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $doc->template->documentType->name ?? '-' }}</td>
                        <td class="px-4 py-3">
                            @if($fs && $fs['total'] > 0)
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 max-w-[120px] bg-slate-200 rounded-full h-1.5">
                                        <div class="bg-amber-500 h-1.5 rounded-full" style="width: {{ round(($fs['filled'] / $fs['total']) * 100) }}%"></div>
                                    </div>
                                    <span class="text-xs text-amber-600 font-medium">{{ $fs['filled'] }}/{{ $fs['total'] }}</span>
                                </div>
                                @if(count($fs['missing']) > 0)
                                    <div class="text-[10px] text-slate-400 mt-0.5">
                                        Missing: {{ implode(', ', array_slice($fs['missing'], 0, 3)) }}
                                        @if(count($fs['missing']) > 3)
                                            +{{ count($fs['missing']) - 3 }} more
                                        @endif
                                    </div>
                                @endif
                            @else
                                <span class="text-xs text-slate-400">No required fields</span>
                            @endif
                        </td>
                        @if($user->isAdmin() || $user->isBranchManager())
                        <td class="px-4 py-3 text-slate-500">{{ $doc->owner->name ?? '-' }}</td>
                        @endif
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('docuperfect.documents.edit', $doc) }}" class="text-blue-600 hover:underline text-xs">Edit Document</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Completed --}}
    @if($groups['completed']->isNotEmpty())
    <div id="section-completed" class="space-y-2 scroll-mt-4">
        <h3 class="text-sm font-semibold text-emerald-700 uppercase tracking-wider">Completed</h3>
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <table class="w-full text-sm ds-table">
                <thead>
                    <tr>
                        <th class="text-left px-4 py-3">Document</th>
                        <th class="text-left px-4 py-3">Type</th>
                        @if($user->isAdmin() || $user->isBranchManager())
                        <th class="text-left px-4 py-3">Agent</th>
                        @endif
                        <th class="text-right px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($groups['completed'] as $doc)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $doc->name }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $doc->template->documentType->name ?? '-' }}</td>
                        @if($user->isAdmin() || $user->isBranchManager())
                        <td class="px-4 py-3 text-slate-500">{{ $doc->owner->name ?? '-' }}</td>
                        @endif
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('docuperfect.signatures.audit', $doc) }}" class="text-blue-600 hover:underline text-xs">Audit</a>
                            <a href="{{ route('docuperfect.signatures.download', $doc) }}" class="text-emerald-600 hover:underline text-xs ml-2">Download</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Properties — documents grouped by property --}}
    @if($documentsByProperty->isNotEmpty())
    <div id="section-properties" class="space-y-2 scroll-mt-4">
        <h3 class="text-sm font-semibold text-indigo-700 uppercase tracking-wider flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
            </svg>
            Properties
        </h3>
        <div class="space-y-3">
            @foreach($documentsByProperty->sortKeys() as $propertyId => $docs)
                @php
                    $propName = $propertyId ? ($docs->first()->property_address ?? ($properties->firstWhere('id', $propertyId)->full_address ?? 'Unknown Property')) : null;
                @endphp
                <div x-data="{ open: {{ $propertyId ? 'true' : 'false' }} }">
                    <button @click="open = !open" class="w-full flex items-center justify-between px-4 py-3 rounded-xl bg-white border border-slate-200 hover:border-indigo-300 transition text-left">
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-indigo-500 transition-transform" :class="open ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                            <span class="font-semibold text-sm text-slate-800">
                                {{ $propName ?? 'Unassigned' }}
                            </span>
                            <span class="text-xs text-slate-400">({{ $docs->count() }} {{ Str::plural('document', $docs->count()) }})</span>
                        </div>
                    </button>
                    <div x-show="open" x-collapse class="mt-1">
                        <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
                            <table class="w-full text-sm ds-table">
                                <thead>
                                    <tr>
                                        <th class="text-left px-4 py-2 text-xs">Type</th>
                                        <th class="text-left px-4 py-2 text-xs">Document</th>
                                        <th class="text-left px-4 py-2 text-xs">Status</th>
                                        <th class="text-right px-4 py-2 text-xs">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @foreach($docs as $doc)
                                    @php
                                        $st = $signatureTemplates->get($doc->id);
                                        $statusLabel = 'Draft';
                                        $statusColor = 'bg-slate-100 text-slate-600';
                                        if ($st) {
                                            $statusLabel = match($st->status) {
                                                'completed' => 'Signed',
                                                'pending_agent_approval' => 'Needs Approval',
                                                'rejected' => 'Rejected',
                                                'signing', 'awaiting_tenant', 'awaiting_landlord' => 'Awaiting',
                                                default => ucfirst(str_replace('_', ' ', $st->status)),
                                            };
                                            $statusColor = match($st->status) {
                                                'completed' => 'bg-emerald-100 text-emerald-700',
                                                'pending_agent_approval' => 'bg-amber-100 text-amber-700',
                                                'rejected' => 'bg-red-100 text-red-700',
                                                'signing', 'awaiting_tenant', 'awaiting_landlord' => 'bg-blue-100 text-blue-700',
                                                default => 'bg-slate-100 text-slate-600',
                                            };
                                        }
                                    @endphp
                                    <tr>
                                        <td class="px-4 py-2">
                                            @if($doc->document_type)
                                                <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-200 text-slate-600">{{ ucwords(str_replace('_', ' ', $doc->document_type)) }}</span>
                                            @else
                                                <span class="text-slate-300 text-xs">—</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2 font-medium text-slate-800">{{ $doc->name }}</td>
                                        <td class="px-4 py-2">
                                            <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $statusColor }}">{{ $statusLabel }}</span>
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            @if($st && $st->status === 'completed')
                                                <a href="{{ route('docuperfect.signatures.audit', $doc) }}" class="text-blue-600 hover:underline text-xs">Audit</a>
                                                <a href="{{ route('docuperfect.signatures.download', $doc) }}" class="text-emerald-600 hover:underline text-xs ml-2">Download</a>
                                            @else
                                                <a href="{{ route('docuperfect.signatures.setup', $doc) }}" class="text-blue-600 hover:underline text-xs">View</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Active Leases --}}
    <div id="section-active-leases" class="space-y-2 scroll-mt-4">
        <h3 class="text-sm font-semibold text-green-700 uppercase tracking-wider flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            Active Leases
        </h3>

        {{-- LeaseRecord-based active leases --}}
        @if($activeLeases->isNotEmpty())
        <div class="space-y-3">
            @foreach($activeLeases as $lease)
                @php
                    $rental = number_format((float) $lease->rental_amount, 0, '.', ' ');
                    $daysLeft = $lease->daysUntilExpiry();
                    $expiryColor = match(true) {
                        $daysLeft <= 30 => 'text-red-600',
                        $daysLeft <= 90 => 'text-amber-600',
                        default => 'text-green-600',
                    };
                    $expiryBg = match(true) {
                        $daysLeft <= 30 => 'bg-red-50 border-red-200',
                        $daysLeft <= 90 => 'bg-amber-50 border-amber-200',
                        default => 'bg-green-50/50 border-green-200',
                    };
                    $expiryDot = match(true) {
                        $daysLeft <= 30 => 'bg-red-500',
                        $daysLeft <= 90 => 'bg-amber-500',
                        default => 'bg-green-500',
                    };
                @endphp
                <div class="rounded-2xl border {{ $expiryBg }} p-4">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <span class="inline-block w-2.5 h-2.5 rounded-full {{ $expiryDot }}"></span>
                                <span class="font-semibold text-slate-800">{{ $lease->property_address ?: ($lease->document->name ?? 'Unnamed') }}</span>
                            </div>
                            <div class="text-xs text-slate-600 mt-1">
                                Tenant: {{ $lease->tenant_name ?? '—' }}
                                <span class="mx-1.5">|</span>
                                Landlord: {{ $lease->landlord_name ?? '—' }}
                            </div>
                            <div class="text-xs text-slate-500 mt-0.5">
                                @if($lease->rental_amount)
                                    Rental: R {{ $rental }}/mo
                                    <span class="mx-1.5">|</span>
                                @endif
                                @if($lease->lease_start_date)
                                    Start: {{ $lease->lease_start_date->format('d M Y') }}
                                    <span class="mx-1.5">|</span>
                                @endif
                                @if($lease->lease_end_date)
                                    <span class="{{ $expiryColor }} font-medium">Expires: {{ $lease->lease_end_date->format('d M Y') }} ({{ $daysLeft }}d)</span>
                                @endif
                            </div>
                            @if($lease->signatureTemplate && $lease->signatureTemplate->completed_at)
                                <div class="text-[10px] text-green-600 mt-1">
                                    Signed {{ $lease->signatureTemplate->completed_at->format('d M Y') }}
                                </div>
                            @endif
                        </div>
                        <div class="flex flex-col gap-1.5 ml-4">
                            @if($lease->document)
                                <a href="{{ route('docuperfect.signatures.audit', $lease->document) }}"
                                   class="text-xs px-3 py-1 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50 text-center">
                                    Audit
                                </a>
                            @endif
                            @if($lease->signatureTemplate && $lease->signatureTemplate->signed_pdf_path)
                                <a href="{{ route('docuperfect.signatures.download', $lease->document) }}"
                                   class="text-xs px-3 py-1 rounded-lg bg-green-600 text-white hover:bg-green-700 text-center">
                                    Download PDF
                                </a>
                            @endif
                            <a href="{{ route('docuperfect.leases.history', $lease) }}"
                               class="text-xs px-3 py-1 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50 text-center">
                                History
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        @endif

        {{-- Completed lease-type documents with expiry picker --}}
        @if($completedLeaseDocs->isNotEmpty())
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden mt-3">
            <div class="px-4 py-2 bg-slate-50 border-b border-slate-200">
                <span class="text-xs text-slate-500 font-medium">Lease Documents — Set Expiry Dates</span>
            </div>
            <table class="w-full text-sm ds-table">
                <thead>
                    <tr>
                        <th class="text-left px-4 py-2 text-xs">Property</th>
                        <th class="text-left px-4 py-2 text-xs">Document</th>
                        <th class="text-left px-4 py-2 text-xs">Signed</th>
                        <th class="text-left px-4 py-2 text-xs">Lease Expiry</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($completedLeaseDocs as $doc)
                    @php
                        $st = $signatureTemplates->get($doc->id);
                        $signedDate = $st?->completed_at;
                        $expiry = $doc->lease_expiry_date;
                        $expiryDaysLeft = $expiry ? (int) now()->diffInDays($expiry, false) : null;
                        $expiryIndicator = match(true) {
                            $expiryDaysLeft === null => 'bg-slate-300',
                            $expiryDaysLeft <= 30 => 'bg-red-500',
                            $expiryDaysLeft <= 90 => 'bg-amber-500',
                            default => 'bg-green-500',
                        };
                    @endphp
                    <tr>
                        <td class="px-4 py-2 font-medium text-slate-800">{{ $doc->property_address ?: '—' }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ $doc->name }}</td>
                        <td class="px-4 py-2 text-slate-500 text-xs">{{ $signedDate?->format('d M Y') ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2">
                                <span class="inline-block w-2 h-2 rounded-full {{ $expiryIndicator }}"></span>
                                <input type="date" value="{{ $expiry?->format('Y-m-d') ?? '' }}"
                                       class="text-xs border-slate-300 rounded py-0.5 px-1.5 bg-white"
                                       @change="saveExpiry({{ $doc->id }}, $event.target.value)">
                                <span class="text-emerald-600 text-[10px] transition-opacity" :class="savedIndicators[{{ $doc->id }} + '-expiry'] ? 'opacity-100' : 'opacity-0'">Saved</span>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif

        @if($activeLeases->isEmpty() && $completedLeaseDocs->isEmpty())
        <div class="ds-status-card p-4 text-center">
            <div class="text-sm text-slate-400 italic">No active leases yet.</div>
        </div>
        @endif
    </div>

    {{-- Rejected / Archived --}}
    @if(isset($rejected) && $rejected->isNotEmpty())
    <div id="section-rejected" class="space-y-2 scroll-mt-4 mt-8">
        <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider cursor-pointer"
            @click="showRejected = !showRejected">
            Rejected ({{ $rejected->count() }})
            <span class="text-xs" x-text="showRejected ? '&#9660;' : '&#9654;'"></span>
        </h3>
        <div x-show="showRejected" x-collapse class="space-y-3">
            @foreach($rejected as $doc)
                @php $sigTemplate = $signatureTemplates->get($doc->id); @endphp
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 opacity-75">
                    <div class="flex items-start justify-between">
                        <div>
                            <h4 class="font-medium text-gray-600 line-through">{{ $doc->name }}</h4>
                            @if($sigTemplate && $sigTemplate->rejection_reason)
                                <p class="text-xs text-red-500 mt-1">
                                    Rejected: {{ $sigTemplate->rejection_reason }}
                                </p>
                            @endif
                            @if($sigTemplate && $sigTemplate->rejected_at)
                                <p class="text-xs text-gray-400">
                                    {{ $sigTemplate->rejected_at->format('d M Y H:i') }}
                                    @if($sigTemplate->rejectedBy)
                                        by {{ $sigTemplate->rejectedBy->name }}
                                    @endif
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Empty state --}}
    @if($counts['draft'] === 0 && $counts['ready_to_sign'] === 0 && $counts['awaiting_signatures'] === 0 && $counts['completed'] === 0 && $counts['pending_approval'] === 0 && $activeLeases->isEmpty())
    <div class="ds-status-card p-6 text-center">
        <div class="text-sm text-slate-500">No rental documents found. Create a document from a rental template to get started.</div>
    </div>
    @endif

    {{-- Upload on Behalf Modal --}}
    <div x-show="showUploadOnBehalfModal" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
        <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md" @click.away="showUploadOnBehalfModal = false">
            <h3 class="text-lg font-bold text-indigo-700 mb-2">Upload on Behalf</h3>
            <p class="text-sm text-gray-600 mb-4">Uploading signed document for <strong x-text="uploadOnBehalfPartyName"></strong></p>

            <form method="POST"
                  :action="'/docuperfect/documents/' + uploadOnBehalfDocId + '/signatures/inspect/' + uploadOnBehalfRequestId + '/upload-on-behalf'"
                  enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="auto_approve" value="1">

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Signed Document *</label>
                    <input type="file" name="files[]" multiple required
                           accept=".pdf,.jpg,.jpeg,.png"
                           class="w-full text-sm border rounded-lg px-3 py-2 file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                    <p class="text-[10px] text-slate-400 mt-1">PDF, JPG or PNG. Max 20MB per file.</p>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-1">How was it received? *</label>
                    <select name="receive_method" required class="w-full border rounded-lg px-3 py-2 text-sm">
                        <option value="">-- Select --</option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="email">Email</option>
                        <option value="in_person">In-person</option>
                    </select>
                </div>

                <div class="flex justify-end gap-3">
                    <button type="button" @click="showUploadOnBehalfModal = false"
                            class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                    <button type="submit"
                            class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">
                        Upload &amp; Approve
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Rejection Modal --}}
    <div x-show="showRejectModal" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
        <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md" @click.away="showRejectModal = false">
            <h3 class="text-lg font-bold text-red-700 mb-4">Reject Document</h3>
            <p class="text-sm text-gray-600 mb-4" x-text="'Rejecting: ' + rejectDocName"></p>

            <form method="POST" :action="'/docuperfect/documents/' + rejectDocId + '/reject'">
                @csrf

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reason for Rejection *</label>
                    <textarea name="rejection_reason" rows="3" required minlength="5"
                              class="w-full border rounded-lg px-3 py-2 text-sm"
                              placeholder="e.g. Wrong rental amount, tenant name misspelled..."></textarea>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">What would you like to do?</label>
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="action" value="revise" checked
                                   class="text-blue-600">
                            <span class="text-sm">Create a revised version (clone with fields, clear signatures)</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="action" value="archive"
                                   class="text-blue-600">
                            <span class="text-sm">Just archive it (no further action)</span>
                        </label>
                    </div>
                </div>

                <div class="flex justify-end gap-3">
                    <button type="button" @click="showRejectModal = false"
                            class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                    <button type="submit"
                            class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700">
                        Reject Document
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>

{{-- Tile scroll-to-section --}}
<script>
function scrollToSection(sectionId) {
    var el = document.getElementById(sectionId);
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        el.classList.add('ring-2', 'ring-blue-400', 'ring-offset-2');
        setTimeout(function() {
            el.classList.remove('ring-2', 'ring-blue-400', 'ring-offset-2');
        }, 2000);
    }
}
</script>

{{-- Dashboard polling for live updates --}}
<script>
(function() {
    let lastKnownUpdate = null;
    let initialized = false;

    setInterval(async () => {
        try {
            const response = await fetch('{{ route("docuperfect.rental.statusCheck") }}');
            if (!response.ok) return;
            const data = await response.json();

            if (!initialized) {
                lastKnownUpdate = data.last_update || '';
                initialized = true;
            } else if (data.last_update && data.last_update !== lastKnownUpdate) {
                lastKnownUpdate = data.last_update;
                showUpdateBanner();
            }

            const badge = document.getElementById('pending-approval-count');
            if (badge && data.pending_approval_count !== undefined) {
                badge.textContent = data.pending_approval_count;
            }
        } catch (e) {
            // Silent fail
        }
    }, 60000);

    function showUpdateBanner() {
        if (document.getElementById('ds-update-banner')) return;
        const banner = document.createElement('div');
        banner.id = 'ds-update-banner';
        banner.className = 'fixed top-4 right-4 bg-blue-600 text-white px-4 py-2 rounded-lg shadow-lg z-50 flex items-center gap-3 text-sm';
        banner.innerHTML = '<span>Signing status updated</span>'
            + '<button onclick="window.location.reload()" class="bg-white/20 hover:bg-white/30 px-3 py-1 rounded text-sm font-medium">Refresh</button>'
            + '<button onclick="this.parentElement.remove()" class="opacity-70 hover:opacity-100 ml-1">&times;</button>';
        document.body.appendChild(banner);
    }
})();
</script>
@endsection
