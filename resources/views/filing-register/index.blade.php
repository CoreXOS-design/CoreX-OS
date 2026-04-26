@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">
                    Filing Register &mdash; {{ $branchName }}
                </h1>
                <p class="text-sm text-white/60">Searchable index of physically filed mandates.</p>
            </div>
        </div>
    </div>

    {{-- Filter bar --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" action="{{ route('filing-register.index') }}" class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[220px]">
                <label for="search" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
                <input id="search" type="text" name="search" value="{{ request('search') }}"
                       placeholder="Address, reference, seller, seq..."
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label for="document_type" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Type</label>
                <select id="document_type" name="document_type" class="list-header-filter rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="All" {{ request('document_type') === 'All' ? 'selected' : '' }}>All</option>
                    <option value="OA" {{ request('document_type') === 'OA' ? 'selected' : '' }}>OA</option>
                    <option value="EA" {{ request('document_type') === 'EA' ? 'selected' : '' }}>EA</option>
                    <option value="Other" {{ request('document_type') === 'Other' ? 'selected' : '' }}>Other</option>
                </select>
            </div>
            <div>
                <label for="status" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
                <select id="status" name="status" class="list-header-filter rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="All" {{ request('status') === 'All' ? 'selected' : '' }}>All</option>
                    <option value="Active" {{ request('status') === 'Active' ? 'selected' : '' }}>Active</option>
                    <option value="Expiring" {{ request('status') === 'Expiring' ? 'selected' : '' }}>Expiring Soon</option>
                    <option value="Expired" {{ request('status') === 'Expired' ? 'selected' : '' }}>Expired</option>
                    <option value="Archived" {{ request('status') === 'Archived' ? 'selected' : '' }}>Archived</option>
                </select>
            </div>
            @if($isAdmin)
            <div>
                <label for="branch_id" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Branch</label>
                <select id="branch_id" name="branch_id" class="list-header-filter rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">All Branches</option>
                    @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" {{ request('branch_id') == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div>
                <label for="agent_id" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Agent</label>
                <select id="agent_id" name="agent_id" class="list-header-filter rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">All Agents</option>
                    @foreach($agents as $ag)
                    <option value="{{ $ag->id }}" {{ request('agent_id') == $ag->id ? 'selected' : '' }}>{{ $ag->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-2">
                <button type="submit" class="corex-btn-primary">Filter</button>
                @if(request()->hasAny(['search','document_type','status','branch_id','agent_id']))
                    <a href="{{ route('filing-register.index') }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Clear</a>
                @endif
            </div>
        </form>
    </div>

    {{-- Summary tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="ds-status-card">
            <div class="ds-label">Total Filed</div>
            <div class="ds-value-lg">{{ number_format($totalCount) }}</div>
        </div>
        <div class="ds-status-card" style="border-left-color: var(--ds-green);">
            <div class="ds-label">Active</div>
            <div class="ds-value-lg" style="color: var(--ds-green);">{{ number_format($activeCount) }}</div>
        </div>
        <div class="ds-status-card" style="border-left-color: var(--ds-amber);">
            <div class="ds-label">Expiring (30 days)</div>
            <div class="ds-value-lg" style="color: var(--ds-amber);">{{ number_format($expiringCount) }}</div>
        </div>
        <div class="ds-status-card" style="border-left-color: var(--ds-amber);">
            <div class="ds-label">Expired</div>
            <div class="ds-value-lg" style="color: var(--ds-amber);">{{ number_format($expiredCount) }}</div>
        </div>
    </div>

    {{-- Add new filing --}}
    @permission('filing.create')
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ open: false }">
        <button type="button" @click="open = !open" class="flex items-center gap-2 text-sm font-semibold" style="color: var(--brand-icon);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
            New Filing
            <svg class="w-3 h-3 transition-transform" :class="open && 'rotate-180'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
        </button>

        <form method="POST" action="{{ route('filing-register.store') }}" x-show="open" x-cloak x-transition class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
            @csrf
            <div>
                <label for="new_branch_id" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Branch <span class="text-red-500">*</span></label>
                <select id="new_branch_id" name="branch_id" required tabindex="1" class="w-full rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    @foreach($branches as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="new_agent_id" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Agent <span class="text-red-500">*</span></label>
                <select id="new_agent_id" name="agent_id" required tabindex="2" class="w-full rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">Select Agent</option>
                    @foreach($agents as $ag)
                    <option value="{{ $ag->id }}">{{ $ag->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="new_document_type" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Type <span class="text-red-500">*</span></label>
                <select id="new_document_type" name="document_type" required tabindex="3" class="w-full rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="OA">OA (Open Authority)</option>
                    <option value="EA">EA (Exclusive Authority)</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div>
                <label for="new_file_reference" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">File Reference <span class="text-red-500">*</span></label>
                <input id="new_file_reference" type="text" name="file_reference" required tabindex="4" placeholder="e.g. File 3"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label for="new_sequence_number" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Sequence Number <span class="text-red-500">*</span></label>
                <input id="new_sequence_number" type="text" name="sequence_number" required tabindex="5" placeholder="e.g. 0042"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label for="new_property_address" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Property Address <span class="text-red-500">*</span></label>
                <input id="new_property_address" type="text" name="property_address" required tabindex="6" placeholder="e.g. 21 Dee Road, Uvongo"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label for="new_seller_name" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Seller Name</label>
                <input id="new_seller_name" type="text" name="seller_name" tabindex="7" placeholder="Optional"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label for="new_expiry_date" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Expiry Date</label>
                <input id="new_expiry_date" type="date" name="expiry_date" tabindex="8"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label for="new_notes" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Notes</label>
                <input id="new_notes" type="text" name="notes" tabindex="9" placeholder="Optional"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div class="md:col-span-3">
                <button type="submit" tabindex="10" class="corex-btn-primary">Save Filing</button>
            </div>
        </form>
    </div>
    @endpermission

    {{-- Main table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table table-sticky">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Ref</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Property Address</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Seller</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Expiry</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($filings as $filing)
                    <tr x-data="{ editing: false }" style="border-top: 1px solid var(--border);">
                        {{-- Display row --}}
                        <template x-if="!editing">
                            <td class="px-4 py-3 font-mono text-xs whitespace-nowrap">{{ $filing->full_reference }}</td>
                        </template>
                        <template x-if="!editing">
                            <td class="px-4 py-3">
                                @if($filing->document_type === 'OA')
                                    <span class="ds-badge ds-badge-info">OA</span>
                                @elseif($filing->document_type === 'EA')
                                    <span class="ds-badge ds-badge-info">EA</span>
                                @else
                                    <span class="ds-badge ds-badge-default">Other</span>
                                @endif
                            </td>
                        </template>
                        <template x-if="!editing">
                            <td class="px-4 py-3">{{ $filing->property_address }}</td>
                        </template>
                        <template x-if="!editing">
                            <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $filing->seller_name ?? '—' }}</td>
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
                                @if($showArchived)
                                    <span class="ds-badge ds-badge-warning">Archived</span>
                                @elseif($filing->status === 'active')
                                    <span class="ds-badge ds-badge-success">Active</span>
                                @elseif($filing->status === 'expiring')
                                    <span class="ds-badge ds-badge-warning">Expiring</span>
                                @else
                                    <span class="ds-badge ds-badge-warning">Expired</span>
                                @endif
                            </td>
                        </template>
                        <template x-if="!editing">
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if($showArchived)
                                    <form method="POST" action="{{ route('filing-register.restore', $filing->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs font-semibold" style="color: var(--ds-green);">Restore</button>
                                    </form>
                                @else
                                    @permission('filing.edit')
                                    <button @click="editing = true" class="text-xs font-semibold mr-3" style="color: var(--brand-icon);">Edit</button>
                                    @endpermission
                                    @permission('filing.archive')
                                    <form method="POST" action="{{ route('filing-register.destroy', $filing->id) }}" class="inline" onsubmit="return confirm('Delete this filing entry?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson);">Delete</button>
                                    </form>
                                    @endpermission
                                @endif
                            </td>
                        </template>

                        {{-- Inline edit row --}}
                        <template x-if="editing">
                            <td colspan="8" class="px-4 py-3">
                                <form method="POST" action="{{ route('filing-register.update', $filing->id) }}" class="flex flex-wrap items-end gap-3">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="branch_id" value="{{ $filing->branch_id }}">
                                    <div>
                                        <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Agent</label>
                                        <select name="agent_id" class="rounded-md px-2 py-1 text-xs"
                                                style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                            @foreach($agents as $ag)
                                            <option value="{{ $ag->id }}" {{ $filing->agent_id == $ag->id ? 'selected' : '' }}>{{ $ag->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Type</label>
                                        <select name="document_type" class="rounded-md px-2 py-1 text-xs"
                                                style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                            <option value="OA" {{ $filing->document_type === 'OA' ? 'selected' : '' }}>OA</option>
                                            <option value="EA" {{ $filing->document_type === 'EA' ? 'selected' : '' }}>EA</option>
                                            <option value="Other" {{ $filing->document_type === 'Other' ? 'selected' : '' }}>Other</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">File Ref</label>
                                        <input type="text" name="file_reference" value="{{ $filing->file_reference }}"
                                               class="rounded-md px-2 py-1 text-xs w-20"
                                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Seq #</label>
                                        <input type="text" name="sequence_number" value="{{ $filing->sequence_number }}"
                                               class="rounded-md px-2 py-1 text-xs w-16"
                                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Address</label>
                                        <input type="text" name="property_address" value="{{ $filing->property_address }}"
                                               class="rounded-md px-2 py-1 text-xs w-40"
                                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Seller</label>
                                        <input type="text" name="seller_name" value="{{ $filing->seller_name }}"
                                               class="rounded-md px-2 py-1 text-xs w-28"
                                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Expiry</label>
                                        <input type="date" name="expiry_date" value="{{ $filing->expiry_date ? $filing->expiry_date->format('Y-m-d') : '' }}"
                                               class="rounded-md px-2 py-1 text-xs"
                                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Notes</label>
                                        <input type="text" name="notes" value="{{ $filing->notes }}"
                                               class="rounded-md px-2 py-1 text-xs w-28"
                                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    </div>
                                    <div class="flex gap-2">
                                        <button type="submit" class="corex-btn-primary">Save</button>
                                        <button type="button" @click="editing = false" class="corex-btn-outline">Cancel</button>
                                    </div>
                                </form>
                            </td>
                        </template>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                            No filing entries found. Click &ldquo;New Filing&rdquo; to add one.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
