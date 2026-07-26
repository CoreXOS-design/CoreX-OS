{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{--
    Agency Billing — READ ONLY. What this agency pays CoreX, and the arithmetic
    behind it. Spec: .ai/specs/agency-billing.md §8.1 (AT-11)

    The line-item table is the point of this page. An agency must be able to
    check our maths by hand — every rand of the total is attributable to a line.
--}}
@extends('layouts.corex-app')

@php
    use App\Support\Money\Zar;
@endphp

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (branded) --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Billing</h1>
                <p class="text-sm text-white/60">
                    What {{ $agency->name }} pays for CoreX — {{ now()->format('F Y') }}.
                </p>
            </div>
            <div class="text-left md:text-right">
                <div class="text-xs uppercase tracking-wider text-white/50">Monthly total</div>
                <div class="text-3xl font-bold text-white leading-tight">{{ Zar::format($quote->payableZar) }}</div>
            </div>
        </div>
    </div>

    {{-- ── Nothing to bill ──────────────────────────────────────────────────
         A brand-new agency with no active users. Absorb, don't crash: say so
         plainly rather than rendering an empty table and a confusing R 0.00. --}}
    @if($quote->isEmpty())
        <div class="rounded-md p-6 text-center" style="background:var(--surface); border:1px solid var(--border);">
            <div class="text-base font-semibold mb-1" style="color:var(--text-primary);">Nothing to bill yet</div>
            <p class="text-sm" style="color:var(--text-secondary);">
                {{ $agency->name }} has no active users, so there is nothing to charge this month.
                Your bill starts the moment you add your first user.
            </p>
        </div>
    @else

    {{-- ── Custom amount banner ─────────────────────────────────────────────
         The concession is stated openly, WITH the list price it replaces, so
         the agency can see what they're getting. --}}
    @if($quote->basis === \App\Services\Billing\BillingQuote::BASIS_CUSTOM)
        <div class="rounded-md px-4 py-3 flex items-start gap-3"
             style="background:color-mix(in srgb, var(--ds-navy,#0b2a4a) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-navy,#0b2a4a) 25%, transparent);">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="color:var(--ds-navy,#0b2a4a);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <div class="flex-1 text-sm" style="color:var(--text-primary);">
                <div class="font-semibold">You are on an agreed rate of {{ Zar::format($quote->payableZar) }} per month.</div>
                @if($quote->customAmountNote)
                    <div class="mt-0.5" style="color:var(--text-secondary);">{{ $quote->customAmountNote }}</div>
                @endif
                <div class="mt-1" style="color:var(--text-secondary);">
                    The standard price for your {{ $quote->seats }} {{ Str::plural('user', $quote->seats) }}
                    would be {{ Zar::format($quote->computedZar) }}.
                </div>
            </div>
        </div>
    @endif

    {{-- ── Discount banner — with the countdown, which is the part they care about --}}
    @if($quote->discountActive)
        <div class="rounded-md px-4 py-3 flex items-start gap-3"
             style="background:color-mix(in srgb, var(--ds-green,#059669) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green,#059669) 30%, transparent);">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="color:var(--ds-green,#059669);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z" />
            </svg>
            <div class="flex-1 text-sm" style="color:var(--text-primary);">
                <div class="font-semibold">
                    {{ rtrim(rtrim(number_format($quote->discountPercent, 2), '0'), '.') }}% off —
                    {{ $quote->discountMonthsRemaining }} {{ Str::plural('month', $quote->discountMonthsRemaining) }} remaining
                </div>
                <div class="mt-0.5" style="color:var(--text-secondary);">
                    You are saving {{ Zar::format($quote->savingZar()) }} a month.
                    This discount runs until {{ \Illuminate\Support\Carbon::parse($quote->discountEndsOn)->format('j F Y') }},
                    after which your price returns to {{ Zar::format($quote->computedZar) }}.
                </div>
                @if($quote->discountNote)
                    <div class="mt-0.5" style="color:var(--text-secondary);">{{ $quote->discountNote }}</div>
                @endif
            </div>
        </div>
    @endif

    {{-- ── Summary tiles ── --}}
    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Your plan" :value="$quote->planLabel" />
        <x-corex-kpi-card title="Billable users" :value="(string) $quote->seats" />
        <x-corex-kpi-card title="Branches" :value="(string) $quote->branches" />
        <x-corex-kpi-card title="Monthly total" :value="Zar::format($quote->payableZar)" />
    </div>

    {{-- ── The arithmetic, as a sectioned receipt ──────────────────────────
         Sections come from each line's `group` key, never from matching its
         label — rename a label and a label-matching view would silently
         mis-section the money. --}}
    <div class="rounded-md overflow-hidden" x-data="{ details: false }"
         style="background:var(--surface); border:1px solid var(--border);">

        <div class="px-5 py-4 flex flex-wrap items-start justify-between gap-3" style="border-bottom:1px solid var(--border);">
            <div>
                <h2 class="text-base font-semibold" style="color:var(--text-primary);">How this is worked out</h2>
                <p class="text-sm mt-0.5" style="color:var(--text-secondary);">
                    You are on the {{ $quote->planLabel }} plan. Every line below adds up to your total — check our maths.
                </p>
            </div>

            <button type="button" @click="details = !details"
                    class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium shrink-0"
                    style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                </svg>
                <span x-text="details ? 'Hide details' : 'Show details'">Show details</span>
            </button>
        </div>

        {{-- ══ SECTION: base fee ══ --}}
        @foreach($quote->linesIn('base') as $line)
            <div class="px-5 py-4 flex items-start justify-between gap-4" style="border-bottom:1px solid var(--border);">
                <div>
                    <div class="text-sm font-medium" style="color:var(--text-primary);">{{ $line['label'] }}</div>
                    <div class="text-xs mt-0.5" style="color:var(--text-secondary);">{{ $line['note'] }}</div>
                </div>
                <div class="text-sm font-semibold whitespace-nowrap" style="color:var(--text-primary);">
                    {{ Zar::format($line['amount']) }}
                </div>
            </div>
        @endforeach

        {{-- ══ SECTION: users ══ --}}
        @if(count($quote->linesIn('seats')))
            <div class="px-5 py-4" style="border-bottom:1px solid var(--border);">
                <div class="flex items-start justify-between gap-4 mb-3">
                    <div>
                        <div class="text-sm font-medium" style="color:var(--text-primary);">
                            Users — you have {{ $quote->seats }}
                        </div>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">
                            @if($quote->derivedPlan === \App\Models\Billing\AgencySubscription::PLAN_AGENCY)
                                Rates are banded by how many users you have — not by who they are.
                                Nobody is "the R195 person"; the bands simply price your headcount.
                            @else
                                One flat rate for every user.
                            @endif
                        </div>
                    </div>
                    <div class="text-sm font-semibold whitespace-nowrap" style="color:var(--text-primary);">
                        {{ Zar::format($quote->subtotalIn('seats')) }}
                    </div>
                </div>

                <div class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
                    @foreach($quote->linesIn('seats') as $line)
                        <div class="flex items-center justify-between gap-3 px-3 py-2 text-xs"
                             style="background:var(--surface-2); {{ ! $loop->first ? 'border-top:1px solid var(--border);' : '' }}">
                            <span style="color:var(--text-primary);">{{ $line['label'] }}</span>
                            <span class="flex items-center gap-3 whitespace-nowrap">
                                <span style="color:var(--text-muted);">
                                    {{ $line['qty'] }} × {{ Zar::format($line['unit']) }}
                                </span>
                                <span class="font-medium tabular-nums" style="color:var(--text-primary); min-width:5.5rem; text-align:right;">
                                    {{ Zar::format($line['amount']) }}
                                </span>
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ══ SECTION: branches ══ --}}
        @foreach($quote->linesIn('branches') as $line)
            <div class="px-5 py-4 flex items-start justify-between gap-4" style="border-bottom:1px solid var(--border);">
                <div>
                    <div class="text-sm font-medium" style="color:var(--text-primary);">
                        Branches — you have {{ $quote->branches }}
                    </div>
                    <div class="text-xs mt-0.5" style="color:var(--text-secondary);">
                        {{ $line['note'] }}
                        {{ $line['qty'] }} extra {{ Str::plural('branch', $line['qty']) }} × {{ Zar::format($line['unit']) }}.
                    </div>
                </div>
                <div class="text-sm font-semibold whitespace-nowrap" style="color:var(--text-primary);">
                    {{ Zar::format($line['amount']) }}
                </div>
            </div>
        @endforeach

        {{-- ══ DETAILS — exactly who and what is on this bill ══ --}}
        <div x-show="details" x-cloak style="background:var(--surface-2); border-bottom:1px solid var(--border);">
            <div class="px-5 py-4 space-y-5">

                {{-- Who you are paying for --}}
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">
                        The {{ count($billableUsers) }} {{ Str::plural('user', count($billableUsers)) }} on this bill
                    </div>

                    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
                        @forelse($billableUsers as $u)
                            <div class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                                 style="{{ ! $loop->first ? 'border-top:1px solid var(--border);' : '' }}">
                                <div class="min-w-0">
                                    <div class="font-medium truncate" style="color:var(--text-primary);">{{ $u['name'] }}</div>
                                    <div class="text-xs truncate" style="color:var(--text-muted);">{{ $u['email'] }}</div>
                                </div>
                                <span class="text-xs whitespace-nowrap" style="color:var(--text-secondary);">{{ $u['role'] }}</span>
                            </div>
                        @empty
                            <div class="px-3 py-3 text-sm" style="color:var(--text-muted);">No active users.</div>
                        @endforelse
                    </div>

                    {{-- Proof that deactivating someone really does take them off the bill --}}
                    @if($excludedUsers['deactivated'] > 0 || $excludedUsers['archived'] > 0)
                        <div class="text-xs mt-2" style="color:var(--text-secondary);">
                            <span class="font-semibold" style="color:var(--text-primary);">Not billed:</span>
                            @if($excludedUsers['deactivated'] > 0)
                                {{ $excludedUsers['deactivated'] }} deactivated
                            @endif
                            @if($excludedUsers['deactivated'] > 0 && $excludedUsers['archived'] > 0), @endif
                            @if($excludedUsers['archived'] > 0)
                                {{ $excludedUsers['archived'] }} archived
                            @endif
                            — these {{ ($excludedUsers['deactivated'] + $excludedUsers['archived']) === 1 ? 'person is' : 'people are' }} not counted.
                        </div>
                    @endif
                </div>

                {{-- What branches you are paying for --}}
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">
                        Your {{ count($branchRows) }} {{ Str::plural('branch', count($branchRows)) }}
                    </div>

                    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
                        @forelse($branchRows as $b)
                            <div class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                                 style="{{ ! $loop->first ? 'border-top:1px solid var(--border);' : '' }}">
                                <span class="font-medium truncate" style="color:var(--text-primary);">{{ $b['name'] }}</span>
                                @if($b['included'])
                                    <span class="text-xs whitespace-nowrap" style="color:var(--ds-green,#059669);">Included free</span>
                                @else
                                    <span class="text-xs whitespace-nowrap" style="color:var(--text-secondary);">
                                        {{ Zar::format(config('corex-billing.branches.rate')) }}
                                    </span>
                                @endif
                            </div>
                        @empty
                            <div class="px-3 py-3 text-sm" style="color:var(--text-muted);">No branches.</div>
                        @endforelse
                    </div>
                </div>

            </div>
        </div>

        {{-- ══ TOTALS ══ --}}
        <div class="px-5 py-4">
            <div class="flex items-center justify-between gap-4 text-sm">
                <span style="color:var(--text-secondary);">
                    {{ $quote->basis === \App\Services\Billing\BillingQuote::BASIS_AUTOMATIC ? 'Total' : 'Standard price' }}
                </span>
                <span class="font-medium tabular-nums" style="color:var(--text-primary);">{{ Zar::format($quote->computedZar) }}</span>
            </div>

            @if($quote->basis === \App\Services\Billing\BillingQuote::BASIS_DISCOUNTED)
                <div class="flex items-center justify-between gap-4 text-sm mt-2">
                    <span style="color:var(--ds-green,#059669);">
                        {{ $quote->discountNote ?: 'Discount' }}
                        ({{ rtrim(rtrim(number_format($quote->discountPercent, 2), '0'), '.') }}% off)
                    </span>
                    <span class="font-medium tabular-nums" style="color:var(--ds-green,#059669);">
                        −{{ Zar::format($quote->savingZar()) }}
                    </span>
                </div>
            @endif

            @if($quote->basis === \App\Services\Billing\BillingQuote::BASIS_CUSTOM)
                <div class="flex items-center justify-between gap-4 text-sm mt-2">
                    <span style="color:var(--text-secondary);">Your agreed rate replaces the standard price</span>
                    <span style="color:var(--text-muted);">—</span>
                </div>
            @endif

            @if($quote->basis !== \App\Services\Billing\BillingQuote::BASIS_AUTOMATIC)
                <div class="flex items-center justify-between gap-4 mt-3 pt-3" style="border-top:1px solid var(--border);">
                    <span class="text-base font-bold" style="color:var(--text-primary);">You pay</span>
                    <span class="text-base font-bold tabular-nums" style="color:var(--text-primary);">{{ Zar::format($quote->payableZar) }}</span>
                </div>
            @endif
        </div>
    </div>

    {{-- ── Seat explainer — pre-empts the #1 support question ── --}}
    <div class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">What counts as a billable user</div>
        <p class="text-sm" style="color:var(--text-secondary);">
            You have <span class="font-semibold" style="color:var(--text-primary);">{{ $quote->seats }} active {{ Str::plural('user', $quote->seats) }}</span>.
            Every person who can log in counts as one user, whatever their role —
            <span class="font-semibold" style="color:var(--text-primary);">except assistants</span>, who work on behalf of an agent and are never billed as a separate seat.
            <span class="font-semibold" style="color:var(--text-primary);">Deactivated and archived users are not billed</span> either,
            so if someone leaves, deactivate them and they drop off your next bill.
            @if($quote->branches > 1)
                Your first branch is included; the other {{ $quote->branches - 1 }} {{ Str::plural('branch', $quote->branches - 1) }}
                {{ $quote->branches - 1 === 1 ? 'is' : 'are' }} charged separately.
            @endif
        </p>
    </div>

    @endif

    {{-- ── Read-only, and honest about it (STANDARDS: No Silent Locks) ──
         Don't render a dead edit button, and don't leave the user wondering why
         they can't change anything. Say why. (The contact line was removed on
         Johan's instruction — the "why" stays, since a silently uneditable
         surface is exactly what that standard forbids.) --}}
    <div class="rounded-md p-4 flex items-start gap-3" style="background:var(--surface-2); border:1px solid var(--border);">
        <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="color:var(--text-muted);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
        </svg>
        <div class="text-sm" style="color:var(--text-secondary);">
            <span class="font-semibold" style="color:var(--text-primary);">This page is read-only.</span>
            Your plan and pricing are set by CoreX and update automatically as your team grows or shrinks.
        </div>
    </div>

</div>
@endsection
