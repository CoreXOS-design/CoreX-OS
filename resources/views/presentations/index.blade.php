@extends('layouts.corex')

@section('corex-content')

<div class="max-w-6xl mx-auto">
    <x-list-header
        title="Presentations"
        :form-action="route('presentations.index')"
        :paginator="$presentations"
        search-placeholder="Search address, seller, suburb..."
    >
        <x-slot:filters>
            <select name="status" onchange="this.form.submit()" class="list-header-filter">
                <option value="active" {{ request('status', 'active') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="archived" {{ request('status') === 'archived' ? 'selected' : '' }}>Archived</option>
            </select>

            <select name="record_status" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All statuses</option>
                @foreach($statuses as $s)
                <option value="{{ $s }}" {{ request('record_status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                @endforeach
            </select>

            @if($propertyTypes->isNotEmpty())
            <select name="property_type" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All types</option>
                @foreach($propertyTypes as $pt)
                <option value="{{ $pt }}" {{ request('property_type') === $pt ? 'selected' : '' }}>{{ ucfirst($pt) }}</option>
                @endforeach
            </select>
            @endif

            @if($agents->isNotEmpty())
            <select name="agent" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All agents</option>
                @foreach($agents as $ag)
                <option value="{{ $ag->id }}" {{ request('agent') == $ag->id ? 'selected' : '' }}>{{ $ag->name }}</option>
                @endforeach
            </select>
            @endif
        </x-slot:filters>

        <x-slot:actions>
            @if(\Illuminate\Support\Facades\Route::has('admin.p24-suburbs.index'))
            <a href="{{ route('admin.p24-suburbs.index') }}" class="corex-btn-outline text-xs">P24 Suburbs</a>
            @endif
            <a href="{{ route('presentations.create') }}" class="corex-btn-primary text-sm">+ New Presentation</a>
        </x-slot:actions>
    </x-list-header>

    {{-- Presentations table --}}
    <div class="ds-status-card" style="border-left-color: var(--ds-cyan); padding: 0; overflow: hidden;">
        @if($presentations->isEmpty())
            <div class="px-6 py-12 text-center">
                <p class="text-gray-400 text-sm mb-4">No presentations found.</p>
                <a href="{{ route('presentations.create') }}" class="corex-btn-primary">
                    Create your first presentation
                </a>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm ds-table">
                    <thead>
                        <tr>
                            <x-sort-header field="property_address" label="Title / Address" />
                            <th class="text-left px-4 py-3">Property</th>
                            <x-sort-header field="seller_name" label="Seller" />
                            <x-sort-header field="status" label="Status" />
                            @if($agents->isNotEmpty())
                            <th class="text-left px-4 py-3">Agent</th>
                            @endif
                            <x-sort-header field="created_at" label="Created" />
                            <th class="text-left px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($presentations as $pres)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <div class="font-semibold" style="color:#0f172a;">{{ $pres->title }}</div>
                                    <div class="text-xs text-gray-500 mt-0.5">{{ $pres->property_address ?? '—' }}</div>
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
                                    @if($showArchived)
                                        <span class="ds-badge ds-badge-warning">Archived</span>
                                    @else
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
                                    @endif
                                </td>
                                @if($agents->isNotEmpty())
                                <td class="px-4 py-3 text-gray-600 text-xs">
                                    {{ $pres->creator?->name ?? '—' }}
                                </td>
                                @endif
                                <td class="px-4 py-3 text-gray-400 text-xs">
                                    {{ $pres->created_at->format('d M Y') }}
                                </td>
                                <td class="px-4 py-3">
                                    @if($showArchived)
                                        <form method="POST" action="{{ route('presentations.restore', $pres->id) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs font-medium text-emerald-600 hover:text-emerald-800">Restore</button>
                                        </form>
                                    @else
                                        <a href="{{ route('presentations.show', $pres) }}"
                                           class="ds-agent-link text-xs font-medium">
                                            Open &rarr;
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-3 border-t" style="border-color: #e2e8f0;">
                {{ $presentations->links() }}
            </div>
        @endif
    </div>
</div>

@endsection
