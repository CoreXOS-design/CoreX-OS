<x-app-layout>

    @if(isset($paidNotSettledDeals) && $paidNotSettledDeals->count() > 0 && auth()->user()?->isEffectiveAdmin())
        <div x-data="{ openPaidExceptions: false }" class="mb-4">
            <div class="rounded-xl border border-red-500 bg-red-50 px-4 py-3 text-sm text-red-900 flex items-center justify-between gap-3">
                <div class="font-semibold">
                    {{ $paidNotSettledDeals->count() }} deal{{ $paidNotSettledDeals->count() === 1 ? '' : 's' }} marked Paid but Settlement not marked Paid
                </div>
                <button type="button"
                        @click="openPaidExceptions = true"
                        class="rounded-lg bg-red-200/70 px-3 py-1.5 text-xs font-semibold hover:bg-red-200">
                    View exceptions
                </button>
            </div>

            <div x-show="openPaidExceptions" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/50" @click="openPaidExceptions = false"></div>

                <div class="relative w-full max-w-3xl rounded-2xl bg-white p-5 shadow-xl">
                    <div class="flex items-center justify-between mb-3">
                        <div class="text-lg font-extrabold text-gray-900">Paid but not settled</div>
                        <button type="button" @click="openPaidExceptions = false" class="text-gray-500 hover:text-gray-800">&times;</button>
                    </div>

                    <div class="text-sm text-gray-600 mb-4">
                        These deals are marked <b>Paid</b> on the Deal Register, but settlement has not been marked paid yet.
                        Open each settlement and complete the agent payout workflow.
                    </div>

                    <div class="max-h-[60vh] overflow-auto rounded-xl border border-gray-200">
                        <table class="min-w-full text-sm ds-table">
                            <thead>
                                <tr>
                                    <th class="px-3 py-2 text-left">Deal No</th>
                                    <th class="px-3 py-2 text-left">Property</th>
                                    <th class="px-3 py-2 text-left">Period</th>
                                    <th class="px-3 py-2 text-left">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($paidNotSettledDeals as $d)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-3 py-2 font-semibold" style="color:#0b2a4a">{{ $d->deal_no ?? ('#'.$d->id) }}</td>
                                        <td class="px-3 py-2 text-gray-700">{{ $d->property_address ?? '—' }}</td>
                                        <td class="px-3 py-2 text-gray-700">{{ $d->period ?? '—' }}</td>
                                        <td class="px-3 py-2">
                                            <a href="{{ route('admin.deals.settle', $d) }}" class="corex-btn-primary text-xs px-3 py-1.5">
                                                Open settlement
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 flex justify-end">
                        <button type="button" @click="openPaidExceptions = false" class="corex-btn-outline text-sm">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <x-slot name="header">
        <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                <div>
                    <h2 class="text-xl font-bold text-white leading-tight">Deal Register</h2>
                    <div class="text-sm text-white/60">Operational view for tracking deal status, settlement, and audit log.</div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white ring-1 ring-white/20">
                        {{ $deals->count() }} deals
                    </span>
                    <a href="{{ route('admin.deals.create') }}"
                       class="inline-flex items-center gap-2 rounded-xl bg-white/20 px-4 py-2 text-sm font-semibold text-white hover:bg-white/30">
                        <span class="text-base leading-none">+</span>
                        <span>Add Deal</span>
                    </a>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">

        @if($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ $errors->first() }}
            </div>
        @endif

        {{-- Deals Table --}}
        <div>
            <h2 class="ds-section-header">Deals Overview</h2>
            <div class="ds-section-sub mb-4">All deals sorted by deal number (newest first).</div>

            <div class="ds-status-card overflow-hidden" style="padding:0">
                <div class="table-scroll">
                    <table class="ds-table min-w-full text-sm table-sticky">
                        <thead>
                            <tr>
                                <th class="text-left px-4 py-3">Deal</th>
                                <th class="text-left px-4 py-3">Property</th>
                                <th class="text-left px-4 py-3">Branch / Period</th>
                                <th class="text-right px-4 py-3">Selling Price</th>
                                <th class="text-right px-4 py-3">Deal Commission</th>
                                @if(($branchIdContext ?? 0) > 0)
                                    <th class="text-right px-4 py-3">Branch Commission</th>
                                @endif
                                <th class="text-center px-4 py-3">Status</th>
                                <th class="text-center px-4 py-3">Commission</th>
                                <th class="text-center px-4 py-3">Quick Update</th>
                                <th class="text-right px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($deals->sortByDesc('deal_no') as $deal)
                                @php
                                    $b = $branches->firstWhere('id', $deal->branch_id);
                                    $acceptedMap = ['P'=>'Pending','D'=>'Declined','G'=>'Granted','R'=>'Registered'];
                                    $asVal = (string)($deal->accepted_status ?? '');
                                    $csVal = (string)($deal->commission_status ?? '');
                                    $acceptedLabel = $acceptedMap[$asVal] ?? ($asVal ?: '—');

                                    $statusBadge = 'ds-badge-default';
                                    if ($asVal === 'G') $statusBadge = 'ds-badge-success';
                                    elseif ($asVal === 'R') $statusBadge = 'ds-badge-info';
                                    elseif ($asVal === 'D') $statusBadge = 'ds-badge-danger';
                                    elseif ($asVal === 'P') $statusBadge = 'ds-badge-warning';

                                    $commBadge = 'ds-badge-default';
                                    if ($csVal === 'Paid') $commBadge = 'ds-badge-paid';
                                    elseif ($csVal === 'Not Paid') $commBadge = 'ds-badge-notpaid';
                                    elseif ($csVal === 'Loss') $commBadge = 'ds-badge-loss';
                                @endphp

                                <tr>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.deals.edit', $deal) }}" class="ds-agent-link font-bold">{{ $deal->deal_no }}</a>
                                        <div class="text-xs text-gray-500 mt-0.5">{{ $deal->deal_date ? \Carbon\Carbon::parse($deal->deal_date)->format('d M Y') : '—' }}</div>
                                    </td>

                                    <td class="px-4 py-3">
                                        <div class="text-sm text-gray-900 font-medium">{{ \Illuminate\Support\Str::limit($deal->property_address, 40) ?: '—' }}</div>
                                        <div class="text-xs text-gray-500 mt-0.5">{{ $deal->seller_name ?: '—' }} &rarr; {{ $deal->buyer_name ?: '—' }}</div>
                                    </td>

                                    <td class="px-4 py-3">
                                        <div class="font-medium" style="color:#0b2a4a">{{ $b?->name ?? '—' }}</div>
                                        <div class="text-xs text-gray-500 mt-0.5">{{ $deal->period ?: '—' }}</div>
                                    </td>

                                    <td class="px-4 py-3 text-right">
                                        <div class="font-semibold ds-value">R {{ number_format((float)$deal->property_value, 0) }}</div>
                                    </td>

                                    <td class="px-4 py-3 text-right">
                                        <div class="font-bold ds-value">R {{ number_format((float)$deal->totalOurCommission(), 0) }}</div>
                                    </td>

                                    @if(($branchIdContext ?? 0) > 0)
                                        <td class="px-4 py-3 text-right">
                                            <div class="font-bold ds-value">R {{ number_format((float)$deal->branchCommission($branchIdContext), 0) }}</div>
                                        </td>
                                    @endif

                                    <td class="px-4 py-3 text-center">
                                        <span class="ds-badge {{ $statusBadge }}">{{ $acceptedLabel }}</span>
                                    </td>

                                    <td class="px-4 py-3 text-center">
                                        <span class="ds-badge {{ $commBadge }}">{{ $csVal ?: '—' }}</span>
                                    </td>

                                    <td class="px-4 py-3">
                                        <form method="POST" action="{{ route('admin.deals.quickUpdate', $deal) }}" class="flex items-center gap-1.5">
                                            @csrf
                                            <select name="accepted_status" class="h-7 rounded-lg border-gray-200 text-xs px-1.5 py-0">
                                                <option value="">—</option>
                                                <option value="P" {{ $asVal === 'P' ? 'selected' : '' }}>Pend</option>
                                                <option value="G" {{ $asVal === 'G' ? 'selected' : '' }}>Grant</option>
                                                <option value="R" {{ $asVal === 'R' ? 'selected' : '' }}>Reg</option>
                                                <option value="D" {{ $asVal === 'D' ? 'selected' : '' }}>Decl</option>
                                            </select>
                                            <select name="commission_status" class="h-7 rounded-lg border-gray-200 text-xs px-1.5 py-0">
                                                <option value="">—</option>
                                                <option value="Not Paid" {{ $csVal === 'Not Paid' ? 'selected' : '' }}>Not Paid</option>
                                                <option value="Paid" {{ $csVal === 'Paid' ? 'selected' : '' }}>Paid</option>
                                                <option value="Loss" {{ $csVal === 'Loss' ? 'selected' : '' }}>Loss</option>
                                            </select>
                                            <button type="submit" class="corex-btn-primary text-xs px-2 py-1" style="font-size:0.6875rem">Save</button>
                                        </form>
                                    </td>

                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <a href="{{ route('admin.deals.log', $deal) }}" class="corex-btn-outline text-xs px-2 py-1">Log</a>
                                            <a href="{{ route('admin.deals.edit', $deal) }}" class="corex-btn-outline text-xs px-2 py-1">Edit</a>
                                            @if(auth()->user()->isEffectiveAdmin())
                                                <a href="{{ route('admin.deals.settle', $deal) }}" class="corex-btn-primary text-xs px-2 py-1">Pay</a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach

                            @if($deals->isEmpty())
                                <tr>
                                    <td colspan="{{ ($branchIdContext ?? 0) > 0 ? 10 : 9 }}" class="px-5 py-10 text-center text-sm text-gray-500">No deals found.</td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</x-app-layout>
