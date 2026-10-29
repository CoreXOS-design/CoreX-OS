<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>{{ $agencyName }}</title></head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;"><tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;">
<tr><td align="center" style="padding-bottom:20px;">@if($logoUrl)<img src="{{ $logoUrl }}" alt="{{ $agencyName }}" style="max-height:56px;max-width:220px;">@else<strong style="font-size:1.2rem;color:#111827;">{{ $agencyName }}</strong>@endif</td></tr>
<tr><td style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:32px;">
<p style="margin:0 0 14px;font-size:1rem;color:#111827;">Hi {{ $toName }},</p>

@if($kind === 'approved')
<h1 style="margin:0 0 10px;font-size:1.25rem;color:#111827;">You are approved to bid</h1>
<p style="margin:0 0 14px;color:#374151;">Your registration for <strong>{{ $auction->title }}</strong> was approved.@if(!empty($facts['bidder_number'])) Your bidder number is <strong>{{ $facts['bidder_number'] }}</strong>.@endif</p>
@elseif($kind === 'declined')
<h1 style="margin:0 0 10px;font-size:1.25rem;color:#111827;">We could not approve your registration</h1>
<p style="margin:0 0 14px;color:#374151;">Your registration for <strong>{{ $auction->title }}</strong> was not approved.</p>
@if(!empty($facts['reason']))<p style="margin:0 0 14px;padding:12px;background:#f9fafb;border-radius:8px;color:#111827;">{{ $facts['reason'] }}</p>@endif
<p style="margin:0 0 14px;color:#374151;">You can fix this and register again from the auction page.</p>
@elseif($kind === 'outbid')
<h1 style="margin:0 0 10px;font-size:1.25rem;color:#111827;">You have been outbid</h1>
<p style="margin:0 0 14px;color:#374151;">Someone bid higher on <strong>Lot {{ $lot?->lot_number }} — {{ $lot?->displayTitle() }}</strong>.@if(!empty($facts['amount'])) The current bid is <strong>{{ $facts['amount'] }}</strong>.@endif</p>
@elseif($kind === 'won')
<h1 style="margin:0 0 10px;font-size:1.25rem;color:#111827;">Congratulations, you won</h1>
<p style="margin:0 0 14px;color:#374151;">You are the winning bidder on <strong>Lot {{ $lot?->lot_number }} — {{ $lot?->displayTitle() }}</strong>@if(!empty($facts['amount'])) at <strong>{{ $facts['amount'] }}</strong>@endif. {{ $agencyName }} will be in touch about the next steps.</p>
@elseif($kind === 'not_accepted')
<h1 style="margin:0 0 10px;font-size:1.25rem;color:#111827;">The seller did not accept the highest bid</h1>
<p style="margin:0 0 14px;color:#374151;">Your bid {!! !empty($facts['amount']) ? 'of <strong>' . e($facts['amount']) . '</strong> ' : '' !!}on <strong>Lot {{ $lot?->lot_number }} — {{ $lot?->displayTitle() }}</strong> was the highest, but it was below the reserve and the seller chose not to accept it. The lot has been passed in. Thank you for bidding.</p>
@elseif($kind === 'subject_to_confirmation')
<h1 style="margin:0 0 10px;font-size:1.25rem;color:#111827;">You are the highest bidder</h1>
<p style="margin:0 0 14px;color:#374151;">Your bid {!! !empty($facts['amount']) ? 'of <strong>' . e($facts['amount']) . '</strong> ' : '' !!}on <strong>Lot {{ $lot?->lot_number }} — {{ $lot?->displayTitle() }}</strong> is the highest, but it is below the seller's reserve, so the sale is subject to the seller's confirmation. {{ $agencyName }} will let you know.</p>
@else
<h1 style="margin:0 0 10px;font-size:1.25rem;color:#111827;">Bidding has ended</h1>
<p style="margin:0 0 14px;color:#374151;">Bidding on <strong>Lot {{ $lot?->lot_number }} — {{ $lot?->displayTitle() }}</strong> has ended and your bid was not the highest. Thank you for taking part.</p>
@endif

<p style="margin:20px 0 0;"><a href="{{ $url }}" style="display:inline-block;background:#0b2a4a;color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;">Open the auction page</a></p>
</td></tr></table>
</td></tr></table>
</body>
</html>
