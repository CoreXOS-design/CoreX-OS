<x-app-layout>
    <x-slot name="header">
        <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                <div>
                    <h2 class="text-xl font-bold text-white leading-tight">
                        Filing Register &mdash; {{ $branchName }}
                    </h2>
                    <div class="text-sm text-white/60">Searchable index of physically filed mandates</div>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">

        {{-- Flash messages handled by global toast system --}}

        {{-- Filter bar --}}
        <div class="ds-status-card">
            <form method="GET" action="{{ route('filing-register.index') }}" class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[200px]">
                    <label class="ds-label block mb-1">Search</label>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Address, reference, seller, seq..." class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-1 focus:ring-cyan-500 focus:border-cyan-500">
                </div>
                <div>
                    <label class="ds-label block mb-1">Type</label>
                    <select name="document_type" class="px-3 py-2 border border-gray-200 rounded-lg text-sm">
                        <option value="All" {{ request('document_type') === 'All' ? 'selected' : '' }}>All</option>
                        <option value="OA" {{ request('document_type') === 'OA' ? 'selected' : '' }}>OA</option>
                        <option value="EA" {{ request('document_type') === 'EA' ? 'selected' : '' }}>EA</option>
                        <option value="Other" {{ request('document_type') === 'Other' ? 'selected' : '' }}>Other</option>
                    </select>
                </div>
                <div>
                    <label class="ds-label block mb-1">Status</label>
                    <select name="status" class="px-3 py-2 border border-gray-200 rounded-lg text-sm">
                        <option value="All" {{ request('status') === 'All' ? 'selected' : '' }}>All</option>
                        <option value="Active" {{ request('status') === 'Active' ? 'selected' : '' }}>Active</option>
                        <option value="Expiring" {{ request('status') === 'Expiring' ? 'selected' : '' }}>Expiring Soon</option>
                        <option value="Expired" {{ request('status') === 'Expired' ? 'selected' : '' }}>Expired</option>
                    </select>
                </div>
                @if($isAdmin)
                <div>
                    <label class="ds-label block mb-1">Branch</label>
                    <select name="branch_id" class="px-3 py-2 border border-gray-200 rounded-lg text-sm">
                        <option value="">All Branches</option>
                        @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" {{ request('branch_id') == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div>
                    <label class="ds-label block mb-1">Agent</label>
                    <select name="agent_id" class="px-3 py-2 border border-gray-200 rounded-lg text-sm">
                        <option value="">All Agents</option>
                        @foreach($agents as $ag)
                        <option value="{{ $ag->id }}" {{ request('agent_id') == $ag->id ? 'selected' : '' }}>{{ $ag->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <button type="submit" class="corex-btn-primary px-4 py-2 rounded-lg text-sm">Filter</button>
                </div>
                @if(request()->hasAny(['search','document_type','status','branch_id','agent_id']))
                <div>
                    <a href="{{ route('filing-register.index') }}" class="text-sm text-gray-500 hover:text-gray-700 underline">Clear</a>
                </div>
                @endif
            </form>
        </div>

        {{-- Summary tiles --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="ds-status-card">
                <div class="ds-label">Total Filed</div>
                <div class="ds-value-lg">{{ $totalCount }}</div>
            </div>
            <div class="ds-status-card" style="border-left-color: var(--ds-green);">
                <div class="ds-label">Active</div>
                <div class="ds-value-lg" style="color: var(--ds-green);">{{ $activeCount }}</div>
            </div>
            <div class="ds-status-card" style="border-left-color: var(--ds-amber);">
                <div class="ds-label">Expiring (30 days)</div>
                <div class="ds-value-lg" style="color: var(--ds-amber);">{{ $expiringCount }}</div>
            </div>
            <div class="ds-status-card" style="border-left-color: var(--ds-crimson);">
                <div class="ds-label">Expired</div>
                <div class="ds-value-lg" style="color: var(--ds-crimson);">{{ $expiredCount }}</div>
            </div>
        </div>

        {{-- Add new filing (admin only) --}}
        @if($isAdmin)
        <div class="ds-status-card" x-data="{ open: false }">
            <button type="button" @click="open = !open" class="flex items-center gap-2 text-sm font-semibold" style="color: var(--ds-navy);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                New Filing
                <svg class="w-3 h-3 transition-transform" :class="open && 'rotate-180'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
            </button>

            <form method="POST" action="{{ route('filing-register.store') }}" x-show="open" x-cloak x-transition class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                @csrf
                <div>
                    <label class="ds-label block mb-1">Branch *</label>
                    <select name="branch_id" required tabindex="1" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                        @foreach($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ds-label block mb-1">Agent *</label>
                    <select name="agent_id" required tabindex="2" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                        <option value="">Select Agent</option>
                        @foreach($agents as $ag)
                        <option value="{{ $ag->id }}">{{ $ag->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ds-label block mb-1">Type *</label>
                    <select name="document_type" required tabindex="3" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                        <option value="OA">OA (Open Authority)</option>
                        <option value="EA">EA (Exclusive Authority)</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div>
                    <label class="ds-label block mb-1">File Reference *</label>
                    <input type="text" name="file_reference" required tabindex="4" placeholder="e.g. File 3" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                </div>
                <div>
                    <label class="ds-label block mb-1">Sequence Number *</label>
                    <input type="text" name="sequence_number" required tabindex="5" placeholder="e.g. 0042" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                </div>
                <div>
                    <label class="ds-label block mb-1">Property Address *</label>
                    <input type="text" name="property_address" required tabindex="6" placeholder="e.g. 21 Dee Road, Uvongo" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                </div>
                <div>
                    <label class="ds-label block mb-1">Seller Name</label>
                    <input type="text" name="seller_name" tabindex="7" placeholder="Optional" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                </div>
                <div>
                    <label class="ds-label block mb-1">Expiry Date</label>
                    <input type="date" name="expiry_date" tabindex="8" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                </div>
                <div>
                    <label class="ds-label block mb-1">Notes</label>
                    <input type="text" name="notes" tabindex="9" placeholder="Optional" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                </div>
                <div class="md:col-span-3">
                    <button type="submit" tabindex="10" class="corex-btn-primary px-6 py-2 rounded-lg text-sm font-semibold">Save Filing</button>
                </div>
            </form>
        </div>
        @endif

        {{-- Main table --}}
        <div class="ds-status-card" style="padding:0; overflow:hidden;">
            <div class="table-scroll">
                <table class="w-full text-sm ds-table table-sticky">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 text-left">Ref</th>
                            <th class="px-4 py-3 text-left">Type</th>
                            <th class="px-4 py-3 text-left">Property Address</th>
                            <th class="px-4 py-3 text-left">Seller</th>
                            <th class="px-4 py-3 text-left">Agent</th>
                            <th class="px-4 py-3 text-left">Expiry</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            @if($isAdmin)
                            <th class="px-4 py-3 text-right">Actions</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($filings as $filing)
                        <tr x-data="{ editing: false }">
                            {{-- Display row --}}
                            <template x-if="!editing">
                                <td class="px-4 py-3 font-mono text-xs whitespace-nowrap">{{ $filing->full_reference }}</td>
                            </template>
                            <template x-if="!editing">
                                <td class="px-4 py-3">
                                    @if($filing->document_type === 'OA')
                                        <span class="ds-badge ds-badge-info">OA</span>
                                    @elseif($filing->document_type === 'EA')
                                        <span class="ds-badge" style="background: var(--ds-cyan); color: #fff;">EA</span>
                                    @else
                                        <span class="ds-badge ds-badge-default">Other</span>
                                    @endif
                                </td>
                            </template>
                            <template x-if="!editing">
                                <td class="px-4 py-3">{{ $filing->property_address }}</td>
                            </template>
                            <template x-if="!editing">
                                <td class="px-4 py-3 text-gray-600">{{ $filing->seller_name ?? '—' }}</td>
                            </template>
                            <template x-if="!editing">
                                <td class="px-4 py-3">{{ $filing->agent->name ?? '—' }}</td>
                            </template>
                            <template x-if="!editing">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    {{ $filing->expiry_date ? $filing->expiry_date->format('Y-m-d') : '—' }}
                                </td>
                            </template>
                            <template x-if="!editing">
                                <td class="px-4 py-3">
                                    @if($filing->status === 'active')
                                        <span class="ds-badge ds-badge-success">Active</span>
                                    @elseif($filing->status === 'expiring')
                                        <span class="ds-badge ds-badge-warning">Expires in {{ (int) now()->diffInDays($filing->expiry_date) }}d</span>
                                    @else
                                        <span class="ds-badge ds-badge-danger">Expired {{ (int) $filing->expiry_date->diffInDays(now()) }}d ago</span>
                                    @endif
                                </td>
                            </template>
                            @if($isAdmin)
                            <template x-if="!editing">
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <button @click="editing = true" class="text-xs text-blue-600 hover:underline mr-2">Edit</button>
                                    <form method="POST" action="{{ route('filing-register.destroy', $filing->id) }}" class="inline" onsubmit="return confirm('Delete this filing entry?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs text-red-600 hover:underline">Delete</button>
                                    </form>
                                </td>
                            </template>
                            @endif

                            {{-- Inline edit row (admin only) --}}
                            @if($isAdmin)
                            <template x-if="editing">
                                <td colspan="{{ $isAdmin ? 8 : 7 }}" class="px-4 py-3">
                                    <form method="POST" action="{{ route('filing-register.update', $filing->id) }}" class="flex flex-wrap items-end gap-3">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="branch_id" value="{{ $filing->branch_id }}">
                                        <div>
                                            <label class="ds-label block mb-1 text-[10px]">Agent</label>
                                            <select name="agent_id" class="px-2 py-1 border border-gray-200 rounded text-xs">
                                                @foreach($agents as $ag)
                                                <option value="{{ $ag->id }}" {{ $filing->agent_id == $ag->id ? 'selected' : '' }}>{{ $ag->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="ds-label block mb-1 text-[10px]">Type</label>
                                            <select name="document_type" class="px-2 py-1 border border-gray-200 rounded text-xs">
                                                <option value="OA" {{ $filing->document_type === 'OA' ? 'selected' : '' }}>OA</option>
                                                <option value="EA" {{ $filing->document_type === 'EA' ? 'selected' : '' }}>EA</option>
                                                <option value="Other" {{ $filing->document_type === 'Other' ? 'selected' : '' }}>Other</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="ds-label block mb-1 text-[10px]">File Ref</label>
                                            <input type="text" name="file_reference" value="{{ $filing->file_reference }}" class="px-2 py-1 border border-gray-200 rounded text-xs w-20">
                                        </div>
                                        <div>
                                            <label class="ds-label block mb-1 text-[10px]">Seq #</label>
                                            <input type="text" name="sequence_number" value="{{ $filing->sequence_number }}" class="px-2 py-1 border border-gray-200 rounded text-xs w-16">
                                        </div>
                                        <div>
                                            <label class="ds-label block mb-1 text-[10px]">Address</label>
                                            <input type="text" name="property_address" value="{{ $filing->property_address }}" class="px-2 py-1 border border-gray-200 rounded text-xs w-40">
                                        </div>
                                        <div>
                                            <label class="ds-label block mb-1 text-[10px]">Seller</label>
                                            <input type="text" name="seller_name" value="{{ $filing->seller_name }}" class="px-2 py-1 border border-gray-200 rounded text-xs w-28">
                                        </div>
                                        <div>
                                            <label class="ds-label block mb-1 text-[10px]">Expiry</label>
                                            <input type="date" name="expiry_date" value="{{ $filing->expiry_date ? $filing->expiry_date->format('Y-m-d') : '' }}" class="px-2 py-1 border border-gray-200 rounded text-xs">
                                        </div>
                                        <div>
                                            <label class="ds-label block mb-1 text-[10px]">Notes</label>
                                            <input type="text" name="notes" value="{{ $filing->notes }}" class="px-2 py-1 border border-gray-200 rounded text-xs w-28">
                                        </div>
                                        <div class="flex gap-2">
                                            <button type="submit" class="corex-btn-primary px-3 py-1 rounded text-xs">Save</button>
                                            <button type="button" @click="editing = false" class="px-3 py-1 rounded text-xs bg-gray-200 hover:bg-gray-300">Cancel</button>
                                        </div>
                                    </form>
                                </td>
                            </template>
                            @endif
                        </tr>
                        @empty
                        <tr>
                            <td colspan="{{ $isAdmin ? 8 : 7 }}" class="px-4 py-8 text-center text-gray-400">
                                No filing entries found. {{ $isAdmin ? 'Click "+ New Filing" to add one.' : '' }}
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</x-app-layout>
