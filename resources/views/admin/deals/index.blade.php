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

    <x-list-header
        title="Deal Register"
        :form-action="route('admin.deals')"
        :paginator="$deals"
        search-placeholder="Search seller, buyer, property, deal no..."
    >
        <x-slot:filters>
            <select name="status" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All statuses</option>
                <option value="Pending" {{ request('status') === 'Pending' ? 'selected' : '' }}>Pending</option>
                <option value="Granted" {{ request('status') === 'Granted' ? 'selected' : '' }}>Granted</option>
                <option value="Registered" {{ request('status') === 'Registered' ? 'selected' : '' }}>Registered</option>
                <option value="Declined" {{ request('status') === 'Declined' ? 'selected' : '' }}>Declined</option>
            </select>

            <select name="commission" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All commission</option>
                <option value="Not Paid" {{ request('commission') === 'Not Paid' ? 'selected' : '' }}>Not Paid</option>
                <option value="Paid" {{ request('commission') === 'Paid' ? 'selected' : '' }}>Paid</option>
                <option value="Loss" {{ request('commission') === 'Loss' ? 'selected' : '' }}>Loss</option>
            </select>

            @if(auth()->user()->isEffectiveAdmin())
            <select name="branch" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All branches</option>
                @foreach($branches as $br)
                <option value="{{ $br->id }}" {{ request('branch') == $br->id ? 'selected' : '' }}>{{ $br->name }}</option>
                @endforeach
            </select>
            @endif

            <select name="agent" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All agents</option>
                @foreach($agents as $ag)
                <option value="{{ $ag->id }}" {{ request('agent') == $ag->id ? 'selected' : '' }}>{{ $ag->name }}</option>
                @endforeach
            </select>
        </x-slot:filters>

        <x-slot:actions>
            <a href="{{ route('admin.deals.create') }}" class="corex-btn-primary text-sm">+ Add Deal</a>
        </x-slot:actions>
    </x-list-header>

    <div class="space-y-4">

        @if($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ $errors->first() }}
            </div>
        @endif

        {{-- Deals Table --}}
        <div class="ds-status-card overflow-hidden" style="padding:0">
            <div class="overflow-x-auto">
                <table class="ds-table min-w-full text-sm">
                    <thead>
                        <tr>
                            <x-sort-header field="deal_no" label="Deal" />
                            <x-sort-header field="property_address" label="Property" />
                            <th class="text-left px-4 py-3">Branch / Period</th>
                            <x-sort-header field="property_value" label="Selling Price" align="right" />
                            <th class="text-right px-4 py-3">Deal Commission</th>
                            @if(($branchIdContext ?? 0) > 0)
                                <th class="text-right px-4 py-3">Branch Commission</th>
                            @endif
                            <x-sort-header field="accepted_status" label="Status" align="center" />
                            <x-sort-header field="commission_status" label="Commission" align="center" />
                            <th class="text-center px-4 py-3">Quick Update</th>
                            <th class="text-right px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($deals as $deal)
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

                            <tr class="hover:bg-gray-50">
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

            @if($deals->hasPages())
            <div class="px-4 py-3 border-t" style="border-color: #e2e8f0;">
                {{ $deals->links() }}
            </div>
            @endif
        </div>

    </div>
</x-app-layout>
