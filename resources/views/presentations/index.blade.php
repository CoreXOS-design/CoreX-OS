@extends('layouts.corex')

@section('corex-content')

{{-- Navy header bar --}}
<div class="max-w-6xl mx-auto">
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 mb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
            <div>
                <h2 class="text-xl font-bold text-white leading-tight">Presentations</h2>
                <div class="text-sm text-white/60">Seller presentations with market analysis</div>
            </div>
            <a href="{{ route('presentations.create') }}"
               class="corex-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3); background:transparent;">
                + New Presentation
            </a>
        </div>
    </div>

    {{-- Flash messages handled by global toast system --}}

    {{-- Presentations table --}}
    <div class="ds-status-card" style="border-left-color: var(--ds-cyan); padding: 0; overflow: hidden;">
        @if($presentations->isEmpty())
            <div class="px-6 py-12 text-center">
                <p class="text-gray-400 text-sm mb-4">No presentations yet.</p>
                <a href="{{ route('presentations.create') }}" class="corex-btn-primary">
                    Create your first presentation
                </a>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm ds-table">
                    <thead>
                        <tr>
                            <th class="text-left px-4 py-3">Title</th>
                            <th class="text-left px-4 py-3">Address</th>
                            <th class="text-left px-4 py-3">Property</th>
                            <th class="text-left px-4 py-3">Seller</th>
                            <th class="text-left px-4 py-3">Status</th>
                            <th class="text-left px-4 py-3">Last Updated</th>
                            <th class="text-left px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($presentations as $pres)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-semibold" style="color:#0b2a4a;">
                                    {{ $pres->title }}
                                </td>
                                <td class="px-4 py-3 text-gray-600 text-xs">
                                    {{ $pres->property_address ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    @if($pres->suburb || $pres->property_type)
                                        <span class="text-gray-700">{{ $pres->suburb ?? '—' }}</span>
                                        @if($pres->property_type)
                                            <span class="text-gray-400"> · {{ ucfirst($pres->property_type) }}</span>
                                        @endif
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-600 text-xs">
                                    {{ $pres->seller_name ?? '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    @php
                                        $badgeClass = match($pres->status) {
                                            'presented' => 'ds-badge-info',
                                            'locked'    => 'ds-badge-success',
                                            default     => 'ds-badge-default',
                                        };
                                    @endphp
                                    <span class="ds-badge {{ $badgeClass }}">
                                        {{ ucfirst($pres->status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-400 text-xs">
                                    {{ $pres->updated_at->format('Y-m-d H:i') }}
                                </td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('presentations.show', $pres) }}"
                                       class="ds-agent-link text-xs font-medium">
                                        Open &rarr;
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($presentations->hasPages())
                <div class="px-4 py-3 border-t" style="border-color: #e2e8f0;">
                    {{ $presentations->links() }}
                </div>
            @endif
        @endif
    </div>
</div>

@endsection