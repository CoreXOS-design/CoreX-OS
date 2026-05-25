@extends('layouts.corex-app')

@section('corex-content')

<div class="space-y-6">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Presentations</h1>
                <p class="text-sm text-white/60">Seller presentations, evaluations and pricing analysis.</p>
            </div>
            <div class="flex items-center gap-2">
                @if(\Illuminate\Support\Facades\Route::has('admin.p24-suburbs.index'))
                    <a href="{{ route('admin.p24-suburbs.index') }}" class="corex-btn-outline">P24 Suburbs</a>
                @endif
                <a href="{{ route('presentations.create') }}" class="corex-btn-primary">New Presentation</a>
            </div>
        </div>
    </div>

    {{-- Filter bar --}}
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
    </x-list-header>

    {{-- Presentations table --}}
    @if($presentations->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No presentations yet</h3>
            <p class="text-sm mb-4" style="color: var(--text-muted);">Create your first presentation to start tracking seller pitches and pricing analysis.</p>
            <a href="{{ route('presentations.create') }}" class="corex-btn-primary">Create Presentation</a>
        </div>
    @else
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <x-sort-header field="property_address" label="Title / Address" />
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Property</th>
                            <x-sort-header field="seller_name" label="Seller" />
                            <x-sort-header field="status" label="Status" />
                            @if($agents->isNotEmpty())
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                            @endif
                            <x-sort-header field="created_at" label="Created" />
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($presentations as $pres)
                            <tr style="border-top: 1px solid var(--border);">
                                <td class="px-4 py-3">
                                    <div class="font-semibold" style="color: var(--text-primary);">{{ $pres->title }}</div>
                                    <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $pres->property_address ?? '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    @if($pres->suburb || $pres->property_type)
                                        <span style="color: var(--text-secondary);">{{ $pres->suburb ?? '—' }}</span>
                                        @if($pres->property_type)
                                            <span style="color: var(--text-muted);"> · {{ ucfirst($pres->property_type) }}</span>
                                        @endif
                                    @else
                                        <span style="color: var(--text-muted);">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">
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
                                <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">
                                    {{ $pres->creator?->name ?? '—' }}
                                </td>
                                @endif
                                <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">
                                    {{ $pres->created_at->format('d M Y') }}
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    @if($showArchived)
                                        <form method="POST" action="{{ route('presentations.restore', $pres->id) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs font-semibold" style="color: var(--ds-green);">Restore</button>
                                        </form>
                                    @else
                                        <a href="{{ route('presentations.show', $pres) }}"
                                           class="text-xs font-semibold" style="color: var(--brand-icon);">
                                            Open &rarr;
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                {{ $presentations->links() }}
            </div>
        </div>
    @endif
</div>

@endsection
