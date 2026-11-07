{{-- vars: $agency, $auction, $lot (nullable), $buyer (nullable) --}}
@php $action = $lot ? route('public.auctions.enquire.lot', [$agency->slug, $auction->id, $lot->id]) : route('public.auctions.enquire', [$agency->slug, $auction->id]); @endphp
<div class="panel" id="enquire">
    <h2 style="margin-top:0;">Make an enquiry</h2>
    <p class="muted small" style="margin-top:0;">Ask about {{ $lot ? 'this lot' : 'this auction' }} and the agent will be in touch.</p>
    <form method="POST" action="{{ $action }}" class="stack">
        @csrf
        <label for="enq-name">Your name</label>
        <input id="enq-name" type="text" name="name" value="{{ old('name') }}" required maxlength="190" autocomplete="name">
        <label for="enq-email">Email</label>
        <input id="enq-email" type="email" name="email" value="{{ old('email', $buyer?->email) }}" maxlength="190" autocomplete="email">
        <label for="enq-phone">Phone</label>
        <input id="enq-phone" type="tel" name="phone" value="{{ old('phone') }}" maxlength="40" autocomplete="tel">
        <label for="enq-msg">Message</label>
        <textarea id="enq-msg" name="message" rows="4" maxlength="2000">{{ old('message') }}</textarea>
        <label style="font-weight:400;display:flex;gap:8px;align-items:flex-start;margin-top:14px;">
            <input type="checkbox" name="popia_consent" value="1" required style="margin-top:4px;">
            <span class="small">I agree that {{ $branding['name'] }} may use these details to answer my enquiry and contact me about this auction.</span>
        </label>
        <p style="margin-top:14px;"><button type="submit" class="btn">Send enquiry</button></p>
    </form>
</div>
