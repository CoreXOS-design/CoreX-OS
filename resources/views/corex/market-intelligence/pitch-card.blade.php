{{-- Road to Sold 23.23: ONE property's MIC Adverts card on its own page, for the pitch popup's Property step and the clickable header on the pitch screens.
     Chrome-less in embed mode. Same partials and data as the Adverts list (MarketIntelligenceController::work with _pitch_card), nothing added to the card. --}}
@extends('layouts.corex-app')

@section('corex-content')
{{-- inside the pitch popup the card is for LOOKING at the property: the card's own action buttons (Pitch now, Claim, Comment, Link contact, Intel) are the
     popup's job now (its Next button), so they are hidden here (23.23 drop 6) --}}
<style>[data-qa="pitch-card"] [data-qa="line-buttons"] { display: none !important; }</style>
<div data-qa="pitch-card" style="width: 100%; max-width: 1200px; margin: 0 auto; padding: 12px 8px;">
    @unless(request()->boolean('peek'))<h1 class="text-base font-bold mb-2" style="color: var(--text-primary);" data-qa="step-heading">Property</h1>@endunless
    @include('corex.market-intelligence._listings')
</div>
@include('corex.market-intelligence._advert-fetch-script')
@include('corex.market-intelligence._work-card-scripts')
@endsection
