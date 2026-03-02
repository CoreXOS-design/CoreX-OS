@extends('layouts.corex')

@section('corex-content')
<div class="max-w-6xl mx-auto">

    {{-- Navy header bar --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold text-white">Commercial Market Evaluations</h2>
            <p class="text-sm text-white/60 mt-0.5">Evaluate commercial, industrial, hospitality & agricultural properties</p>
        </div>
        <a href="{{ route('commercial-evaluations.create') }}" class="corex-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3); background:transparent;">
            + New Evaluation
        </a>
    </div>

    {{-- Flash messages handled by global toast system --}}

    {{-- Content --}}
    <div class="ds-status-card">
        @if($evaluations->isEmpty())
            <div class="px-6 py-12 text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-gray-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" />
                </svg>
                <p class="text-gray-400 text-sm mb-3">No commercial evaluations yet.</p>
                <a href="{{ route('commercial-evaluations.create') }}" class="corex-btn-primary text-sm">
                    Create Your First Evaluation
                </a>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100">
                            <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Property</th>
                            <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Type</th>
                            <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Town</th>
                            <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Asking Price</th>
                            <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Recommended Range</th>
                            <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Created</th>
                            <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($evaluations as $eval)
                        <tr class="hover:bg-gray-50/50">
                            <td class="px-4 py-3">
                                <a href="{{ route('commercial-evaluations.show', $eval) }}" class="font-medium text-gray-900 hover:text-[#00b4d8]">
                                    {{ $eval->property_name }}
                                </a>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ \App\Models\CommercialEvaluation::propertyTypeBadgeColor($eval->property_type) }}">
                                    {{ \App\Models\CommercialEvaluation::propertyTypeLabel($eval->property_type) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $eval->town ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ \App\Models\CommercialEvaluation::statusBadgeColor($eval->status) }}">
                                    {{ ucfirst($eval->status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right text-gray-700 font-mono text-xs">{{ $eval->asking_price_display }}</td>
                            <td class="px-4 py-3 text-right text-gray-700 font-mono text-xs">{{ $eval->recommended_range_display }}</td>
                            <td class="px-4 py-3 text-gray-500 text-xs">{{ $eval->created_at->format('Y-m-d') }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('commercial-evaluations.show', $eval) }}" class="text-[#00b4d8] hover:text-[#0096b7] text-xs font-medium">View</a>
                                    <a href="{{ route('commercial-evaluations.edit', $eval) }}" class="text-gray-500 hover:text-gray-700 text-xs font-medium">Edit</a>
                                    <form method="POST" action="{{ route('commercial-evaluations.destroy', $eval) }}" class="inline" onsubmit="return confirm('Delete this evaluation?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-500 hover:text-red-700 text-xs font-medium">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($evaluations->hasPages())
                <div class="px-4 py-3 border-t border-gray-100">
                    {{ $evaluations->links() }}
                </div>
            @endif
        @endif
    </div>
</div>
@endsection
