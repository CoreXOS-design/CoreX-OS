@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5" x-data="{ expanded: {} }">

    {{-- Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Portal Leads</h1>
                <p class="text-sm text-white/60">Buyer enquiries received from Property24 and Private Property.</p>
            </div>
            <div class="text-xs text-white/70">
                Total: <span class="font-semibold text-white">{{ $leads->total() }}</span>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('corex.portal-leads.index') }}"
          class="rounded-md p-4 grid grid-cols-1 md:grid-cols-6 gap-3"
          style="background: var(--surface, #fff); border:1px solid var(--border, #e5e7eb);">

        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Portal</label>
            <select name="portal" class="w-full rounded-md border-gray-300 text-sm">
                <option value="">All</option>
                <option value="p24" @selected(($filters['portal'] ?? '') === 'p24')>Property24</option>
                <option value="pp"  @selected(($filters['portal'] ?? '') === 'pp')>Private Property</option>
            </select>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">From</label>
            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="w-full rounded-md border-gray-300 text-sm" />
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">To</label>
            <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="w-full rounded-md border-gray-300 text-sm" />
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Agent</label>
            <select name="agent_id" class="w-full rounded-md border-gray-300 text-sm">
                <option value="">All agents</option>
                @foreach($agents as $a)
                    <option value="{{ $a->id }}" @selected((string)($filters['agent_id'] ?? '') === (string)$a->id)>{{ $a->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Status</label>
            <select name="status" class="w-full rounded-md border-gray-300 text-sm">
                <option value="">All</option>
                <option value="new"      @selected(($filters['status'] ?? '') === 'new')>New Contact</option>
                <option value="existing" @selected(($filters['status'] ?? '') === 'existing')>Already Exists</option>
            </select>
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="corex-btn-primary text-sm w-full">Apply</button>
            <a href="{{ route('corex.portal-leads.index') }}" class="text-xs underline text-gray-500 whitespace-nowrap">Reset</a>
        </div>
    </form>

    {{-- Leads table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface, #fff); border:1px solid var(--border, #e5e7eb);">
        <table class="w-full text-sm">
            <thead class="text-xs uppercase tracking-wide" style="background:var(--surface-2, #f8fafc);">
                <tr>
                    <th class="text-left px-3 py-2">Received</th>
                    <th class="text-left px-3 py-2">Portal</th>
                    <th class="text-left px-3 py-2">Type</th>
                    <th class="text-left px-3 py-2">Name</th>
                    <th class="text-left px-3 py-2">Contact</th>
                    <th class="text-left px-3 py-2">Property</th>
                    <th class="text-left px-3 py-2">Message</th>
                    <th class="text-left px-3 py-2">Status</th>
                    <th class="text-left px-3 py-2">Agent</th>
                    <th class="text-left px-3 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($leads as $lead)
                    @php
                        $agent = $lead->existingContactAgent
                              ?? ($lead->listing && $lead->listing->agent_id
                                  ? \App\Models\User::find($lead->listing->agent_id)
                                  : null);
                    @endphp
                    <tr class="border-t" style="border-color:var(--border,#e5e7eb);"
                        :class="expanded[{{ $lead->id }}] ? 'bg-amber-50' : ''">
                        <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-700">
                            {{ optional($lead->received_at)->format('Y-m-d H:i') }}
                        </td>
                        <td class="px-3 py-2">
                            @if($lead->portal === 'p24')
                                <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold text-white" style="background:#ef4444;">P24</span>
                            @else
                                <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold text-white" style="background:#3b82f6;">PP</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs">{{ $lead->lead_type }}{{ $lead->is_whatsapp ? ' / WhatsApp' : '' }}</td>
                        <td class="px-3 py-2 font-medium">{{ $lead->name }}</td>
                        <td class="px-3 py-2 text-xs">
                            @if($lead->email)<div>{{ $lead->email }}</div>@endif
                            @if($lead->phone)<div class="text-gray-500">{{ $lead->phone }}</div>@endif
                        </td>
                        <td class="px-3 py-2 text-xs">
                            @if($lead->listing)
                                <a href="{{ route('corex.properties.show', $lead->listing_id) }}" class="text-blue-600 hover:underline">
                                    {{ $lead->listing->title ?? ('#' . $lead->listing_id) }}
                                </a>
                            @elseif($lead->listing_portal_ref)
                                <span class="text-gray-400">ref {{ $lead->listing_portal_ref }}</span>
                            @else
                                <span class="text-gray-300">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs text-gray-700 max-w-xs">
                            <div x-show="!expanded[{{ $lead->id }}]" class="truncate">{{ \Illuminate\Support\Str::limit($lead->message, 80) }}</div>
                            <div x-show="expanded[{{ $lead->id }}]" x-cloak class="whitespace-pre-wrap">{{ $lead->message }}</div>
                        </td>
                        <td class="px-3 py-2">
                            @if($lead->contact_exists)
                                <span class="inline-block px-2 py-0.5 rounded text-[10px] font-semibold" style="background:#fef3c7;color:#92400e;">
                                    Already Exists{{ $lead->existingContactAgent ? ' — ' . $lead->existingContactAgent->name : '' }}
                                </span>
                            @else
                                <span class="inline-block px-2 py-0.5 rounded text-[10px] font-semibold" style="background:#d1fae5;color:#065f46;">
                                    New Contact{{ $agent ? ' — ' . $agent->name : '' }}
                                </span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs">{{ $agent->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-right">
                            <button type="button"
                                    class="text-xs underline text-gray-600"
                                    @click="expanded[{{ $lead->id }}] = !expanded[{{ $lead->id }}]"
                                    x-text="expanded[{{ $lead->id }}] ? 'Less' : 'More'"></button>
                        </td>
                    </tr>
                    <tr x-show="expanded[{{ $lead->id }}]" x-cloak class="border-t" style="border-color:var(--border,#e5e7eb);">
                        <td colspan="10" class="px-6 py-3 bg-gray-50">
                            <div class="text-[11px] text-gray-500 font-semibold mb-1">Raw payload</div>
                            <pre class="text-[11px] text-gray-700 overflow-auto max-h-64 bg-white p-2 rounded">{{ json_encode($lead->lead_source_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-3 py-8 text-center text-gray-400 text-sm">No portal leads yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $leads->links() }}</div>
</div>
@endsection
