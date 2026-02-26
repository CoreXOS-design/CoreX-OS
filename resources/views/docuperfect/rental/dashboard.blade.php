@extends('layouts.nexus')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Rental Documents</h2>
            <div class="text-sm text-white/60">Manage rental document signing workflows.</div>
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
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div class="ds-status-card p-4 text-center">
            <div class="text-2xl font-bold text-slate-700">{{ $counts['draft'] }}</div>
            <div class="text-xs text-slate-500 mt-1">Draft</div>
        </div>
        <div class="ds-status-card p-4 text-center">
            <div class="text-2xl font-bold text-blue-600">{{ $counts['ready_to_sign'] }}</div>
            <div class="text-xs text-slate-500 mt-1">Ready to Sign</div>
        </div>
        <div class="ds-status-card p-4 text-center">
            <div class="text-2xl font-bold text-amber-600">{{ $counts['awaiting_signatures'] }}</div>
            <div class="text-xs text-slate-500 mt-1">Awaiting Signatures</div>
        </div>
        <div class="ds-status-card p-4 text-center">
            <div class="text-2xl font-bold text-emerald-600">{{ $counts['completed'] }}</div>
            <div class="text-xs text-slate-500 mt-1">Completed</div>
        </div>
        <div class="ds-status-card p-4 text-center">
            <div class="text-2xl font-bold text-orange-600">{{ $activeLeaseCount }}</div>
            <div class="text-xs text-slate-500 mt-1">Active Leases</div>
        </div>
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
                    $urgencyText = match(true) {
                        $daysLeft <= 0  => 'text-red-700',
                        $daysLeft <= 30 => 'text-red-600',
                        $daysLeft <= 60 => 'text-amber-600',
                        default         => 'text-emerald-600',
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

    {{-- Awaiting Signatures --}}
    @if($groups['awaiting_signatures']->isNotEmpty())
    <div class="space-y-2">
        <h3 class="text-sm font-semibold text-amber-700 uppercase tracking-wider">Awaiting Signatures</h3>
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <table class="w-full text-sm ds-table">
                <thead>
                    <tr>
                        <th class="text-left px-4 py-3">Document</th>
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
                                                {{-- Days since sent indicator --}}
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
                                                    @if($req->team_alerted_at)
                                                        <span class="text-amber-500 text-[10px] font-medium" title="Team alerted {{ $req->team_alerted_at->format('d M') }}">&#9888; alert sent</span>
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
                                        $wetInkReq = $sigTemplate->requests->first(fn($r) => $r->wet_ink_status === 'uploaded_pending_review');
                                        $activeReq = $sigTemplate->requests->first(fn($r) => in_array($r->status, ['pending', 'viewed', 'partially_signed']));
                                    @endphp
                                    @if($wetInkReq)
                                        <a href="{{ route('docuperfect.signatures.wetInkReview', ['document' => $doc->id, 'signingRequest' => $wetInkReq->id]) }}"
                                           class="text-amber-600 hover:underline text-xs font-medium">
                                            Review Wet Ink
                                        </a>
                                    @endif
                                    @if($activeReq)
                                        <form method="POST" action="{{ route('docuperfect.signatures.sendReminder', ['document' => $doc->id, 'signatureRequest' => $activeReq->id]) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-amber-600 hover:underline text-xs" onclick="return confirm('Send reminder to {{ $activeReq->signer_name }}?')">
                                                Send Reminder
                                            </button>
                                        </form>
                                    @endif
                                @endif
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
    <div class="space-y-2">
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
    <div class="space-y-2">
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
    <div class="space-y-2">
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

    {{-- Empty state --}}
    @if($counts['draft'] === 0 && $counts['ready_to_sign'] === 0 && $counts['awaiting_signatures'] === 0 && $counts['completed'] === 0)
    <div class="ds-status-card p-6 text-center">
        <div class="text-sm text-slate-500">No rental documents found. Create a document from a rental template to get started.</div>
    </div>
    @endif

</div>
@endsection
