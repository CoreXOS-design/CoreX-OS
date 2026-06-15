{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
    <div x-data="depositCalculator()" class="w-full space-y-5">
        {{-- Page header (Pattern A — branded) --}}
        <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-white leading-tight">Deposit Interest Calculator</h1>
                    <p class="text-sm text-white/60">Proportional trust account interest</p>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    @if(\Illuminate\Support\Facades\Route::has('deposit-interest-calculator.history'))
                        <a href="{{ route('deposit-interest-calculator.history') }}" class="corex-btn-outline">History</a>
                    @endif
                    <button type="submit" form="calcForm" class="corex-btn-primary">Calculate</button>
                </div>
            </div>
        </div>

        <div class="space-y-5">
            {{-- Flash --}}
            @if(session('status'))
                <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
                     style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                            border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                            color: var(--text-primary);">
                    <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <div class="flex-1">{{ session('status') }}</div>
                </div>
            @endif
            @if($errors->any())
                <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
                     style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                            border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                            color: var(--text-primary);">
                    <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                    </svg>
                    <div class="flex-1">
                        @foreach($errors->all() as $error)
                            <div>{{ $error }}</div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Calculator Form --}}
            <form id="calcForm" method="POST" action="{{ route('deposit-interest-calculator.calculate') }}"
                  class="rounded-md p-5" style="border: 1px solid var(--border); background: var(--surface);">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                    <div>
                        <label for="property_name" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Property Name <span class="text-red-500">*</span></label>
                        <input id="property_name" type="text" name="property_name" required
                               value="{{ old('property_name', $input['property_name'] ?? '') }}"
                               placeholder="e.g. 12 Marine Drive, Margate"
                               class="w-full rounded-md text-sm px-3 py-2"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div>
                        <label for="deposit_amount" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Deposit Amount (R) <span class="text-red-500">*</span></label>
                        <input id="deposit_amount" type="number" step="0.01" min="1" name="deposit_amount" required
                               value="{{ old('deposit_amount', $input['deposit_amount'] ?? '') }}"
                               placeholder="7700.00"
                               class="w-full rounded-md text-sm px-3 py-2"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div>
                        <label for="invest_date" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Date Invested <span class="text-red-500">*</span></label>
                        <input id="invest_date" type="date" name="invest_date" required
                               value="{{ old('invest_date', $input['invest_date'] ?? '') }}"
                               class="w-full rounded-md text-sm px-3 py-2"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div>
                        <label for="refund_date" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Date Refunded <span class="text-red-500">*</span></label>
                        <input id="refund_date" type="date" name="refund_date" required
                               value="{{ old('refund_date', $input['refund_date'] ?? now()->format('Y-m-d')) }}"
                               class="w-full rounded-md text-sm px-3 py-2"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                </div>

                {{-- Topups --}}
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-xs font-medium" style="color: var(--text-secondary);">Deposit Topups</label>
                        <button type="button" @click="addTopup()" class="corex-btn-outline">+ Add Topup</button>
                    </div>

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
                                       class="w-full rounded-md text-sm px-3 py-2"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                            </div>
                            <div class="flex-1">
                                <input type="number" step="0.01" min="0.01" :name="'topups[' + index + '][amount]'" x-model="topup.amount" required
                                       placeholder="0.00"
                                       class="w-full rounded-md text-sm px-3 py-2"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                            </div>
                            <button type="button" @click="removeTopup(index)"
                                    class="p-1.5 rounded-md transition-colors" style="color: var(--text-muted);" title="Remove">
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
                <div class="rounded-md p-5" style="border: 1px solid var(--border); background: var(--surface);">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h2 class="text-lg font-semibold" style="color: var(--text-primary);">{{ $input['property_name'] }}</h2>
                            <p class="text-sm" style="color: var(--text-muted);">
                                Invested: {{ \Carbon\Carbon::parse($input['invest_date'])->format('d M Y') }}
                                &mdash;
                                Refunded: {{ \Carbon\Carbon::parse($input['refund_date'])->format('d M Y') }}
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                            <div class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Total Deposit</div>
                            <div class="mt-1 font-semibold font-mono text-[1.625rem]" style="color: var(--text-primary);">R {{ number_format($result['total_deposited'], 2) }}</div>
                            @if($result['topups_total'] > 0)
                                <div class="text-xs mt-1" style="color: var(--text-muted);">
                                    R {{ number_format($result['deposit_amount'], 2) }} + R {{ number_format($result['topups_total'], 2) }} topups
                                </div>
                            @endif
                        </div>
                        <div class="rounded-md p-4"
                             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);">
                            <div class="text-xs uppercase tracking-wider" style="color: var(--ds-green);">Total Interest</div>
                            <div class="mt-1 font-semibold font-mono text-[1.625rem]" style="color: var(--ds-green);">R {{ number_format($result['total_interest'], 2) }}</div>
                        </div>
                        <div class="rounded-md p-4"
                             style="background: color-mix(in srgb, var(--brand-icon) 10%, transparent);
                                    border: 1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent);">
                            <div class="text-xs uppercase tracking-wider" style="color: var(--brand-icon);">Grand Total</div>
                            <div class="mt-1 font-semibold font-mono text-[1.625rem]" style="color: var(--brand-icon);">R {{ number_format($result['grand_total'], 2) }}</div>
                        </div>
                    </div>
                </div>

                {{-- Breakdown Table --}}
                <div class="rounded-md overflow-hidden" style="border: 1px solid var(--border); background: var(--surface);">
                    <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
                        <h3 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--text-primary);">Interest Breakdown</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm ds-table">
                            <thead>
                                <tr style="background: var(--surface-2);">
                                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Date</th>
                                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Description</th>
                                    <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Total Invested Funds</th>
                                    <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Running Balance</th>
                                    <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Share %</th>
                                    <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Interest Earned</th>
                                    <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Share of Interest</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($result['breakdown'] as $row)
                                    <tr style="border-top: 1px solid var(--border);">
                                        <td class="px-4 py-3" style="color: var(--text-primary);">{{ $row['date']->format('d M Y') }}</td>
                                        <td class="px-4 py-3 font-medium">
                                            @if($row['type'] === 'deposit')
                                                <span class="ds-badge ds-badge-info">{{ $row['description'] }}</span>
                                            @elseif($row['type'] === 'topup')
                                                <span class="ds-badge ds-badge-warning">{{ $row['description'] }}</span>
                                            @elseif($row['type'] === 'interest')
                                                <span class="ds-badge ds-badge-success">{{ $row['description'] }}</span>
                                            @else
                                                <span style="color: var(--text-primary);">{{ $row['description'] }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono" style="color: var(--text-primary);">
                                            {{ $row['total_invested_funds'] !== null ? 'R ' . number_format($row['total_invested_funds'], 2) : '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono font-medium" style="color: var(--text-primary);">R {{ number_format($row['running_balance'], 2) }}</td>
                                        <td class="px-4 py-3 text-right font-mono" style="color: var(--text-primary);">
                                            {{ $row['share_percentage'] !== null ? number_format($row['share_percentage'] * 100, 4) . '%' : '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono" style="color: var(--text-primary);">
                                            {{ $row['interest_earned'] !== null ? 'R ' . number_format($row['interest_earned'], 2) : '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono font-medium" style="color: {{ $row['type'] === 'interest' ? 'var(--ds-green)' : 'var(--text-primary)' }};">
                                            {{ $row['interest_share'] !== null ? 'R ' . number_format($row['interest_share'], 2) : '—' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No breakdown rows.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Action buttons --}}
                <div class="flex flex-wrap items-center gap-3">
                    <form method="POST" action="{{ route('deposit-interest-calculator.download-tenant-pdf') }}" class="inline">
                        @csrf
                        @include('deposit-interest-calculator._hidden-inputs')
                        <button type="submit" class="corex-btn-primary inline-flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                            </svg>
                            Download Tenant Statement
                        </button>
                    </form>

                    <form method="POST" action="{{ route('deposit-interest-calculator.download-pdf') }}" class="inline">
                        @csrf
                        @include('deposit-interest-calculator._hidden-inputs')
                        <button type="submit" class="corex-btn-outline inline-flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                            </svg>
                            Download Full Statement
                        </button>
                    </form>

                    <form method="POST" action="{{ route('deposit-interest-calculator.save') }}" class="inline">
                        @csrf
                        @include('deposit-interest-calculator._hidden-inputs')
                        <button type="submit" class="corex-btn-outline inline-flex items-center gap-2">
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
@endsection
