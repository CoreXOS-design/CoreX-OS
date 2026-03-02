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
            <div class="flex items-center gap-2">
                @if(\Illuminate\Support\Facades\Route::has('admin.p24-suburbs.index'))
                <a href="{{ route('admin.p24-suburbs.index') }}"
                   class="corex-btn-outline" style="color:rgba(255,255,255,0.7); border-color:rgba(255,255,255,0.18); background:transparent; font-size:0.75rem; padding:0.3rem 0.7rem;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:0.875rem;height:0.875rem;">
                        <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    P24 Suburbs
                </a>
                @endif
                <a href="{{ route('presentations.create') }}"
                   class="corex-btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.3); background:transparent;">
                    + New Presentation
                </a>
            </div>
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