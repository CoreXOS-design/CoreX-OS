<x-app-layout>
    @php $locked = $deal->isFinanciallyLocked(); @endphp

    <div x-data="settlementForm()" x-cloak>
        {{-- Sticky header --}}
        <div class="sticky top-0 z-30 -mx-4 -mt-4 mb-0 lg:-mx-6 lg:-mt-6" style="background: var(--surface); border-bottom: 1px solid var(--border);">
            <div class="flex items-center justify-between px-4 sm:px-6 lg:px-8 py-3">
                <div class="flex items-center gap-3 min-w-0">
                    <a href="{{ route('deals-v2.show', $deal) }}" class="inline-flex items-center gap-1 text-sm flex-shrink-0" style="color: var(--text-muted);">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        Back
                    </a>
                    <span class="flex-shrink-0" style="color: var(--border);">|</span>
                    <h1 class="text-lg font-semibold truncate" style="color: var(--text-primary);">Settlement — {{ $deal->reference }}</h1>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    @if($locked)
                        <span class="text-xs px-2.5 py-1 rounded-full font-medium" style="background: rgba(16,185,129,0.15); color: #34d399;">Paid ✓</span>
                    @endif
                    <a href="{{ route('deals-v2.settlement.print', $deal) }}" target="_blank" class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors" style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">Print</a>
                </div>
            </div>
        </div>

        <div class="p-4 lg:p-6 max-w-6xl mx-auto space-y-6">
            @if(session('status'))
                <div class="p-3 rounded-lg text-sm" style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #34d399;">{{ session('status') }}</div>
            @endif
            @if(session('error'))
                <div class="p-3 rounded-lg text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: #f87171;">{{ session('error') }}</div>
            @endif

            {{-- Deal Summary --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="rounded-xl p-3" style="border: 1px solid var(--border); background: var(--surface);">
                    <div class="text-xs" style="color: var(--text-muted);">Property</div>
                    <div class="text-sm font-medium" style="color: var(--text-primary);">{{ Str::limit($deal->property->address ?? '—', 30) }}</div>
                </div>
                <div class="rounded-xl p-3" style="border: 1px solid var(--border); background: var(--surface);">
                    <div class="text-xs" style="color: var(--text-muted);">Purchase Price</div>
                    <div class="text-sm font-medium font-mono" style="color: var(--text-primary);">R {{ number_format($deal->purchase_price, 2) }}</div>
                </div>
                <div class="rounded-xl p-3" style="border: 1px solid var(--border); background: var(--surface);">
                    <div class="text-xs" style="color: var(--text-muted);">Commission (inc VAT)</div>
                    <div class="text-sm font-medium font-mono" style="color: var(--text-primary);">R {{ number_format($deal->commission_amount + $deal->commission_vat, 2) }}</div>
                </div>
                <div class="rounded-xl p-3" style="border: 1px solid var(--border); background: var(--surface);">
                    <div class="text-xs" style="color: var(--text-muted);">Commission (ex VAT)</div>
                    <div class="text-sm font-medium font-mono" style="color: #2dd4bf;">R {{ number_format($summary['commExVat'], 2) }}</div>
                </div>
            </div>

            {{-- Settlement Form --}}
            <form method="POST" action="{{ route('deals-v2.settlement.save', $deal) }}">
                @csrf

                @foreach(['listing', 'selling'] as $side)
                    @php
                        $isExternal = $deal->{$side . '_external'};
                        $pool = $side === 'listing' ? $summary['listingPool'] : $summary['sellingPool'];
                        $rows = $side === 'listing' ? $summary['listingRows'] : $summary['sellingRows'];
                        $sidePct = $deal->{$side . '_split_percent'};
                    @endphp

                    <div class="rounded-xl overflow-hidden" style="border: 1px solid var(--border); background: var(--surface);">
                        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
                            <div>
                                <span class="text-sm font-semibold uppercase tracking-wider" style="color: var(--text-primary);">{{ ucfirst($side) }} Side ({{ number_format($sidePct, 0) }}%)</span>
                                <span class="text-xs ml-2 font-mono" style="color: var(--text-muted);">Pool: R {{ number_format($pool, 2) }}</span>
                            </div>
                            @if($isExternal)
                                <span class="text-xs px-2 py-0.5 rounded" style="background: rgba(245,158,11,0.15); color: #fbbf24;">External — {{ $deal->{$side . '_external_agency'} ?? 'Unknown' }}</span>
                            @endif
                        </div>

                        @if(!$isExternal && count($rows) > 0)
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-xs">
                                    <thead>
                                        <tr style="border-bottom: 1px solid var(--border);">
                                            <th class="text-left px-3 py-2" style="color: var(--text-muted);">Agent</th>
                                            <th class="text-right px-3 py-2" style="color: var(--text-muted);">Share %</th>
                                            <th class="text-right px-3 py-2" style="color: var(--text-muted);">Allocated</th>
                                            <th class="text-right px-3 py-2" style="color: var(--text-muted);">Cut %</th>
                                            <th class="text-right px-3 py-2" style="color: var(--text-muted);">Gross</th>
                                            <th class="text-center px-3 py-2" style="color: var(--text-muted);">PAYE</th>
                                            <th class="text-right px-3 py-2" style="color: var(--text-muted);">PAYE Val</th>
                                            <th class="text-right px-3 py-2" style="color: var(--text-muted);">PAYE Amt</th>
                                            <th class="text-right px-3 py-2" style="color: var(--text-muted);">Deduct</th>
                                            <th class="text-right px-3 py-2" style="color: var(--text-muted);">Net</th>
                                            <th class="text-right px-3 py-2" style="color: var(--text-muted);">Company</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($rows as $row)
                                            @if($row['user_id'] === 0)
                                                <tr style="border-top: 1px solid var(--border); background: var(--surface-2);">
                                                    <td class="px-3 py-2 font-medium" style="color: var(--text-muted);" colspan="2">{{ $row['name'] }}</td>
                                                    <td class="px-3 py-2 text-right font-mono" style="color: var(--text-primary);">R {{ number_format($row['allocated'], 2) }}</td>
                                                    <td colspan="7"></td>
                                                    <td class="px-3 py-2 text-right font-mono" style="color: var(--text-primary);">R {{ number_format($row['company'], 2) }}</td>
                                                </tr>
                                            @else
                                                <tr style="border-bottom: 1px solid var(--border);">
                                                    <td class="px-3 py-2 font-medium" style="color: var(--text-primary);">{{ $row['name'] }}</td>
                                                    <td class="px-3 py-2 text-right">
                                                        <input type="number" name="{{ $side }}_share[{{ $row['user_id'] }}]" value="{{ number_format($row['share_percent'], 2, '.', '') }}" step="0.01" min="0" max="100" {{ $locked ? 'disabled' : '' }}
                                                               class="w-16 rounded text-xs px-1 py-0.5 text-right focus:outline-none"
                                                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                    </td>
                                                    <td class="px-3 py-2 text-right font-mono" style="color: var(--text-primary);">R {{ number_format($row['allocated'], 2) }}</td>
                                                    <td class="px-3 py-2 text-right">
                                                        <input type="number" name="{{ $side }}_cut[{{ $row['user_id'] }}]" value="{{ number_format($row['agent_cut_percent'], 2, '.', '') }}" step="0.01" min="0" {{ $locked ? 'disabled' : '' }}
                                                               class="w-16 rounded text-xs px-1 py-0.5 text-right focus:outline-none"
                                                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                    </td>
                                                    <td class="px-3 py-2 text-right font-mono" style="color: var(--text-primary);">R {{ number_format($row['gross'], 2) }}</td>
                                                    <td class="px-3 py-2 text-center">
                                                        <select name="{{ $side }}_paye_method[{{ $row['user_id'] }}]" {{ $locked ? 'disabled' : '' }}
                                                                class="rounded text-xs px-1 py-0.5 focus:outline-none"
                                                                style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                            <option value="percentage" {{ $row['paye_method'] === 'percentage' ? 'selected' : '' }}>%</option>
                                                            <option value="fixed" {{ $row['paye_method'] === 'fixed' ? 'selected' : '' }}>Fixed</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-3 py-2 text-right">
                                                        <input type="number" name="{{ $side }}_paye_value[{{ $row['user_id'] }}]" value="{{ number_format($row['paye_value'], 2, '.', '') }}" step="0.01" min="0" {{ $locked ? 'disabled' : '' }}
                                                               class="w-16 rounded text-xs px-1 py-0.5 text-right focus:outline-none"
                                                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                    </td>
                                                    <td class="px-3 py-2 text-right font-mono" style="color: var(--text-muted);">R {{ number_format($row['paye'], 2) }}</td>
                                                    <td class="px-3 py-2 text-right">
                                                        <input type="number" name="{{ $side }}_deductions[{{ $row['user_id'] }}]" value="{{ number_format($row['deductions'], 2, '.', '') }}" step="0.01" min="0" {{ $locked ? 'disabled' : '' }}
                                                               class="w-16 rounded text-xs px-1 py-0.5 text-right focus:outline-none"
                                                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                        <input type="hidden" name="{{ $side }}_deductions_desc[{{ $row['user_id'] }}]" value="{{ $row['deductions_description'] }}">
                                                    </td>
                                                    <td class="px-3 py-2 text-right font-mono font-medium" style="color: #2dd4bf;">R {{ number_format($row['net'], 2) }}</td>
                                                    <td class="px-3 py-2 text-right font-mono" style="color: var(--text-muted);">R {{ number_format($row['company'], 2) }}</td>
                                                </tr>
                                            @endif
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @elseif($isExternal)
                            <div class="px-4 py-3 text-sm" style="color: var(--text-muted);">
                                External side — payable to {{ $deal->{$side . '_external_agency'} ?? 'external agency' }}: R {{ number_format($deal->commissionExVat() * (($deal->{$side . '_split_percent'} ?? 0) / 100), 2) }}
                            </div>
                        @else
                            <div class="px-4 py-3 text-sm" style="color: var(--text-muted);">No agents assigned to this side.</div>
                        @endif
                    </div>
                @endforeach

                {{-- Checksum --}}
                <div class="rounded-xl p-4 flex items-center justify-between" style="border: 1px solid {{ $summary['checksumOk'] ? 'rgba(16,185,129,0.3)' : 'color-mix(in srgb, var(--ds-crimson) 30%, transparent)' }}; background: {{ $summary['checksumOk'] ? 'rgba(16,185,129,0.05)' : 'rgba(239,68,68,0.05)' }};">
                    <div class="text-sm" style="color: {{ $summary['checksumOk'] ? '#34d399' : '#f87171' }};">
                        Checksum: Net (R {{ number_format($summary['totals']['net'], 2) }}) + PAYE + Deductions + Company + External = R {{ number_format($summary['checksumTotal'], 2) }}
                        <span class="font-medium">vs Commission ex VAT R {{ number_format($summary['commExVat'], 2) }}</span>
                    </div>
                    @if($summary['checksumOk'])
                        <span style="color: #34d399;">✓ Balanced</span>
                    @else
                        <span style="color: #f87171;">⚠ Not balanced</span>
                    @endif
                </div>

                {{-- Actions --}}
                @if(!$locked)
                    <div class="flex items-center gap-3">
                        <button type="submit" name="mark_paid" value="0" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors" style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                            Save Settlement
                        </button>
                        <button type="submit" name="mark_paid" value="1" class="px-4 py-2 rounded-lg bg-teal-600 hover:bg-teal-500 text-white text-sm font-medium transition-colors"
                                onclick="return confirm('Mark this deal as Paid? This locks all financial fields.')">
                            Save & Mark Paid
                        </button>
                    </div>
                @endif
            </form>

            {{-- Agent Summary --}}
            @if(count($summary['agentSummary']) > 0)
                <div class="rounded-xl overflow-hidden" style="border: 1px solid var(--border); background: var(--surface);">
                    <div class="px-4 py-3" style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
                        <span class="text-sm font-semibold uppercase tracking-wider" style="color: var(--text-primary);">Agent Summary</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead>
                                <tr style="border-bottom: 1px solid var(--border);">
                                    <th class="text-left px-3 py-2" style="color: var(--text-muted);">Agent</th>
                                    <th class="text-right px-3 py-2" style="color: var(--text-muted);">Allocated</th>
                                    <th class="text-right px-3 py-2" style="color: var(--text-muted);">Gross</th>
                                    <th class="text-right px-3 py-2" style="color: var(--text-muted);">PAYE</th>
                                    <th class="text-right px-3 py-2" style="color: var(--text-muted);">Deductions</th>
                                    <th class="text-right px-3 py-2" style="color: var(--text-muted);">Net</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($summary['agentSummary'] as $as)
                                    <tr style="border-bottom: 1px solid var(--border);">
                                        <td class="px-3 py-2 font-medium" style="color: var(--text-primary);">{{ $as['name'] }}</td>
                                        <td class="px-3 py-2 text-right font-mono" style="color: var(--text-primary);">R {{ number_format($as['allocated'], 2) }}</td>
                                        <td class="px-3 py-2 text-right font-mono" style="color: var(--text-primary);">R {{ number_format($as['gross'], 2) }}</td>
                                        <td class="px-3 py-2 text-right font-mono" style="color: var(--text-muted);">R {{ number_format($as['paye'], 2) }}</td>
                                        <td class="px-3 py-2 text-right font-mono" style="color: var(--text-muted);">R {{ number_format($as['deductions'], 2) }}</td>
                                        <td class="px-3 py-2 text-right font-mono font-medium" style="color: #2dd4bf;">R {{ number_format($as['net'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <script>
        function settlementForm() {
            return {};
        }
    </script>
</x-app-layout>
