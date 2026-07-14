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

    {{-- ── The arithmetic ── --}}
    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="px-5 py-4" style="border-bottom:1px solid var(--border);">
            <h2 class="text-base font-semibold" style="color:var(--text-primary);">How this is worked out</h2>
            <p class="text-sm mt-0.5" style="color:var(--text-secondary);">
                Every line below adds up to your total. Check our maths.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <th class="text-left px-5 py-2.5 font-semibold" style="color:var(--text-secondary);">Item</th>
                        <th class="text-right px-5 py-2.5 font-semibold" style="color:var(--text-secondary);">Qty</th>
                        <th class="text-right px-5 py-2.5 font-semibold" style="color:var(--text-secondary);">Rate</th>
                        <th class="text-right px-5 py-2.5 font-semibold" style="color:var(--text-secondary);">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($quote->lines as $line)
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-5 py-3" style="color:var(--text-primary);">{{ $line['label'] }}</td>
                            <td class="px-5 py-3 text-right" style="color:var(--text-secondary);">{{ $line['qty'] }}</td>
                            <td class="px-5 py-3 text-right" style="color:var(--text-secondary);">{{ Zar::format($line['unit']) }}</td>
                            <td class="px-5 py-3 text-right font-medium" style="color:var(--text-primary);">{{ Zar::format($line['amount']) }}</td>
                        </tr>
                    @endforeach

                    {{-- Subtotal — the list price, always shown, even when overridden --}}
                    <tr style="border-top:1px solid var(--border); background:var(--surface-2);">
                        <td class="px-5 py-3 font-semibold" colspan="3" style="color:var(--text-primary);">
                            {{ $quote->basis === \App\Services\Billing\BillingQuote::BASIS_AUTOMATIC ? 'Total' : 'Standard price' }}
                        </td>
                        <td class="px-5 py-3 text-right font-semibold" style="color:var(--text-primary);">
                            {{ Zar::format($quote->computedZar) }}
                        </td>
                    </tr>

                    @if($quote->basis === \App\Services\Billing\BillingQuote::BASIS_DISCOUNTED)
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-5 py-3" colspan="3" style="color:var(--ds-green,#059669);">
                                Discount ({{ rtrim(rtrim(number_format($quote->discountPercent, 2), '0'), '.') }}%)
                            </td>
                            <td class="px-5 py-3 text-right font-medium" style="color:var(--ds-green,#059669);">
                                −{{ Zar::format($quote->savingZar()) }}
                            </td>
                        </tr>
                    @endif

                    @if($quote->basis === \App\Services\Billing\BillingQuote::BASIS_CUSTOM)
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-5 py-3" colspan="3" style="color:var(--text-secondary);">
                                Your agreed rate replaces the standard price
                            </td>
                            <td class="px-5 py-3 text-right" style="color:var(--text-secondary);">—</td>
                        </tr>
                    @endif

                    @if($quote->basis !== \App\Services\Billing\BillingQuote::BASIS_AUTOMATIC)
                        <tr style="border-top:2px solid var(--border);">
                            <td class="px-5 py-4 text-base font-bold" colspan="3" style="color:var(--text-primary);">You pay</td>
                            <td class="px-5 py-4 text-right text-base font-bold" style="color:var(--text-primary);">
                                {{ Zar::format($quote->payableZar) }}
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Seat explainer — pre-empts the #1 support question ── --}}
    <div class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">What counts as a billable user</div>
        <p class="text-sm" style="color:var(--text-secondary);">
            You have <span class="font-semibold" style="color:var(--text-primary);">{{ $quote->seats }} active {{ Str::plural('user', $quote->seats) }}</span>.
            Every person who can log in counts as one user — agents, admins, principals and support staff alike.
            <span class="font-semibold" style="color:var(--text-primary);">Deactivated and archived users are not billed</span>,
            so if someone leaves, deactivate them and they drop off your next bill.
            @if($quote->branches > 1)
                Your first branch is included; the other {{ $quote->branches - 1 }} {{ Str::plural('branch', $quote->branches - 1) }}
                {{ $quote->branches - 1 === 1 ? 'is' : 'are' }} charged separately.
            @endif
        </p>
    </div>

    @endif

    {{-- ── Read-only, and honest about it (STANDARDS: No Silent Locks) ──
         Don't render a dead edit button and don't leave the user wondering why
         they can't change anything. Say why, and give them the way forward. --}}
    <div class="rounded-md p-4 flex items-start gap-3" style="background:var(--surface-2); border:1px solid var(--border);">
        <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="color:var(--text-muted);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
        </svg>
        <div class="text-sm" style="color:var(--text-secondary);">
            <span class="font-semibold" style="color:var(--text-primary);">This page is read-only.</span>
            Your plan and pricing are set by CoreX and update automatically as your team grows or shrinks.
            To discuss your pricing, email
            <a href="mailto:billing@corexos.co.za?subject=Billing enquiry — {{ $agency->name }}"
               class="font-medium underline" style="color:var(--brand-icon,#0ea5e9);">billing@corexos.co.za</a>.
        </div>
    </div>

</div>
@endsection
