@extends('layouts.corex')

@section('corex-content')
@php
    $field = 'w-full px-3 py-2 text-sm rounded-md';
    $fieldStyle = 'border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);';
    $label = 'block text-xs font-semibold uppercase tracking-wider mb-1';
    $hint = 'text-[11px] mt-1';
    $rungs = old('inc_from') ? array_map(fn ($f, $a) => ['from' => $f, 'inc' => $a], old('inc_from'), old('inc_amount', [])) : array_map(fn ($r) => ['from' => $r[0], 'inc' => $r[1]], $values['default_increments']);
@endphp
<div class="corex-auctions w-full flex flex-col gap-4 max-w-4xl">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold" style="color:var(--text-primary);">Auction settings</h1>
        <p class="text-xs" style="color:var(--text-muted);">How your agency's auctions and public auction page behave. Anything left blank uses a sensible default.</p>
    </div>
    @if(session('status'))<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#10b981 12%,transparent);color:#10b981;">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#ef4444 12%,transparent);color:#ef4444;">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    <form method="POST" action="{{ route('corex.settings.auctions.update') }}" class="flex flex-col gap-4">
        @csrf
        <section class="rounded-md p-5 space-y-4" style="background:var(--surface);border:1px solid var(--border);">
            <h2 class="text-sm font-bold" style="color:var(--text-primary);">Your public auction page</h2>
            <p class="text-xs" style="color:var(--text-muted);">To see how it looks, and to change the banner picture and colour, use the <a href="{{ route('corex.auctions.preview') }}" class="underline">Preview page</a>.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Page title</label>
                    <input name="site_title" value="{{ old('site_title', $values['site_title']) }}" placeholder="Your agency name" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Auction reference prefix</label>
                    <input name="reference_prefix" value="{{ old('reference_prefix', $values['reference_prefix']) }}" maxlength="12" class="{{ $field }}" style="{{ $fieldStyle }}">
                    <p class="{{ $hint }}" style="color:var(--text-muted);">Auctions are numbered like AUC-2026-0001.</p></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Enquiries email</label>
                    <input type="email" name="contact_email" value="{{ old('contact_email', $values['contact_email']) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Enquiries phone</label>
                    <input name="contact_phone" value="{{ old('contact_phone', $values['contact_phone']) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div class="md:col-span-2"><label class="{{ $label }}" style="color:var(--text-secondary);">Introduction shown on the page</label>
                    <textarea name="intro_text" rows="3" class="{{ $field }}" style="{{ $fieldStyle }}">{{ old('intro_text', $values['intro_text']) }}</textarea></div>
                <div class="md:col-span-2"><label class="{{ $label }}" style="color:var(--text-secondary);">How bidding works (optional)</label>
                    <textarea name="how_it_works" rows="3" class="{{ $field }}" style="{{ $fieldStyle }}">{{ old('how_it_works', $values['how_it_works']) }}</textarea></div>
                <div class="md:col-span-2"><label class="{{ $label }}" style="color:var(--text-secondary);">Footer text (optional)</label>
                    <textarea name="footer_text" rows="2" class="{{ $field }}" style="{{ $fieldStyle }}">{{ old('footer_text', $values['footer_text']) }}</textarea></div>
                <div class="md:col-span-2"><input type="hidden" name="show_past_auctions" value="0">
                    <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="show_past_auctions" value="1" @checked(old('show_past_auctions', $values['show_past_auctions']))> Show past auctions on the page</label></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Who is told about a new enquiry</label>
                    <select name="notify_enquiry_to" class="{{ $field }}" style="{{ $fieldStyle }}">
                        @foreach(\App\Models\AgencyAuctionSettings::NOTIFY_ENQUIRY_TO as $k => $l)<option value="{{ $k }}" @selected(old('notify_enquiry_to', $values['notify_enquiry_to']) === $k)>{{ $l }}</option>@endforeach
                    </select></div>
            </div>
        </section>

        <section class="rounded-md p-5 space-y-4" style="background:var(--surface);border:1px solid var(--border);" data-fee-settings>
            <h2 class="text-sm font-bold" style="color:var(--text-primary);">Fees and payment</h2>
            <p class="text-xs" style="color:var(--text-muted);">What the winning bidder pays on top of the price, and when. Each auction can override the percentages. Leave the commission at 0 to charge none.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Buyer's commission (% of the price, before VAT)</label>
                    <input type="number" step="0.01" min="0" max="50" name="buyers_commission_percent" value="{{ old('buyers_commission_percent', $values['buyers_commission_percent']) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Minimum commission (R)</label>
                    <input type="number" step="1" min="0" name="buyers_commission_minimum" value="{{ old('buyers_commission_minimum', $values['buyers_commission_minimum']) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Deposit (% of the price)</label>
                    <input type="number" step="0.01" min="0" max="100" name="deposit_percent" value="{{ old('deposit_percent', $values['deposit_percent']) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="{{ $label }}" style="color:var(--text-secondary);">Deposit due (days)</label>
                        <input type="number" min="0" max="365" name="deposit_due_days" value="{{ old('deposit_due_days', $values['deposit_due_days']) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                    <div><label class="{{ $label }}" style="color:var(--text-secondary);">Balance due (days)</label>
                        <input type="number" min="0" max="365" name="balance_due_days" value="{{ old('balance_due_days', $values['balance_due_days']) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                </div>
                <div class="md:col-span-2"><label class="{{ $label }}" style="color:var(--text-secondary);">Payment details printed on invoices</label>
                    <textarea name="payment_details" rows="3" placeholder="Bank, account holder, account number, branch code" class="{{ $field }}" style="{{ $fieldStyle }}">{{ old('payment_details', $values['payment_details']) }}</textarea>
                    <p class="{{ $hint }}" style="color:var(--text-muted);">VAT on the commission uses the VAT rate set for your agency.</p></div>
            </div>
        </section>

        <section class="rounded-md p-5 space-y-4" style="background:var(--surface);border:1px solid var(--border);">
            <h2 class="text-sm font-bold" style="color:var(--text-primary);">Bidding</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Soft close (minutes)</label>
                    <input type="number" min="0" max="60" name="soft_close_minutes" value="{{ old('soft_close_minutes', $values['soft_close_minutes']) }}" class="{{ $field }}" style="{{ $fieldStyle }}">
                    <p class="{{ $hint }}" style="color:var(--text-muted);">A bid in the last few minutes extends the closing time by the same amount, so nobody is sniped at the buzzer.</p></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">What buyers see about the reserve</label>
                    <select name="reserve_visibility" class="{{ $field }}" style="{{ $fieldStyle }}">
                        @foreach(\App\Models\AgencyAuctionSettings::RESERVE_VISIBILITY as $k => $l)<option value="{{ $k }}" @selected(old('reserve_visibility', $values['reserve_visibility']) === $k)>{{ $l }}</option>@endforeach
                    </select>
                    <p class="{{ $hint }}" style="color:var(--text-muted);">The reserve amount itself is never shown to buyers.</p></div>
                <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="show_bid_count" value="1" @checked(old('show_bid_count', $values['show_bid_count']))> Show how many bids a lot has</label>
                <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="show_bidder_numbers" value="1" @checked(old('show_bidder_numbers', $values['show_bidder_numbers']))> Show bidder numbers to other bidders</label>
                <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="outbid_emails" value="1" @checked(old('outbid_emails', $values['outbid_emails']))> Email a bidder when they are outbid</label>
            </div>
            <div x-data="{ rows: {{ json_encode($rungs) }} }" class="space-y-2">
                <label class="{{ $label }}" style="color:var(--text-secondary);">Default bid increments for new auctions</label>
                <template x-for="(r, i) in rows" :key="i">
                    <div class="flex items-center gap-2"><span class="text-xs" style="color:var(--text-muted);">From R</span>
                        <input type="number" min="0" name="inc_from[]" x-model="r.from" class="px-3 py-2 text-sm rounded-md w-40" style="{{ $fieldStyle }}">
                        <span class="text-xs" style="color:var(--text-muted);">goes up by R</span>
                        <input type="number" min="1" name="inc_amount[]" x-model="r.inc" class="px-3 py-2 text-sm rounded-md w-40" style="{{ $fieldStyle }}">
                        <button type="button" @click="rows.splice(i, 1)" class="text-xs underline" style="color:var(--text-muted);">Remove</button></div>
                </template>
                <button type="button" @click="rows.push({from: '', inc: ''})" class="text-xs underline" style="color:var(--text-muted);">+ Add a line</button>
            </div>
        </section>

        <section class="rounded-md p-5 space-y-4" style="background:var(--surface);border:1px solid var(--border);">
            <h2 class="text-sm font-bold" style="color:var(--text-primary);">Bidder registration</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Days a seller has to confirm a below-reserve sale</label>
                    <input type="number" min="1" max="60" name="confirmation_days" value="{{ old('confirmation_days', $values['confirmation_days']) }}" class="{{ $field }}" style="{{ $fieldStyle }}">
                    <p class="{{ $hint }}" style="color:var(--text-muted);">When the highest bid is under the reserve, the seller decides within this time. It appears on the calendar.</p></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Registration closes (hours before the auction)</label>
                    <input type="number" min="0" max="720" name="registration_closes_hours" value="{{ old('registration_closes_hours', $values['registration_closes_hours']) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                @foreach(['docs_required_individual' => 'Documents required from an individual', 'docs_required_company' => 'Documents required from a company'] as $key => $title)
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">{{ $title }}</label>
                    @foreach(\App\Models\AgencyAuctionSettings::DOCUMENT_KINDS as $k => $l)
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="{{ $key }}[]" value="{{ $k }}" @checked(in_array($k, (array) old($key, $values[$key]), true))> {{ $l }}</label>
                    @endforeach</div>
                @endforeach
                <div class="md:col-span-2"><label class="{{ $label }}" style="color:var(--text-secondary);">Terms shown to a buyer when they register</label>
                    <textarea name="terms_text" rows="4" class="{{ $field }}" style="{{ $fieldStyle }}">{{ old('terms_text', $values['terms_text']) }}</textarea></div>
            </div>
        </section>
        <div><button type="submit" class="corex-btn-primary">Save settings</button></div>
    </form>
</div>
@endsection
