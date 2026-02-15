<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-extrabold text-white leading-tight">
                    Company Summary (The 3 Scenarios)
                </h2>
                <p class="text-sm text-white/80 mt-1">
                    Same rules, same period — different inputs. This shows what “today looks like”, what the “target plan looks like”, and what’s “recommended to hit the income goal”.
                </p>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Period Selector --}}
            <div class="hfc-card p-5 sm:p-6">
                <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900">Choose a period</h3>
                        <p class="text-sm text-slate-500">Pick the month you want to discuss in the meeting.</p>
                    </div>

                    <form method="GET" action="{{ route('company.summary') }}" class="flex items-end gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 uppercase tracking-wide">Period</label>
                            <input type="month"
                                   name="period"
                                   value="{{ $period }}"
                                   class="mt-1 border-gray-300 rounded-md shadow-sm">
                        </div>

                        <div>
                            <button type="submit" class="btn-primary px-5">
                                View Summary
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Expenses --}}
            <div class="hfc-card p-5 sm:p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Monthly Company Expenses (Admin-set)</p>
                        <p class="text-3xl font-extrabold text-slate-900 mt-1">R {{ number_format($monthlyExpenses, 2) }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-slate-500">Cashflow is calculated after expenses.</p>
                        <p class="text-xs text-slate-400">This is the “overhead” the business must cover each month.</p>
                    </div>
                </div>
            </div>

            @php
                $blocks = [
                    [
                        'title' => 'Actual',
                        'subtitle' => 'What happens with current stock + pricing quality',
                        'badge' => 'Uses: current listings + correctly priced %',
                        'data' => $actual,
                        'shell' => 'border border-blue-200 bg-blue-50/40',
                        'pill'  => 'bg-blue-600 text-white',
                        'icon'  => '📌',
                    ],
                    [
                        'title' => 'Target',
                        'subtitle' => 'What happens if we hit the targets we set',
                        'badge' => 'Uses: listing targets for the period',
                        'data' => $target,
                        'shell' => 'border border-slate-200 bg-slate-50/60',
                        'pill'  => 'bg-slate-800 text-white',
                        'icon'  => '🎯',
                    ],
                    [
                        'title' => 'Recommended',
                        'subtitle' => 'What is needed to fund the income goals',
                        'badge' => 'Uses: worksheet recommended listings totals',
                        'data' => $recommended,
                        'shell' => 'border border-emerald-200 bg-emerald-50/40',
                        'pill'  => 'bg-emerald-600 text-white',
                        'icon'  => '🚀',
                    ],
                ];
            @endphp

            <div class="grid grid-cols-1 lg:grid-cols-3" style="column-gap:2rem; row-gap:2rem;">
                @foreach($blocks as $b)
                    @php
                        $cash = $b['data']['cashflow'] ?? 0;
                        $cashClass = $cash >= 0 ? 'text-emerald-700' : 'text-red-700';
                        $cashBg = $cash >= 0 ? 'bg-emerald-50 border-emerald-200' : 'bg-red-50 border-red-200';
                    @endphp

                    <div class="hfc-card p-5 sm:p-6 border-2 shadow-lg {{ $b['shell'] }}" style="margin-bottom: 24px;">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="flex items-center gap-2">
                                    <div class="text-xl">{{ $b['icon'] }}</div>
                                    <h3 class="text-xl font-extrabold text-slate-900">{{ $b['title'] }}</h3>
                                </div>
                                <p class="text-sm text-slate-600 mt-1">{{ $b['subtitle'] }}</p>
                            </div>
                            <span class="text-xs px-3 py-1 rounded-full font-bold {{ $b['pill'] }}">{{ $b['badge'] }}</span>
                        </div>

                        {{-- Headline KPI --}}
                        <div class="mt-4 p-4 rounded-xl border {{ $cashBg }}">
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-600">Cashflow (After Expenses)</p>
                            <p class="text-3xl font-extrabold {{ $cashClass }}">R {{ number_format($cash, 2) }}</p>
                            <p class="text-xs text-slate-500 mt-1">
                                This is the business “result” for the month.
                            </p>
                        </div>

                        {{-- Metrics --}}
                        <div class="grid grid-cols-2 gap-4 mt-5">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Total Listings</p>
                                <p class="text-2xl font-extrabold text-slate-900">{{ number_format($b['data']['listings'], 2) }}</p>
                            </div>

                            <div>
                                <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Correctly Priced</p>
                                <p class="text-2xl font-extrabold text-slate-900">{{ number_format($b['data']['cp_listings'], 2) }}</p>
                            </div>

                            <div>
                                <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Est. Sales</p>
                                <p class="text-2xl font-extrabold text-slate-900">{{ number_format($b['data']['sales'], 2) }}</p>
                                <p class="text-xs text-slate-400">CP ÷ 5</p>
                            </div>

                            <div>
                                <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Agency Gross</p>
                                <p class="text-2xl font-extrabold text-slate-900">R {{ number_format($b['data']['agency_gross'], 2) }}</p>
                            </div>
                        </div>

                        <div class="mt-5 border-t border-slate-200 pt-4 space-y-3">
                            <div class="flex items-center justify-between">
                                <p class="text-sm text-slate-600">Company Income</p>
                                <p class="text-lg font-extrabold text-slate-900">R {{ number_format($b['data']['company_income'], 2) }}</p>
                            </div>
                            <div class="flex items-center justify-between">
                                <p class="text-sm text-slate-600">Agents Net (Total)</p>
                                <p class="text-lg font-extrabold text-slate-900">R {{ number_format($b['data']['agent_net'], 2) }}</p>
                            </div>
                        </div>

                        <div class="mt-4 text-xs text-slate-500">
                            <span class="font-bold text-slate-700">How to read:</span>
                            focus on cashflow first, then look at listings → correctly priced → sales.
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="hfc-card p-5 sm:p-6">
                <h3 class="text-lg font-extrabold text-slate-900">The message for the meeting</h3>
                <p class="text-sm text-slate-600 mt-1">
                    If <span class="font-bold">pricing quality</span> improves, sales increase without needing huge stock.
                    If we hit the <span class="font-bold">targets</span>, we stabilize predictable outcomes.
                    If we follow the <span class="font-bold">recommended</span> plan, we align stock with income needs.
                </p>
            </div>

        </div>
    </div>
</x-app-layout>
