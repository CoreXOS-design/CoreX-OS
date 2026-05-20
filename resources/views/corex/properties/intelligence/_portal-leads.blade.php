{{--
    Portal Leads panel — append-only to the property Intelligence tab.
    Reads from portal_leads filtered by listing_id. Spec: .ai/specs/portal-leads.md
--}}
@php
    $propertyId = $property->id ?? null;
    $portalLeadsForProp = $propertyId
        ? \App\Models\PortalLead::query()
            ->where('listing_id', $propertyId)
            ->orderByDesc('received_at')
            ->limit(50)
            ->get()
        : collect();
    $p24Count = $portalLeadsForProp->where('portal', 'p24')->count();
    $ppCount  = $portalLeadsForProp->where('portal', 'pp')->count();
@endphp

<div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Portal Leads</h3>
        <div class="flex items-center gap-2 text-xs">
            <span class="inline-flex items-center gap-1">
                <span class="inline-block w-2 h-2 rounded-full" style="background:#ef4444;"></span>
                P24 <span class="font-semibold">{{ $p24Count }}</span>
            </span>
            <span class="inline-flex items-center gap-1">
                <span class="inline-block w-2 h-2 rounded-full" style="background:#3b82f6;"></span>
                PP <span class="font-semibold">{{ $ppCount }}</span>
            </span>
            <span class="text-gray-500">· Total {{ $portalLeadsForProp->count() }}</span>
        </div>
    </div>

    @if($portalLeadsForProp->isEmpty())
        <div class="text-xs text-gray-400 py-3 text-center">No portal enquiries received for this property yet.</div>
    @else
        <table class="w-full text-xs">
            <thead class="text-[10px] uppercase tracking-wide" style="color: var(--text-muted);">
                <tr>
                    <th class="text-left px-2 py-1">Date</th>
                    <th class="text-left px-2 py-1">Portal</th>
                    <th class="text-left px-2 py-1">Name</th>
                    <th class="text-left px-2 py-1">Phone</th>
                    <th class="text-left px-2 py-1">Type</th>
                    <th class="text-left px-2 py-1">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($portalLeadsForProp as $pl)
                    <tr class="border-t" style="border-color: var(--border);">
                        <td class="px-2 py-1 whitespace-nowrap">{{ optional($pl->received_at)->format('Y-m-d H:i') }}</td>
                        <td class="px-2 py-1">
                            @if($pl->portal === 'p24')
                                <span class="inline-block px-1.5 py-0.5 rounded text-[9px] font-bold text-white" style="background:#ef4444;">P24</span>
                            @else
                                <span class="inline-block px-1.5 py-0.5 rounded text-[9px] font-bold text-white" style="background:#3b82f6;">PP</span>
                            @endif
                        </td>
                        <td class="px-2 py-1">{{ $pl->name }}</td>
                        <td class="px-2 py-1">{{ $pl->phone ?? '—' }}</td>
                        <td class="px-2 py-1">{{ $pl->lead_type }}</td>
                        <td class="px-2 py-1">
                            @if($pl->contact_exists)
                                <span class="text-amber-700">Already exists</span>
                            @else
                                <span class="text-emerald-700">New contact</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="text-right mt-2">
            <a href="{{ route('corex.portal-leads.index', ['from' => optional($portalLeadsForProp->last()->received_at)->toDateString()]) }}"
               class="text-xs text-blue-600 hover:underline">View on Portal Leads page →</a>
        </div>
    @endif
</div>
