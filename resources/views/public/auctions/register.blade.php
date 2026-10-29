@extends('public.auctions._layout')
@section('title', 'Register to bid')
@section('content')
<div class="narrow" style="max-width:640px;">
    <p class="small"><a href="{{ route('public.auctions.auction', [$agency->slug, $auction->id]) }}">&larr; {{ $auction->title }}</a></p>
    <div class="panel">
        <h1 style="margin-top:0;">Register to bid</h1>
        <p class="muted small">{{ $auction->title }} · {{ $auction->reference }}@if($deadline) · registration closes {{ $deadline->format('j M Y, H:i') }}@endif</p>

        @if($registration && $registration->status === 'approved')
            <div class="alert ok">You are approved to bid in this auction. Your bidder number is <strong>#{{ $registration->bidder_number }}</strong>.</div>
        @elseif(! $isOpen)
            <div class="alert err">Registration for this auction is closed.</div>
        @else
            @if($registration && $registration->status === 'pending')
                <div class="alert ok">Your registration is waiting for approval. You can change your details below and send it again.</div>
            @elseif($registration && $registration->status === 'declined')
                <div class="alert err">Your registration was not approved.@if($registration->declined_reason) {{ $registration->declined_reason }}@endif Please update it below.</div>
            @endif

            <form method="POST" action="{{ route('public.auctions.register.submit', [$agency->slug, $auction->id]) }}" enctype="multipart/form-data" class="stack">
                @csrf
                <label>Are you bidding as</label>
                <div style="display:flex;gap:18px;">
                    <label style="font-weight:400;"><input type="radio" name="bidder_type" value="individual" @checked($type === 'individual') onchange="setType(this.value)"> An individual</label>
                    <label style="font-weight:400;"><input type="radio" name="bidder_type" value="company" @checked($type === 'company') onchange="setType(this.value)"> A company</label>
                </div>

                <div id="company-box" style="{{ $type === 'company' ? '' : 'display:none' }}">
                    <label for="company_name">Company name</label>
                    <input id="company_name" type="text" name="company_name" value="{{ old('company_name', $registration?->company_name) }}" maxlength="190">
                    <label for="company_reg_no">Registration number</label>
                    <input id="company_reg_no" type="text" name="company_reg_no" value="{{ old('company_reg_no', $registration?->company_reg_no) }}" maxlength="60">
                </div>

                <h2>Your documents</h2>
                <p class="muted small" style="margin-top:0;">The agency needs these to approve you (FICA). Upload a PDF, JPG or PNG, or tick &ldquo;use the one on file&rdquo; if you sent it before.</p>
                @foreach(['individual' => $requiredIndividual, 'company' => $requiredCompany] as $btype => $required)
                    @foreach($required as $kind)
                        @php $files = $onFile->get($kind, collect()); $show = ($type === $btype); @endphp
                        <div data-req="{{ $btype }}" style="{{ $show ? '' : 'display:none' }};border-top:1px solid var(--line);padding-top:6px;">
                            <label>{{ $kinds[$kind] ?? $kind }}</label>
                            @if($files->isNotEmpty())
                                <label style="font-weight:400;"><input type="radio" name="existing[{{ $kind }}]" value="{{ $files->first()->id }}" checked> Use the one on file ({{ $files->first()->original_name }}, added {{ $files->first()->created_at->format('j M Y') }})</label>
                                <label style="font-weight:400;"><input type="radio" name="existing[{{ $kind }}]" value=""> Upload a new one</label>
                            @endif
                            <input type="file" name="documents[{{ $kind }}]" accept=".pdf,.jpg,.jpeg,.png">
                        </div>
                    @endforeach
                @endforeach

                <h2>Conditions</h2>
                @if($documents->isNotEmpty())
                    <ul class="docs">@foreach($documents as $d)<li><a href="{{ route('public.auctions.auction.document', [$agency->slug, $auction->id, $d->id]) }}" target="_blank">{{ $d->name }}</a></li>@endforeach</ul>
                @endif
                @if($terms !== '')<div class="small" style="white-space:pre-line;max-height:160px;overflow:auto;border:1px solid var(--line);border-radius:8px;padding:10px;margin-top:8px;">{{ $terms }}</div>@endif
                <label style="font-weight:400;display:flex;gap:8px;align-items:flex-start;margin-top:14px;">
                    <input type="checkbox" name="accept_conditions" value="1" required style="margin-top:4px;">
                    <span class="small">I have read and accept the conditions of this auction, and I agree that {{ $branding['name'] }} may verify my documents.</span>
                </label>
                <p style="margin-top:16px;"><button class="btn" type="submit">{{ $registration ? 'Send my registration again' : 'Submit registration' }}</button></p>
            </form>
        @endif
    </div>
</div>
<script>
    // Only the documents for the chosen bidder type are shown AND submitted (the two lists share some kinds).
    function setType(t) {
        document.getElementById('company-box').style.display = t === 'company' ? '' : 'none';
        document.querySelectorAll('[data-req]').forEach(function (sec) {
            var on = sec.dataset.req === t;
            sec.style.display = on ? '' : 'none';
            sec.querySelectorAll('input').forEach(function (i) { i.disabled = !on; });
        });
    }
    setType(document.querySelector('input[name=bidder_type]:checked') ? document.querySelector('input[name=bidder_type]:checked').value : 'individual');
</script>
@endsection
