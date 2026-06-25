@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="re-portal-leads-intro">
                <h1 class="text-xl font-bold tracking-tight text-white leading-tight">Portal Leads</h1>
                <p class="text-sm" style="color: rgba(255,255,255,0.6);">Buyer enquiries received from Property24 and Private Property.</p>
            </div>
            <div class="text-xs" style="color: rgba(255,255,255,0.7);">
                Total: <span class="font-semibold text-white">{{ $leads->total() }}</span>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('corex.portal-leads.index') }}"
          data-tour="re-portal-leads-filters"
          class="rounded-md p-4 grid grid-cols-1 md:grid-cols-6 gap-3 transition-all duration-300"
          style="background: var(--surface); border: 1px solid var(--border);">

        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Portal</label>
            <select name="portal"
                    class="w-full rounded-md text-sm transition-all duration-300"
                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All</option>
                <option value="p24" @selected(($filters['portal'] ?? '') === 'p24')>Property24</option>
                <option value="pp"  @selected(($filters['portal'] ?? '') === 'pp')>Private Property</option>
            </select>
        </div>

        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">From</label>
            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}"
                   class="w-full rounded-md text-sm transition-all duration-300"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" />
        </div>

        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">To</label>
            <input type="date" name="to" value="{{ $filters['to'] ?? '' }}"
                   class="w-full rounded-md text-sm transition-all duration-300"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);" />
        </div>

        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Agent</label>
            <select name="agent_id"
                    class="w-full rounded-md text-sm transition-all duration-300"
                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All agents</option>
                @foreach($agents as $a)
                    <option value="{{ $a->id }}" @selected((string)($filters['agent_id'] ?? '') === (string)$a->id)>{{ $a->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Status</label>
            <select name="status"
                    data-tour="re-portal-leads-status"
                    class="w-full rounded-md text-sm transition-all duration-300"
                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All</option>
                <option value="new"      @selected(($filters['status'] ?? '') === 'new')>New Contact</option>
                <option value="existing" @selected(($filters['status'] ?? '') === 'existing')>Already Exists</option>
            </select>
        </div>

        <div class="flex items-end gap-2">
            <button type="submit"
                    class="w-full rounded-md text-sm font-semibold text-white px-3 py-2 shadow-lg transition-all duration-300"
                    style="background: var(--brand-button, #0ea5e9);">Apply</button>
            <a href="{{ route('corex.portal-leads.index') }}"
               class="text-xs whitespace-nowrap transition-all duration-300"
               style="color: var(--text-muted);">Reset</a>
        </div>
    </form>

    {{-- Leads table --}}
    <div class="rounded-md overflow-hidden"
         data-tour="re-portal-leads-table"
         style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-[10px] uppercase tracking-wider"
                    style="background: var(--surface-2); color: var(--text-muted);">
                    <th class="text-left px-3 py-2 font-semibold">Received</th>
                    <th class="text-left px-3 py-2 font-semibold">Portal</th>
                    <th class="text-left px-3 py-2 font-semibold">Type</th>
                    <th class="text-left px-3 py-2 font-semibold">Name</th>
                    <th class="text-left px-3 py-2 font-semibold">Contact</th>
                    <th class="text-left px-3 py-2 font-semibold">Property</th>
                    <th class="text-left px-3 py-2 font-semibold">Message</th>
                    <th class="text-left px-3 py-2 font-semibold">Status</th>
                    <th class="text-left px-3 py-2 font-semibold">Agent</th>
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
                    <tr class="transition-all duration-300"
                        style="border-top: 1px solid var(--border); color: var(--text-primary);">
                        <td class="px-3 py-2 whitespace-nowrap text-xs" style="color: var(--text-secondary);">
                            {{ optional($lead->received_at)->format('Y-m-d H:i') }}
                        </td>
                        <td class="px-3 py-2">
                            @if($lead->portal === 'p24')
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[10px] font-bold text-white"
                                      style="background: var(--brand-default, #0b2a4a);">P24</span>
                            @else
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[10px] font-bold text-white"
                                      style="background: var(--brand-button, #0ea5e9);">PP</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs" style="color: var(--text-secondary);">{{ $lead->lead_type }}{{ $lead->is_whatsapp ? ' / WhatsApp' : '' }}</td>
                        <td class="px-3 py-2 font-medium">
                            @if($lead->contact)
                                <a href="{{ route('corex.contacts.show', $lead->contact_id) }}"
                                   class="transition-all duration-300 hover:underline"
                                   style="color: var(--brand-icon, #0ea5e9);"
                                   title="View contact">
                                    {{ $lead->name }}
                                </a>
                            @else
                                {{ $lead->name }}
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs">
                            @if($lead->email)
                                <div style="color: var(--text-primary);">{{ $lead->email }}</div>
                            @endif
                            @if($lead->phone)
                                <div style="color: var(--text-muted);">{{ $lead->phone }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs">
                            @if($lead->listing)
                                <a href="{{ route('corex.properties.show', $lead->listing_id) }}"
                                   class="font-medium transition-all duration-300"
                                   style="color: var(--brand-icon, #0ea5e9);">
                                    {{ $lead->listing->title ?? ('#' . $lead->listing_id) }}
                                </a>
                            @elseif($lead->listing_portal_ref)
                                <span style="color: var(--text-muted);">ref {{ $lead->listing_portal_ref }}</span>
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs max-w-xs whitespace-pre-wrap" style="color: var(--text-secondary);">{{ $lead->message }}</td>
                        <td class="px-3 py-2">
                            @if($lead->contact_exists)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold"
                                      style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                                    Already Exists{{ $lead->existingContactAgent ? ' — ' . $lead->existingContactAgent->name : '' }}
                                </span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold text-white"
                                      style="background: var(--brand-icon, #0ea5e9);">
                                    New Contact{{ $agent ? ' — ' . $agent->name : '' }}
                                </span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs" style="color: var(--text-secondary);">{{ $agent->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-3 py-8 text-center text-sm"
                            style="color: var(--text-muted);">No portal leads yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $leads->links() }}</div>
</div>
@endsection
