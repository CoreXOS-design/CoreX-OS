<x-app-layout>
    <div x-data="depositCalculator()">
        {{-- Sticky header --}}
        <div class="sticky top-0 z-30 -mx-4 -mt-4 mb-0 lg:-mx-6 lg:-mt-6" style="background: var(--surface); border-bottom: 1px solid var(--border);">
            <div class="flex items-center justify-between px-4 sm:px-6 lg:px-8 py-3">
                <div class="flex items-center gap-3 min-w-0">
                    <h1 class="text-lg font-semibold truncate" style="color: var(--text-primary);">Deposit Interest Calculator</h1>
                    <span class="text-sm flex-shrink-0 hidden sm:inline" style="color: var(--text-muted);">Proportional trust account interest</span>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    @if(\Illuminate\Support\Facades\Route::has('deposit-interest-calculator.history'))
                    <a href="{{ route('deposit-interest-calculator.history') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors" style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        History
                    </a>
                    @endif
                    <button type="submit" form="calcForm" class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-lg bg-teal-600 hover:bg-teal-500 text-white text-sm font-medium transition-colors">
                        Calculate
                    </button>
                </div>
            </div>
        </div>

        <div class="p-4 lg:p-6 max-w-6xl mx-auto">
            {{-- Flash --}}
            @if(session('status'))
                <div class="mb-4 p-3 rounded-lg text-sm" style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #34d399;">
                    {{ session('status') }}
                </div>
            @endif
            @if($errors->any())
                <div class="mb-4 p-3 rounded-lg text-sm" style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #f87171;">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            {{-- Calculator Form --}}
            <form id="calcForm" method="POST" action="{{ route('deposit-interest-calculator.calculate') }}" class="rounded-xl p-5 mb-6" style="border: 1px solid var(--border); background: var(--surface);">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                    <div>
                        <label class="block text-xs font-medium uppercase tracking-wider mb-1" style="color: var(--text-muted);">Property Name</label>
                        <input type="text" name="property_name" required
                               value="{{ old('property_name', $input['property_name'] ?? '') }}"
                               placeholder="e.g. 12 Marine Drive, Margate"
                               class="w-full rounded-md text-sm px-3 py-2 focus:outline-none focus:ring-1 focus:ring-teal-500"
                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium uppercase tracking-wider mb-1" style="color: var(--text-muted);">Deposit Amount (R)</label>
                        <input type="number" step="0.01" min="1" name="deposit_amount" required
                               value="{{ old('deposit_amount', $input['deposit_amount'] ?? '') }}"
                               placeholder="7700.00"
                               class="w-full rounded-md text-sm px-3 py-2 focus:outline-none focus:ring-1 focus:ring-teal-500"
                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium uppercase tracking-wider mb-1" style="color: var(--text-muted);">Date Invested</label>
                        <input type="date" name="invest_date" required
                               value="{{ old('invest_date', $input['invest_date'] ?? '') }}"
                               class="w-full rounded-md text-sm px-3 py-2 focus:outline-none focus:ring-1 focus:ring-teal-500"
                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium uppercase tracking-wider mb-1" style="color: var(--text-muted);">Date Refunded</label>
                        <input type="date" name="refund_date" required
                               value="{{ old('refund_date', $input['refund_date'] ?? now()->format('Y-m-d')) }}"
                               class="w-full rounded-md text-sm px-3 py-2 focus:outline-none focus:ring-1 focus:ring-teal-500"
                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                </div>

                {{-- Topups --}}
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Deposit Topups</label>
                        <button type="button" @click="addTopup()"
                                class="text-xs px-3 py-1.5 rounded-lg bg-teal-600 hover:bg-teal-500 text-white font-medium transition-colors">
                            + Add Topup
                        </button>
                    </div>

                    {{-- Column labels for topups --}}
                    <div x-show="topups.length > 0" class="flex items-center gap-3 mb-1">
                        <div class="flex-1">
                            <span class="text-xs" style="color: var(--text-muted);">Date</span>
                        </div>
                        <div class="flex-1">
                            <span class="text-xs" style="color: var(--text-muted);">Amount (R)</span>
                        </div>
                        <div class="w-8"></div>
                    </div>

                    <template x-for="(topup, index) in topups" :key="index">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="flex-1">
                                <input type="date" :name="'topups[' + index + '][date]'" x-model="topup.date" required
                                       class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-teal-500"
                                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                            </div>
                            <div class="flex-1">
                                <input type="number" step="0.01" min="0.01" :name="'topups[' + index + '][amount]'" x-model="topup.amount" required
                                       placeholder="0.00"
                                       class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-teal-500"
                                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                            </div>
                            <button type="button" @click="removeTopup(index)"
                                    class="p-1.5 rounded hover:bg-red-500/20 transition-colors" style="color: var(--text-muted);">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </template>

                    <p x-show="topups.length === 0" class="text-xs italic" style="color: var(--text-muted);">No topups added</p>
                </div>

                @if($minDate && $maxDate)
                    <p class="text-xs" style="color: var(--text-muted);">
                        Trust interest data available from {{ \Carbon\Carbon::parse($minDate)->format('d M Y') }}
                        to {{ \Carbon\Carbon::parse($maxDate)->format('d M Y') }}
                    </p>
                @endif
            </form>

            {{-- Results --}}
            @if($result)
                {{-- Summary Card --}}
                <div class="rounded-xl p-5 mb-6" style="border: 1px solid var(--border); background: var(--surface);">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h2 class="text-lg font-bold" style="color: var(--text-primary);">{{ $input['property_name'] }}</h2>
                            <p class="text-sm" style="color: var(--text-muted);">
                                Invested: {{ \Carbon\Carbon::parse($input['invest_date'])->format('d M Y') }}
                                &mdash;
                                Refunded: {{ \Carbon\Carbon::parse($input['refund_date'])->format('d M Y') }}
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="rounded-lg p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                            <div class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Total Deposit</div>
                            <div class="mt-1 text-xl font-bold font-mono" style="color: var(--text-primary);">R {{ number_format($result['total_deposited'], 2) }}</div>
                            @if($result['topups_total'] > 0)
                                <div class="text-xs mt-1" style="color: var(--text-muted);">
                                    R {{ number_format($result['deposit_amount'], 2) }} + R {{ number_format($result['topups_total'], 2) }} topups
                                </div>
                            @endif
                        </div>
                        <div class="rounded-lg p-4" style="background: rgba(20,184,166,0.08); border: 1px solid rgba(20,184,166,0.25);">
                            <div class="text-xs uppercase tracking-wider" style="color: rgba(45,212,191,0.7);">Total Interest</div>
                            <div class="mt-1 text-xl font-bold font-mono" style="color: #2dd4bf;">R {{ number_format($result['total_interest'], 2) }}</div>
                        </div>
                        <div class="rounded-lg p-4" style="background: rgba(6,182,212,0.08); border: 1px solid rgba(6,182,212,0.25);">
                            <div class="text-xs uppercase tracking-wider" style="color: rgba(34,211,238,0.7);">Grand Total</div>
                            <div class="mt-1 text-xl font-bold font-mono" style="color: #22d3ee;">R {{ number_format($result['grand_total'], 2) }}</div>
                        </div>
                    </div>
                </div>

                {{-- Breakdown Table --}}
                <div class="rounded-xl overflow-hidden mb-6" style="border: 1px solid var(--border); background: var(--surface);">
                    <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
                        <h3 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--text-primary);">Interest Breakdown</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
                                    <th class="text-left px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Date</th>
                                    <th class="text-left px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Description</th>
                                    <th class="text-right px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Total Invested Funds</th>
                                    <th class="text-right px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Running Balance</th>
                                    <th class="text-right px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Share %</th>
                                    <th class="text-right px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Interest Earned</th>
                                    <th class="text-right px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Share of Interest</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($result['breakdown'] as $row)
                                    <tr class="transition-colors" style="border-bottom: 1px solid var(--border); {{ $row['type'] === 'topup' ? 'background: rgba(245,158,11,0.04);' : ($row['type'] === 'deposit' ? 'background: rgba(6,182,212,0.04);' : '') }}">
                                        <td class="px-4 py-2" style="color: var(--text-primary);">{{ $row['date']->format('d M Y') }}</td>
                                        <td class="px-4 py-2 font-medium">
                                            @if($row['type'] === 'deposit')
                                                <span style="color: #22d3ee;">{{ $row['description'] }}</span>
                                            @elseif($row['type'] === 'topup')
                                                <span style="color: #fbbf24;">{{ $row['description'] }}</span>
                                            @else
                                                <span style="color: var(--text-primary);">{{ $row['description'] }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2 text-right font-mono" style="color: var(--text-primary);">
                                            {{ $row['total_invested_funds'] !== null ? 'R ' . number_format($row['total_invested_funds'], 2) : '—' }}
                                        </td>
                                        <td class="px-4 py-2 text-right font-mono font-medium" style="color: var(--text-primary);">R {{ number_format($row['running_balance'], 2) }}</td>
                                        <td class="px-4 py-2 text-right font-mono" style="color: var(--text-primary);">
                                            {{ $row['share_percentage'] !== null ? number_format($row['share_percentage'] * 100, 4) . '%' : '—' }}
                                        </td>
                                        <td class="px-4 py-2 text-right font-mono" style="color: var(--text-primary);">
                                            {{ $row['interest_earned'] !== null ? 'R ' . number_format($row['interest_earned'], 2) : '—' }}
                                        </td>
                                        <td class="px-4 py-2 text-right font-mono font-medium" style="color: {{ $row['type'] === 'interest' ? '#2dd4bf' : 'var(--text-primary)' }};">
                                            {{ $row['interest_share'] !== null ? 'R ' . number_format($row['interest_share'], 2) : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Action buttons --}}
                <div class="flex flex-wrap items-center gap-3">
                    {{-- Download Tenant Statement (primary — most used) --}}
                    <form method="POST" action="{{ route('deposit-interest-calculator.download-tenant-pdf') }}" class="inline">
                        @csrf
                        @include('deposit-interest-calculator._hidden-inputs')
                        <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-teal-600 hover:bg-teal-500 text-white text-sm font-medium transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                            </svg>
                            Download Tenant Statement
                        </button>
                    </form>

                    {{-- Download Full Statement (internal/agency) --}}
                    <form method="POST" action="{{ route('deposit-interest-calculator.download-pdf') }}" class="inline">
                        @csrf
                        @include('deposit-interest-calculator._hidden-inputs')
                        <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors" style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                            </svg>
                            Download Full Statement
                        </button>
                    </form>

                    {{-- Save to History --}}
                    <form method="POST" action="{{ route('deposit-interest-calculator.save') }}" class="inline">
                        @csrf
                        @include('deposit-interest-calculator._hidden-inputs')
                        <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors" style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0Z" />
                            </svg>
                            Save to History
                        </button>
                    </form>
                </div>
            @endif
        </div>
    </div>

    <script>
        function depositCalculator() {
            return {
                topups: @json($topupsJson),
                addTopup() {
                    this.topups.push({ date: '', amount: '' });
                },
                removeTopup(index) {
                    this.topups.splice(index, 1);
                }
            };
        }
    </script>
</x-app-layout>
