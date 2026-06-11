{{--
    Phase 9c (AT-16) — public privacy-policy footer link (POPIA s18 discoverability).

    Self-contained, inline-styled so it can drop into any standalone public view
    without depending on that view's CSS. Resolves the agency from (in order):
      1. an explicit $privacyFooterAgency passed by the includer,
      2. a $agency already in scope,
      3. $branch->agency if a branch is in scope,
      4. the primary live agency as a last resort.
    Links to the agency-specific canonical privacy page when a slug is known,
    else the default /privacy-policy. Never errors — always renders a link.
--}}
@php
    $pfAgency = ($privacyFooterAgency ?? null)
        ?: (isset($agency) ? $agency : null);
    if (!$pfAgency && isset($branch) && $branch) {
        $pfAgency = $branch->agency ?? null;
    }
    if (!$pfAgency) {
        $pfAgency = \App\Models\Agency::query()
            ->where('is_active', true)->where('is_demo', false)->orderBy('id')->first()
            ?? \App\Models\Agency::query()->orderBy('id')->first();
    }
    $pfUrl = ($pfAgency && $pfAgency->slug)
        ? route('public.privacy.agency', ['agencySlug' => $pfAgency->slug])
        : route('public.privacy');
@endphp
<div style="text-align:center; padding:18px 16px; font-family:'Figtree',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; font-size:12px; line-height:1.5; color:#6b7280;">
    <a href="{{ $pfUrl }}" style="color:#6b7280; text-decoration:underline;">Privacy Policy</a>
    @if($pfAgency)
        <span style="margin:0 6px;">·</span>{{ $pfAgency->trading_name ?: $pfAgency->name }}
    @endif
</div>
