@extends('layouts.corex-app')

@section('corex-content')
<div class="p-6 lg:p-8">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">FICA Compliance</h1>
            <p class="text-sm text-slate-500 mt-1">Manage client FICA verification requests</p>
        </div>
        <a href="{{ route('compliance.fica.create') }}" class="mt-3 sm:mt-0 inline-flex items-center gap-2 px-4 py-2 bg-slate-900 text-white text-sm font-semibold hover:bg-slate-800 transition">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
            Send FICA Request
        </a>
    </div>

    {{-- Success flash --}}
    @if(session('success'))
        <div class="mb-4 p-3 bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
            {{ session('success') }}
        </div>
    @endif

    {{-- Status tabs --}}
    <div class="flex gap-1 mb-6 text-sm font-medium border-b border-slate-200">
        <a href="{{ route('compliance.fica.index') }}" class="px-4 py-2 {{ !request('status') ? 'border-b-2 border-teal-600 text-teal-700' : 'text-slate-500 hover:text-slate-700' }}">
            All <span class="ml-1 text-xs bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded-full">{{ $counts['all'] }}</span>
        </a>
        <a href="{{ route('compliance.fica.index', ['status' => 'submitted']) }}" class="px-4 py-2 {{ request('status') === 'submitted' ? 'border-b-2 border-teal-600 text-teal-700' : 'text-slate-500 hover:text-slate-700' }}">
            Submitted <span class="ml-1 text-xs bg-blue-100 text-blue-600 px-1.5 py-0.5 rounded-full">{{ $counts['submitted'] }}</span>
        </a>
        <a href="{{ route('compliance.fica.index', ['status' => 'approved']) }}" class="px-4 py-2 {{ request('status') === 'approved' ? 'border-b-2 border-teal-600 text-teal-700' : 'text-slate-500 hover:text-slate-700' }}">
            Approved <span class="ml-1 text-xs bg-emerald-100 text-emerald-600 px-1.5 py-0.5 rounded-full">{{ $counts['approved'] }}</span>
        </a>
        <a href="{{ route('compliance.fica.index', ['status' => 'draft']) }}" class="px-4 py-2 {{ request('status') === 'draft' ? 'border-b-2 border-teal-600 text-teal-700' : 'text-slate-500 hover:text-slate-700' }}">
            Pending <span class="ml-1 text-xs bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded-full">{{ $counts['pending'] }}</span>
        </a>
    </div>

    {{-- Search --}}
    <form method="GET" action="{{ route('compliance.fica.index') }}" class="mb-4">
        @if(request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
        <div class="flex gap-2">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search by contact name or email..." class="flex-1 px-3 py-2 border border-slate-300 text-sm focus:outline-none focus:border-teal-500">
            <button type="submit" class="px-4 py-2 bg-slate-900 text-white text-sm font-semibold hover:bg-slate-800 transition">Search</button>
        </div>
    </form>

    {{-- Table --}}
    <div class="bg-white border border-slate-200 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                    <th class="px-4 py-3">Contact</th>
                    <th class="px-4 py-3">Entity</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Requested By</th>
                    <th class="px-4 py-3">Date</th>
                    <th class="px-4 py-3">Risk</th>
                    <th class="px-4 py-3"></th>
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
                        <td class="px-4 py-3 capitalize">{{ $sub->entity_type }}</td>
                        <td class="px-4 py-3">
                            @php
                                $colors = ['draft' => 'bg-slate-100 text-slate-600', 'submitted' => 'bg-blue-100 text-blue-700', 'under_review' => 'bg-yellow-100 text-yellow-700', 'corrections_requested' => 'bg-amber-100 text-amber-700', 'approved' => 'bg-emerald-100 text-emerald-700', 'rejected' => 'bg-red-100 text-red-700'];
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold {{ $colors[$sub->status] ?? 'bg-slate-100 text-slate-600' }}">
                                {{ $sub->status_label }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $sub->requestedBy->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $sub->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-3">
                            @if($sub->risk_rating)
                                @php $riskColors = [1 => 'text-emerald-600', 2 => 'text-amber-600', 3 => 'text-red-600']; @endphp
                                <span class="font-semibold {{ $riskColors[$sub->risk_rating] ?? '' }}">
                                    {{ ['1' => 'Low', '2' => 'Medium', '3' => 'High'][$sub->risk_rating] ?? $sub->risk_rating }}
                                </span>
                            @else
                                <span class="text-slate-300">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('compliance.fica.show', $sub) }}" class="text-teal-600 hover:text-teal-800 font-medium text-xs">Review</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-slate-400">
                            No FICA submissions found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    <div class="mt-4">
        {{ $submissions->links() }}
    </div>
</div>
@endsection
