@extends('layouts.corex-app')

@section('corex-content')
<div class="p-6 lg:p-8">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">FICA Compliance</h1>
            <p class="text-sm text-slate-500 mt-1">
                @if($canSeeAll)
                    All FICA submissions for the agency
                @else
                    Your FICA verification requests
                @endif
            </p>
        </div>
        <div class="flex items-center gap-2 mt-3 sm:mt-0">
            <a href="{{ route('compliance.rmcp') }}" class="inline-flex items-center gap-1.5 px-3 py-2 border border-slate-300 text-slate-700 text-sm font-semibold hover:bg-slate-50 transition">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                View RMCP
            </a>
            <a href="{{ route('compliance.fica.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-slate-900 text-white text-sm font-semibold hover:bg-slate-800 transition">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Send Online FICA
            </a>
            <a href="{{ route('compliance.fica.wet-ink.create') }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold transition" style="background:#00d4aa; color:#0f172a; border-radius:3px;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Create Wet-Ink FICA
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="mb-4 p-3 bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">{{ session('success') }}</div>
    @endif

    {{-- Pipeline flow indicator --}}
    <div class="mb-6 bg-white border border-slate-200 p-4">
        <div class="flex items-center justify-between text-center text-xs">
            @php
                $stages = [
                    ['label' => 'Awaiting Client', 'count' => $counts['draft'], 'color' => '#64748b'],
                    ['label' => 'Awaiting Agent Review', 'count' => $counts['submitted'], 'color' => '#3b82f6'],
                    ['label' => 'Awaiting CO Approval', 'count' => $coQueueCount, 'color' => '#f59e0b'],
                    ['label' => 'Complete', 'count' => $counts['approved'], 'color' => '#059669'],
                ];
            @endphp
            @foreach($stages as $i => $stage)
                <div class="flex-1">
                    <div class="text-xl font-bold" style="color: {{ $stage['color'] }};">{{ $stage['count'] }}</div>
                    <div class="font-semibold text-slate-500 mt-0.5">{{ $stage['label'] }}</div>
                </div>
                @if($i < count($stages) - 1)
                    <svg class="w-4 h-4 text-slate-300 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                @endif
            @endforeach
        </div>
    </div>

    {{-- CO Queue summary card --}}
    @if($isCO && ($coQueueStats['count'] ?? 0) > 0)
        <div class="mb-4 p-4 bg-indigo-50 border border-indigo-200 flex items-center justify-between">
            <div>
                <span class="text-sm font-bold text-indigo-800">{{ $coQueueStats['count'] }} submission{{ $coQueueStats['count'] !== 1 ? 's' : '' }} awaiting your approval</span>
                @if($coQueueStats['oldest_days'] > 0)
                    <span class="text-xs text-indigo-600 ml-2">Oldest: {{ $coQueueStats['oldest_days'] }} day{{ $coQueueStats['oldest_days'] !== 1 ? 's' : '' }}</span>
                @endif
            </div>
            <a href="{{ route('compliance.fica.index', ['tab' => 'co_queue']) }}" class="text-xs font-semibold px-3 py-1.5 bg-indigo-600 text-white hover:bg-indigo-700 transition">
                View CO Queue
            </a>
        </div>
    @endif

    {{-- Tabs --}}
    <div class="flex flex-wrap gap-1 mb-4 text-sm font-medium border-b border-slate-200">
        @if($isCO)
            <a href="{{ route('compliance.fica.index', ['tab' => 'co_queue']) }}"
               class="px-4 py-2 {{ $tab === 'co_queue' ? 'border-b-2 border-indigo-600 text-indigo-700' : 'text-slate-500 hover:text-slate-700' }}">
                My CO Queue <span class="ml-1 text-xs bg-indigo-100 text-indigo-600 px-1.5 py-0.5 rounded-full">{{ $coQueueCount }}</span>
            </a>
        @endif
        <a href="{{ route('compliance.fica.index', ['tab' => 'all']) }}"
           class="px-4 py-2 {{ $tab === 'all' ? 'border-b-2 border-teal-600 text-teal-700' : 'text-slate-500 hover:text-slate-700' }}">
            All <span class="ml-1 text-xs bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded-full">{{ $counts['all'] }}</span>
        </a>
        <a href="{{ route('compliance.fica.index', ['tab' => 'draft']) }}"
           class="px-4 py-2 {{ $tab === 'draft' ? 'border-b-2 border-slate-600 text-slate-700' : 'text-slate-500 hover:text-slate-700' }}">
            Awaiting Client <span class="ml-1 text-xs bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded-full">{{ $counts['draft'] }}</span>
        </a>
        <a href="{{ route('compliance.fica.index', ['tab' => 'submitted']) }}"
           class="px-4 py-2 {{ $tab === 'submitted' ? 'border-b-2 border-blue-600 text-blue-700' : 'text-slate-500 hover:text-slate-700' }}">
            Awaiting Agent Review <span class="ml-1 text-xs bg-blue-100 text-blue-600 px-1.5 py-0.5 rounded-full">{{ $counts['submitted'] }}</span>
        </a>
        <a href="{{ route('compliance.fica.index', ['tab' => 'agent_approved']) }}"
           class="px-4 py-2 {{ $tab === 'agent_approved' ? 'border-b-2 border-amber-600 text-amber-700' : 'text-slate-500 hover:text-slate-700' }}">
            Awaiting CO Approval <span class="ml-1 text-xs bg-amber-100 text-amber-600 px-1.5 py-0.5 rounded-full">{{ $counts['agent_approved'] }}</span>
        </a>
        <a href="{{ route('compliance.fica.index', ['tab' => 'approved']) }}"
           class="px-4 py-2 {{ $tab === 'approved' ? 'border-b-2 border-emerald-600 text-emerald-700' : 'text-slate-500 hover:text-slate-700' }}">
            Approved <span class="ml-1 text-xs bg-emerald-100 text-emerald-600 px-1.5 py-0.5 rounded-full">{{ $counts['approved'] }}</span>
        </a>
        <a href="{{ route('compliance.fica.index', ['tab' => 'corrections_requested']) }}"
           class="px-4 py-2 {{ $tab === 'corrections_requested' ? 'border-b-2 border-orange-600 text-orange-700' : 'text-slate-500 hover:text-slate-700' }}">
            Corrections Needed <span class="ml-1 text-xs bg-orange-100 text-orange-600 px-1.5 py-0.5 rounded-full">{{ $counts['corrections_requested'] }}</span>
        </a>
        <a href="{{ route('compliance.fica.index', ['tab' => 'cancelled']) }}"
           class="px-4 py-2 {{ $tab === 'cancelled' ? 'border-b-2 border-slate-600 text-slate-700' : 'text-slate-500 hover:text-slate-700' }}">
            Cancelled <span class="ml-1 text-xs bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded-full">{{ $counts['cancelled'] }}</span>
        </a>
        <a href="{{ route('compliance.fica.index', ['tab' => 'rejected']) }}"
           class="px-4 py-2 {{ $tab === 'rejected' ? 'border-b-2 border-red-600 text-red-700' : 'text-slate-500 hover:text-slate-700' }}">
            Rejected <span class="ml-1 text-xs bg-red-100 text-red-600 px-1.5 py-0.5 rounded-full">{{ $counts['rejected'] }}</span>
        </a>
    </div>

    {{-- Search --}}
    <form method="GET" action="{{ route('compliance.fica.index') }}" class="mb-4">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <div class="flex gap-2">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search by contact name or email..." class="flex-1 px-3 py-2 border border-slate-300 text-sm focus:outline-none focus:border-teal-500">
            <button type="submit" class="px-4 py-2 bg-slate-900 text-white text-sm font-semibold hover:bg-slate-800 transition">Search</button>
            @if(request('search'))
                <a href="{{ route('compliance.fica.index', ['tab' => $tab]) }}" class="px-3 py-2 border border-slate-300 text-sm text-slate-500 hover:text-slate-700">Clear</a>
            @endif
        </div>
    </form>

    {{-- Table --}}
    <div class="bg-white border border-slate-200 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                    <th class="px-4 py-3">Contact</th>
                    <th class="px-4 py-3">Intake</th>
                    <th class="px-4 py-3">Entity</th>
                    <th class="px-4 py-3">Submitted</th>
                    <th class="px-4 py-3">Agent Review</th>
                    <th class="px-4 py-3">CO Review</th>
                    <th class="px-4 py-3">Risk</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($submissions as $sub)
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="px-4 py-3">
                            @if($sub->contact)
                                <div class="font-medium text-slate-900">{{ $sub->contact->full_name }}</div>
                                <div class="text-xs text-slate-400">{{ $sub->contact->email }}</div>
                            @else
                                <span class="text-slate-400">No contact</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($sub->intake_type === 'wet_ink')
                                <span class="inline-flex items-center px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(245,158,11,0.12); color:#d97706; border-radius:3px;">Wet-Ink</span>
                            @else
                                <span class="inline-flex items-center px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(0,212,170,0.12); color:#00d4aa; border-radius:3px;">Online</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-600 capitalize">{{ $sub->entity_type }}</td>
                        <td class="px-4 py-3 text-slate-600 text-xs">
                            @if($sub->signed_at)
                                {{ $sub->signed_at->format('d M Y') }}
                            @else
                                <span class="text-slate-300">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs">
                            @if($sub->agent_verified_by)
                                <div class="text-slate-700">{{ $sub->agentVerifiedBy->name ?? '—' }}</div>
                                <div class="text-slate-400">{{ $sub->agent_verified_at?->format('d M Y') }}</div>
                            @else
                                <span class="text-slate-300">Pending</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs">
                            @if($sub->co_verified_by)
                                <div class="text-slate-700">{{ $sub->coVerifiedBy->name ?? '—' }}</div>
                                <div class="text-slate-400">{{ $sub->co_verified_at?->format('d M Y') }}</div>
                            @else
                                <span class="text-slate-300">Pending</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($sub->risk_rating)
                                @php $rc = [1 => 'bg-emerald-100 text-emerald-700', 2 => 'bg-amber-100 text-amber-700', 3 => 'bg-red-100 text-red-700']; @endphp
                                <span class="inline-flex items-center px-1.5 py-0.5 text-xs font-semibold {{ $rc[$sub->risk_rating] ?? '' }}">
                                    {{ [1 => 'Low', 2 => 'Med', 3 => 'High'][$sub->risk_rating] ?? '' }}
                                </span>
                            @else
                                <span class="text-slate-300">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $sc = [
                                    'draft' => 'bg-slate-100 text-slate-600',
                                    'submitted' => 'bg-blue-100 text-blue-700',
                                    'under_review' => 'bg-blue-100 text-blue-700',
                                    'agent_approved' => 'bg-amber-100 text-amber-700',
                                    'corrections_requested' => 'bg-orange-100 text-orange-700',
                                    'approved' => 'bg-emerald-100 text-emerald-700',
                                    'rejected' => 'bg-red-100 text-red-700',
                                    'cancelled' => 'bg-slate-100 text-slate-500',
                                ];
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold {{ $sc[$sub->status] ?? 'bg-slate-100 text-slate-600' }}">
                                {{ $sub->status_label }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @php
                                $authUser = auth()->user();
                                $isMySubmission = $sub->requested_by === $authUser->id;
                                $canCoReview = $isCO || $isAdmin;
                            @endphp
                            <div class="flex items-center justify-end gap-1">
                                {{-- Copy link (online draft/corrections only) --}}
                                @if($sub->intake_type !== 'wet_ink' && $sub->token && in_array($sub->status, ['draft', 'corrections_requested']))
                                <button type="button" title="Copy form link"
                                        onclick="ficaCopyLink('{{ url('/fica/' . $sub->token) }}', this)"
                                        class="inline-flex items-center justify-center w-6 h-6 text-slate-400 hover:text-teal-600 transition">
                                    <svg class="fica-link-icon w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" /></svg>
                                    <svg class="fica-check-icon w-3.5 h-3.5 text-emerald-500" style="display:none;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                </button>
                                @endif

                                {{-- View always --}}
                                <a href="{{ route('compliance.fica.show', $sub) }}" class="px-2 py-1 text-xs font-medium" style="color:#00d4aa;">View</a>

                                {{-- Action button per status --}}
                                @if($sub->status === 'draft' && $sub->intake_type !== 'wet_ink')
                                    <form method="POST" action="{{ route('compliance.fica.resend', $sub) }}" class="inline">@csrf
                                        <button type="submit" class="px-2 py-1 text-xs font-semibold" style="color:#3b82f6;">Resend</button>
                                    </form>
                                    <form method="POST" action="{{ route('compliance.fica.cancel', $sub) }}" class="inline"
                                          onsubmit="return confirm('Cancel this FICA request? The client link will be voided.')">@csrf
                                        <button type="submit" class="px-2 py-1 text-xs font-semibold" style="color:#ef4444;">Cancel</button>
                                    </form>
                                @elseif($sub->status === 'submitted' && $isMySubmission)
                                    <a href="{{ route('compliance.fica.show', $sub) }}" class="px-2 py-1 text-xs font-semibold" style="color:#3b82f6;">Verify</a>
                                @elseif($sub->status === 'agent_approved' && $canCoReview)
                                    <a href="{{ route('compliance.fica.compliance-review', $sub) }}" class="px-2 py-1 text-xs font-semibold" style="color:#f59e0b;">Review & Approve</a>
                                @elseif($sub->status === 'corrections_requested' && $isMySubmission)
                                    <a href="{{ route('compliance.fica.show', $sub) }}" class="px-2 py-1 text-xs font-semibold" style="color:#f97316;">Fix</a>
                                @endif

                                {{-- PDF download for approved --}}
                                @if($sub->status === 'approved')
                                    <a href="{{ route('compliance.fica.pdf', $sub) }}" target="_blank" class="px-2 py-1 text-xs text-slate-400 hover:text-slate-600" title="Download PDF">PDF</a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-12 text-center text-slate-400">
                            @if($tab === 'co_queue')
                                No submissions awaiting compliance officer review.
                            @elseif($tab === 'draft')
                                No submissions awaiting client response.
                            @elseif($tab === 'submitted')
                                No submissions awaiting agent review.
                            @elseif($tab === 'cancelled')
                                No cancelled submissions.
                            @else
                                No FICA submissions found.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $submissions->links() }}
    </div>
</div>

<script>
    function ficaCopyLink(text, btn) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        var linkIcon = btn.querySelector('.fica-link-icon');
        var checkIcon = btn.querySelector('.fica-check-icon');
        if (linkIcon && checkIcon) {
            linkIcon.style.display = 'none';
            checkIcon.style.display = '';
            setTimeout(function() { linkIcon.style.display = ''; checkIcon.style.display = 'none'; }, 1500);
        }
    }
</script>
@endsection
