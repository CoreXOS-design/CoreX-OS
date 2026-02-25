@extends('layouts.nexus')

@section('nexus-content')
<div class="max-w-6xl mx-auto space-y-5">

    {{-- Header --}}
    <div class="rounded-2xl px-6 py-4 flex items-center justify-between" style="background:var(--brand-primary, #0b2a4a);">
        <div>
            <h2 class="text-xl font-bold text-white">Properties</h2>
            <div class="text-sm mt-0.5" style="color:rgba(255,255,255,0.6);">Manage listings and publish them to the website.</div>
        </div>
        <a href="{{ route('nexus.properties.create') }}"
           class="px-4 py-2 rounded-lg text-sm font-semibold text-white transition-opacity"
           style="background:var(--brand-secondary, #00b4d8);"
           onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
            + New Property
        </a>
    </div>

    @if(session('success'))
        <div class="rounded-xl border px-4 py-3 text-sm font-medium" style="background:#f0fdf4;border-color:#bbf7d0;color:#166534;">
            {{ session('success') }}
        </div>
    @endif

    {{-- Listings table --}}
    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b" style="background:#f8fafc;">
                    <th class="text-left py-3 px-5 font-semibold text-xs uppercase tracking-wider" style="color:var(--brand-primary, #0b2a4a);">Title</th>
                    <th class="text-left py-3 px-5 font-semibold text-xs uppercase tracking-wider" style="color:var(--brand-primary, #0b2a4a);">Suburb</th>
                    <th class="text-left py-3 px-5 font-semibold text-xs uppercase tracking-wider" style="color:var(--brand-primary, #0b2a4a);">Type</th>
                    <th class="text-right py-3 px-5 font-semibold text-xs uppercase tracking-wider" style="color:var(--brand-primary, #0b2a4a);">Price</th>
                    <th class="text-center py-3 px-5 font-semibold text-xs uppercase tracking-wider" style="color:var(--brand-primary, #0b2a4a);">Status</th>
                    <th class="text-center py-3 px-5 font-semibold text-xs uppercase tracking-wider" style="color:var(--brand-primary, #0b2a4a);">Sync</th>
                    <th class="text-left py-3 px-5 font-semibold text-xs uppercase tracking-wider" style="color:var(--brand-primary, #0b2a4a);">Agent</th>
                    <th class="py-3 px-5"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($properties as $property)
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="py-3 px-5 font-semibold text-slate-800">
                            {{ $property->title }}
                            <div class="text-xs text-slate-400 font-normal mt-0.5">
                                {{ $property->beds }}bd / {{ $property->baths }}ba
                                @if($property->size_m2) · {{ number_format($property->size_m2) }} m²@endif
                            </div>
                        </td>
                        <td class="py-3 px-5 text-slate-600">{{ $property->suburb }}</td>
                        <td class="py-3 px-5 text-slate-500 capitalize">{{ str_replace('_', ' ', $property->property_type) }}</td>
                        <td class="py-3 px-5 text-right text-slate-700 font-mono text-xs">{{ $property->formattedPrice() }}</td>
                        <td class="py-3 px-5 text-center">
                            @php
                                $statusColours = [
                                    'draft'     => ['background:#f1f5f9;color:#64748b;', 'Draft'],
                                    'active'    => ['background:#dcfce7;color:#166534;', 'Active'],
                                    'sold'      => ['background:#dbeafe;color:#1e40af;', 'Sold'],
                                    'withdrawn' => ['background:#fee2e2;color:#991b1b;', 'Withdrawn'],
                                ];
                                [$sc, $sl] = $statusColours[$property->status] ?? ['background:#f1f5f9;color:#64748b;', ucfirst($property->status)];
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold" style="{{ $sc }}">{{ $sl }}</span>
                        </td>
                        <td class="py-3 px-5 text-center">
                            @if($property->published_at)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold" style="background:#dcfce7;color:#166534;">Published</span>
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="py-3 px-5 text-slate-500 text-xs">{{ $property->agent?->name ?? '—' }}</td>
                        <td class="py-3 px-5 text-right">
                            <a href="{{ route('nexus.properties.edit', $property) }}"
                               class="text-xs font-semibold transition-colors"
                               style="color:var(--brand-secondary, #00b4d8);"
                               onmouseover="this.style.color='var(--brand-primary, #0b2a4a)'" onmouseout="this.style.color='var(--brand-secondary, #00b4d8)'">
                                Edit
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="py-10 text-center text-sm text-slate-400 italic">
                            No properties yet.
                            <a href="{{ route('nexus.properties.create') }}" style="color:var(--brand-secondary, #00b4d8);">Create the first one.</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

</div>
@endsection
