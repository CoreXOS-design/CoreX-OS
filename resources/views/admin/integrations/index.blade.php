@extends('layouts.corex')

@section('corex-content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6"
     x-data="{ copied: null, copy(v, k) { navigator.clipboard.writeText(v); this.copied = k; setTimeout(() => this.copied = null, 1500); } }">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">Integrations</h1>
        <p class="text-sm text-white/60">Connect CoreX to external platforms. Configure each provider's app, then connect accounts.</p>
    </div>

    {{-- Meta (Facebook & Instagram) --}}
    <div class="rounded-md p-6 space-y-5" style="background: var(--surface); border: 1px solid var(--border);">

        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold" style="color: var(--text-primary);">Meta — Facebook &amp; Instagram</h2>
                <p class="text-sm" style="color: var(--text-muted);">Lets agents connect a Facebook Page / Instagram Business account and publish property ads from CoreX.</p>
            </div>
            @if($metaConfigured)
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs font-semibold flex-shrink-0"
                      style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">
                    ● Configured
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs font-semibold flex-shrink-0"
                      style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 15%, transparent); color: var(--ds-amber, #f59e0b);">
                    ● Not configured
                </span>
            @endif
        </div>

        @unless($metaConfigured)
            <div class="rounded-md px-4 py-3 text-sm"
                 style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 30%, transparent);
                        color: var(--text-primary);">
                Set <code>META_APP_ID</code>, <code>META_APP_SECRET</code> and <code>META_REDIRECT_URI</code> in this
                server's <code>.env</code> file, then run <code>php artisan config:clear</code>.
            </div>
        @endunless

        @unless($redirectIsHttps)
            <div class="rounded-md px-4 py-3 text-sm"
                 style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                        color: var(--text-primary);">
                <strong>Redirect URI is not HTTPS.</strong> Facebook rejects non-secure redirects
                ("isn't using a secure connection"). Set <code>META_REDIRECT_URI</code> to an
                <code>https://</code> URL.
            </div>
        @endunless

        {{-- Values to paste into the Facebook App dashboard --}}
        <div class="space-y-1">
            <p class="text-sm font-semibold" style="color: var(--text-primary);">Paste these into your Facebook App</p>
            <p class="text-xs" style="color: var(--text-muted);">developers.facebook.com → your app. Each value must match exactly.</p>
        </div>

        <div class="space-y-4">
            @php
                $rows = [
                    ['App Domains (Settings → Basic)', $appDomain, 'domain'],
                    ['Valid OAuth Redirect URI (Facebook Login → Settings)', $redirectUri, 'redirect'],
                    ['Privacy Policy URL (Settings → Basic)', $privacyUrl, 'privacy'],
                    ['Data Deletion Instructions URL (Settings → Basic)', $dataDeletionUrl, 'deletion'],
                ];
            @endphp
            @foreach($rows as [$label, $value, $key])
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-muted);">{{ $label }}</label>
                    <div class="flex items-stretch gap-2">
                        <input type="text" readonly value="{{ $value }}"
                               class="flex-1 px-3 py-2 rounded-md text-sm font-mono"
                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                               onclick="this.select()">
                        <button type="button" @click="copy(@js($value), '{{ $key }}')"
                                class="px-3 py-2 rounded-md text-xs font-semibold whitespace-nowrap"
                                style="background: var(--brand-button, #0ea5e9); color: #fff;">
                            <span x-show="copied !== '{{ $key }}'">Copy</span>
                            <span x-show="copied === '{{ $key }}'" x-cloak>Copied</span>
                        </button>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Live legal pages --}}
        <div class="pt-2 flex flex-wrap gap-3 text-sm">
            <a href="{{ $privacyUrl }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1.5 font-medium" style="color: var(--brand-icon, #0ea5e9);">
                View Privacy Policy ↗
            </a>
            <a href="{{ $dataDeletionUrl }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1.5 font-medium" style="color: var(--brand-icon, #0ea5e9);">
                View Data Deletion page ↗
            </a>
        </div>

        {{-- App Review note --}}
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted);">
            <strong style="color: var(--text-primary);">Before other agencies can connect:</strong>
            while the Facebook app is in <em>Development</em> mode, only people added under
            App Roles → Roles (Admin / Developer / Tester) can connect. To open it to all agents,
            the app must be set to <em>Live</em> and pass App Review for the
            <code>pages_manage_posts</code>, <code>pages_show_list</code>, <code>pages_read_engagement</code>
            and <code>read_insights</code> permissions (requires Business Verification).
        </div>
    </div>

</div>
@endsection
