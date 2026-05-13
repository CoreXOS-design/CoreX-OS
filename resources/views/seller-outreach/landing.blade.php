@extends('layouts.public')

@section('title', $ld->agencyName)

@section('public-content')

{{-- Agency header --}}
<div class="text-center mb-6">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">
        {{ $ld->agencyName }}
    </h1>
    <p class="text-xs" style="color: var(--text-muted);">
        {{ $ld->agencyBlurb }}
    </p>
</div>

{{-- Mode-specific content --}}
@if($ld->mode === \App\Support\SellerOutreach\LandingPageData::MODE_ACTIVE)
    @include('seller-outreach._landing-active', ['ld' => $ld])
@elseif($ld->mode === \App\Support\SellerOutreach\LandingPageData::MODE_GENERIC)
    @include('seller-outreach._landing-generic', ['ld' => $ld])
@else
    @include('seller-outreach._landing-agent-unavailable', ['ld' => $ld])
@endif

{{-- Callback form (all modes) --}}
<div class="mt-8 p-4 rounded-md"
     style="background: var(--surface, #ffffff); border: 1px solid var(--border, #e5e7eb);">

    <h3 class="text-base font-semibold mb-2" style="color: var(--text-primary);">
        Request a callback
    </h3>
    <p class="text-xs mb-4" style="color: var(--text-secondary, #6b7280);">
        Leave your details and we'll get in touch when it suits you.
    </p>

    @if(session('callback_status'))
        <div class="p-3 rounded-md text-sm mb-3"
             style="background: color-mix(in srgb, var(--ds-green, #10b981) 12%, transparent);
                    border: 1px solid var(--ds-green, #10b981); color: var(--ds-green, #047857);">
            {{ session('callback_status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="p-3 rounded-md text-sm mb-3"
             style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 12%, transparent);
                    border: 1px solid var(--ds-crimson, #dc2626); color: var(--ds-crimson, #b91c1c);">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('seller-outreach.public.callback', $ld->send->tracking_short_code) }}">
        @csrf
        <div class="space-y-3">
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Your name</label>
                <input type="text" name="requester_name" value="{{ old('requester_name') }}" maxlength="150"
                       class="w-full px-3 py-2 text-sm rounded"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Phone</label>
                    <input type="tel" name="requester_phone" value="{{ old('requester_phone') }}" maxlength="30" placeholder="082 123 4567"
                           class="w-full px-3 py-2 text-sm rounded"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Email</label>
                    <input type="email" name="requester_email" value="{{ old('requester_email') }}" maxlength="255"
                           class="w-full px-3 py-2 text-sm rounded"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Best time to call</label>
                <input type="text" name="preferred_time" value="{{ old('preferred_time') }}" maxlength="100" placeholder="e.g. weekday mornings"
                       class="w-full px-3 py-2 text-sm rounded"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Anything else?</label>
                <textarea name="message" maxlength="2000" rows="3"
                          class="w-full px-3 py-2 text-sm rounded"
                          style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">{{ old('message') }}</textarea>
            </div>
            <button type="submit"
                    class="w-full sm:w-auto px-6 py-2.5 text-sm font-semibold rounded"
                    style="background: var(--brand-button); color: #fff;">
                Send callback request
            </button>
        </div>
    </form>
</div>

<div class="mt-6 text-center text-xs" style="color: var(--text-muted);">
    Powered by CoreX · {{ now()->format('Y') }}
</div>

@endsection
