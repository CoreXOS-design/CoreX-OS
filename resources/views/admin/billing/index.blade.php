{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{--
    System Developer → Agency Billing. Every agency, what they owe CoreX, and the
    controls to override it. Spec: .ai/specs/agency-billing.md §8.2 (AT-11)

    OWNER-ONLY. This page shows every agency's commercial terms — it is gated by
    `owner_only` middleware and deliberately carries no permission key.

    The edit form is a THREE-WAY MODE SELECTOR (Automatic / Custom / Discount),
    not two independent optional blocks. That is what makes decision D5 —
    "custom amount and discount are never both set" — structural rather than a
    rule someone has to remember.
--}}
@extends('layouts.corex-app')

@php
    use App\Support\Money\Zar;
    use App\Services\Billing\BillingQuote;
    use App\Models\Billing\AgencySubscription;
@endphp

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Agency Billing</h1>
                <p class="text-sm text-white/60">
                    What every agency pays CoreX — {{ now()->format('F Y') }}. Prices follow headcount automatically
                    unless you set a custom amount or a discount.
                </p>
            </div>
            <div class="text-left md:text-right">
                <div class="text-xs uppercase tracking-wider text-white/50">Monthly recurring revenue</div>
                <div class="text-3xl font-bold text-white leading-tight">{{ Zar::format($totals['mrr_zar']) }}</div>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background:color-mix(in srgb, var(--ds-green,#059669) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green,#059669) 30%, transparent); color:var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="color:var(--ds-green,#059669);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background:color-mix(in srgb, var(--ds-crimson,#c41e3a) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson,#c41e3a) 30%, transparent); color:var(--text-primary);">
            <div class="font-semibold mb-1">That didn't save:</div>
            <ul class="list-disc list-inside space-y-0.5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- KPI row --}}
    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Monthly revenue" :value="Zar::format($totals['mrr_zar'])" />
        <x-corex-kpi-card title="Billable users" :value="number_format($totals['seats'])" />
        <x-corex-kpi-card title="On Team plan" :value="(string) $totals['on_team']" />
        <x-corex-kpi-card title="On Agency plan" :value="(string) $totals['on_agency']" />
    </div>

    @if($totals['discount_zar'] > 0)
        <div class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Concessions</div>
            <p class="text-sm" style="color:var(--text-secondary);">
                List price across all agencies is <span class="font-semibold" style="color:var(--text-primary);">{{ Zar::format($totals['list_zar']) }}</span>;
                we are billing <span class="font-semibold" style="color:var(--text-primary);">{{ Zar::format($totals['mrr_zar']) }}</span> —
                giving away <span class="font-semibold" style="color:var(--ds-amber,#f59e0b);">{{ Zar::format($totals['discount_zar']) }}</span> a month
                in custom amounts and discounts.
            </p>
        </div>
    @endif

    {{-- ── The table ── --}}
    <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <th class="text-left  px-4 py-2.5 font-semibold" style="color:var(--text-secondary);">Agency</th>
                        <th class="text-right px-4 py-2.5 font-semibold" style="color:var(--text-secondary);">Users</th>
                        <th class="text-right px-4 py-2.5 font-semibold" style="color:var(--text-secondary);">Branches</th>
                        <th class="text-left  px-4 py-2.5 font-semibold" style="color:var(--text-secondary);">Plan</th>
                        <th class="text-right px-4 py-2.5 font-semibold" style="color:var(--text-secondary);">List price</th>
                        <th class="text-left  px-4 py-2.5 font-semibold" style="color:var(--text-secondary);">Basis</th>
                        <th class="text-right px-4 py-2.5 font-semibold" style="color:var(--text-secondary);">Payable</th>
                        <th class="text-right px-4 py-2.5 font-semibold" style="color:var(--text-secondary);"></th>
                    </tr>
                </thead>

                @forelse($rows as $row)
                    @php
                        $agency = $row['agency'];
                        $quote  = $row['quote'];
                        $sub    = $row['subscription'];
                        $mode   = $quote->basis === BillingQuote::BASIS_CUSTOM ? 'custom'
                                : ($sub->discount_percent !== null ? 'discount' : 'automatic');
                    @endphp

                {{-- One <tbody> per agency: a table may carry many, and it is the only way
                     to give the summary row and its edit row a SHARED Alpine scope, so the
                     Edit button actually toggles the form below it. --}}
                <tbody x-data="{ open: false, mode: '{{ $mode }}' }">
                    <tr style="border-top:1px solid var(--border);">
                        <td class="px-4 py-3">
                            <div class="font-medium" style="color:var(--text-primary);">{{ $agency->name }}</div>
                            @if($quote->seats === 0)
                                <div class="text-xs" style="color:var(--ds-amber,#f59e0b);">No active users — nothing to bill</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right" style="color:var(--text-primary);">{{ $quote->seats }}</td>
                        <td class="px-4 py-3 text-right" style="color:var(--text-secondary);">
                            {{ $quote->branches }}
                            @if($quote->billableBranches > 0)
                                <span class="text-xs" style="color:var(--text-muted);">(+{{ $quote->billableBranches }} billed)</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="ds-badge {{ $quote->derivedPlan === AgencySubscription::PLAN_AGENCY ? 'ds-badge-info' : 'ds-badge-success' }}">
                                {{ $quote->planLabel }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right" style="color:var(--text-secondary);">{{ Zar::format($quote->computedZar) }}</td>
                        <td class="px-4 py-3">
                            @if($quote->basis === BillingQuote::BASIS_CUSTOM)
                                <span class="text-xs font-medium" style="color:var(--ds-navy,#0b2a4a);">Custom amount</span>
                                @if($quote->customAmountNote)
                                    <div class="text-xs" style="color:var(--text-muted);">{{ Str::limit($quote->customAmountNote, 40) }}</div>
                                @endif
                            @elseif($quote->discountActive)
                                <span class="text-xs font-medium" style="color:var(--ds-green,#059669);">
                                    −{{ rtrim(rtrim(number_format($quote->discountPercent, 2), '0'), '.') }}%
                                </span>
                                <div class="text-xs" style="color:var(--text-muted);">
                                    {{ $quote->discountMonthsRemaining }} {{ Str::plural('month', $quote->discountMonthsRemaining) }} left
                                </div>
                            @elseif($sub->discount_percent !== null)
                                {{-- A discount that has run its course. Shown, not hidden — otherwise
                                     a price silently "goes up" and nobody knows why. --}}
                                <span class="text-xs" style="color:var(--text-muted);">Discount expired</span>
                            @else
                                <span class="text-xs" style="color:var(--text-muted);">Automatic</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-semibold" style="color:var(--text-primary);">
                            {{ Zar::format($quote->payableZar) }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button type="button" @click="open = !open" class="text-xs font-medium underline"
                                    style="color:var(--brand-icon,#0ea5e9);"
                                    x-text="open ? 'Close' : 'Edit'">Edit</button>
                        </td>
                    </tr>

                    {{-- ── Edit row ─────────────────────────────────────────────
                         Shares the <tbody>'s Alpine scope, so `open` and `mode`
                         are the SAME state the Edit button above toggles.

                         The mode selector IS the invariant: choosing one mode
                         hides and clears the others, so there is no shape of
                         submitted form that carries both a custom amount and a
                         discount. --}}
                    <tr x-show="open" x-cloak>
                        <td colspan="8" class="p-0">
                            <div style="background:var(--surface-2); border-top:1px solid var(--border);">
                                <form method="POST" action="{{ route('admin.billing.update', $agency) }}" class="p-5 space-y-4">
                                    @csrf
                                    @method('PUT')

                                    <div class="text-sm font-semibold" style="color:var(--text-primary);">
                                        Pricing for {{ $agency->name }}
                                    </div>

                                    {{-- Mode selector --}}
                                    <div class="flex flex-wrap gap-2">
                                        @foreach([
                                            'automatic' => 'Automatic — follows headcount',
                                            'custom'    => 'Custom amount',
                                            'discount'  => 'Discount %',
                                        ] as $value => $label)
                                            <label class="cursor-pointer">
                                                <input type="radio" name="mode" value="{{ $value }}" x-model="mode" class="sr-only">
                                                {{-- Text colour rides on a Tailwind class, not an inline hex: the only
                                                     colour a token can't express here is "white on the brand button", and
                                                     `text-white` says it without hardcoding one. --}}
                                                <span class="inline-block rounded-md px-3 py-1.5 text-xs font-medium transition"
                                                      :class="mode === '{{ $value }}' ? 'text-white' : ''"
                                                      :style="mode === '{{ $value }}'
                                                          ? 'background:var(--brand-button,#0ea5e9); border:1px solid var(--brand-button,#0ea5e9);'
                                                          : 'background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);'">
                                                    {{ $label }}
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>

                                    {{-- Automatic --}}
                                    <div x-show="mode === 'automatic'" x-cloak class="text-sm" style="color:var(--text-secondary);">
                                        This agency pays the standard price for their headcount —
                                        currently <span class="font-semibold" style="color:var(--text-primary);">{{ Zar::format($quote->computedZar) }}</span>/month.
                                        Saving in this mode clears any custom amount or discount.
                                    </div>

                                    {{-- Custom amount --}}
                                    <div x-show="mode === 'custom'" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">
                                                Monthly amount (ZAR)
                                            </label>
                                            <input type="text" name="custom_amount_zar"
                                                   value="{{ old('custom_amount_zar', $sub->custom_amount_zar) }}"
                                                   placeholder="5000"
                                                   class="w-full rounded-md px-3 py-2 text-sm"
                                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                            <div class="text-xs mt-1" style="color:var(--text-muted);">
                                                This becomes the price, replacing the {{ Zar::format($quote->computedZar) }} list price.
                                                Enter 0 to make this agency free.
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">
                                                Note (shown to the agency)
                                            </label>
                                            <input type="text" name="custom_amount_note"
                                                   value="{{ old('custom_amount_note', $sub->custom_amount_note) }}"
                                                   placeholder="Negotiated launch rate"
                                                   class="w-full rounded-md px-3 py-2 text-sm"
                                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                    </div>

                                    {{-- Discount --}}
                                    <div x-show="mode === 'discount'" x-cloak class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                                        <div>
                                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Discount %</label>
                                            <input type="number" step="0.01" min="0.01" max="100" name="discount_percent"
                                                   value="{{ old('discount_percent', $sub->discount_percent) }}"
                                                   placeholder="20"
                                                   class="w-full rounded-md px-3 py-2 text-sm"
                                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">For how many months</label>
                                            <input type="number" step="1" min="1" max="120" name="discount_months"
                                                   value="{{ old('discount_months', $sub->discount_months) }}"
                                                   placeholder="6"
                                                   class="w-full rounded-md px-3 py-2 text-sm"
                                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Starting on</label>
                                            <input type="date" name="discount_starts_on"
                                                   value="{{ old('discount_starts_on', optional($sub->discount_starts_on)->toDateString() ?? now()->toDateString()) }}"
                                                   class="w-full rounded-md px-3 py-2 text-sm"
                                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Note (shown to the agency)</label>
                                            <input type="text" name="discount_note"
                                                   value="{{ old('discount_note', $sub->discount_note) }}"
                                                   placeholder="Launch offer"
                                                   class="w-full rounded-md px-3 py-2 text-sm"
                                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div class="sm:col-span-4 text-xs" style="color:var(--text-muted);">
                                            The discount comes off the list price and expires on its own — no need to come back and remove it.
                                        </div>
                                    </div>

                                    {{-- Internal notes --}}
                                    <div>
                                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">
                                            Internal notes (never shown to the agency)
                                        </label>
                                        <textarea name="notes" rows="2"
                                                  class="w-full rounded-md px-3 py-2 text-sm"
                                                  style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                                  placeholder="Why these terms — who agreed them, when.">{{ old('notes', $sub->notes) }}</textarea>
                                    </div>

                                    <div class="flex items-center gap-3">
                                        <button type="submit" class="corex-btn-primary text-sm">Save pricing</button>
                                        <button type="button" @click="open = false" class="text-sm" style="color:var(--text-secondary);">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        </td>
                    </tr>
                </tbody>
                @empty
                <tbody>
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-sm" style="color:var(--text-muted);">
                            No agencies yet.
                        </td>
                    </tr>
                </tbody>
                @endforelse
            </table>
        </div>
    </div>

    {{-- Pricing reference — so nobody has to go digging in config to sanity-check a number --}}
    <div class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Current pricing</div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
            <div>
                <div class="font-semibold mb-1" style="color:var(--text-primary);">{{ config('corex-billing.team.label') }}</div>
                <div style="color:var(--text-secondary);">
                    {{ Zar::format(config('corex-billing.team.seat_rate')) }} per user, flat.<br>
                    Up to {{ config('corex-billing.team.max_seats') }} users.
                </div>
            </div>
            <div>
                <div class="font-semibold mb-1" style="color:var(--text-primary);">{{ config('corex-billing.agency.label') }}</div>
                <div style="color:var(--text-secondary);">
                    {{ Zar::format(config('corex-billing.agency.base_fee')) }} base, plus graduated seats:<br>
                    @foreach(config('corex-billing.agency.seat_tiers') as $tier)
                        {{ $tier['from'] }}{{ $tier['to'] ? '–' . $tier['to'] : '+' }}: {{ Zar::format($tier['rate']) }}@if(! $loop->last)<br>@endif
                    @endforeach
                </div>
            </div>
            <div>
                <div class="font-semibold mb-1" style="color:var(--text-primary);">Branches</div>
                <div style="color:var(--text-secondary);">
                    First {{ config('corex-billing.branches.included') }} included.<br>
                    {{ Zar::format(config('corex-billing.branches.rate')) }} per extra branch, on both plans.
                </div>
            </div>
        </div>
        <div class="text-xs mt-3" style="color:var(--text-muted);">
            Rates live in <code>config/corex-billing.php</code>. Both plans include full access to everything —
            the plan only decides how the price is worked out.
        </div>
    </div>

</div>
@endsection
