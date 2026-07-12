{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    The universal demo connector — minted HERE (on live), pasted into the demo.
    Owner-only. Spec: .ai/specs/demo-access-control.md §5.1
--}}
@extends('layouts.corex')

@section('title', 'Demo connection')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Back link --}}
    <a href="{{ route('admin.demo-access.index') }}"
       class="inline-flex items-center gap-1.5 text-sm no-underline"
       style="color:var(--text-secondary);">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/>
        </svg>
        Back to Demo Access
    </a>

    {{-- Page header — §2.4 Pattern A --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">Demo connection</h1>
        <p class="text-sm text-white/60">
            One token, for the one demo. The demo site uses it to ask this system whether a
            prospect's access code is real — so grants, terms and telemetry all live here,
            and survive the demo's 3-day wipe.
        </p>
    </div>

    @if (session('status'))
        <div role="status" class="rounded-md px-4 py-3 text-sm font-medium max-w-4xl"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" class="rounded-md px-4 py-3 text-sm max-w-4xl"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- THE ONLY TIME THE TOKEN EXISTS IN READABLE FORM.
         We store sha256 of it. There is no "show token" button and there cannot be. --}}
    @if ($plainToken)
        <div role="status" class="rounded-md px-4 py-4 text-sm max-w-4xl"
             x-data="{ copied: false }"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <p class="font-semibold mb-2">Copy this token now and paste it into the demo.</p>

            <p x-ref="token" class="font-mono text-xs break-all rounded-md px-3 py-2.5 mb-3"
               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ $plainToken }}</p>

            <button type="button" class="corex-btn-primary text-sm"
                    @click="navigator.clipboard.writeText($refs.token.textContent.trim()); copied = true; setTimeout(() => copied = false, 2000)">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184"/>
                </svg>
                <span x-text="copied ? 'Copied' : 'Copy token'">Copy token</span>
            </button>

            <p class="mt-3 text-xs" style="color: var(--text-secondary);">
                <strong>This will not be shown again.</strong> Only a hash of it is stored, so it
                cannot be looked up later. If you lose it, issue a new one — which replaces this
                one, and the demo will stop working until you paste the new token in.
            </p>
        </div>
    @endif

    {{-- Current connector — §3.3 card. --}}
    <div class="rounded-md p-5 max-w-4xl" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Current connector</h2>

        @if ($connector)
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                <div>
                    <div class="text-xs font-medium mb-1" style="color: var(--text-secondary);">Name</div>
                    <div class="text-sm break-all" style="color: var(--text-primary);">{{ $connector->name }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium mb-1" style="color: var(--text-secondary);">Token ID</div>
                    <div class="text-sm font-mono break-all" style="color: var(--text-primary);">{{ $connector->key_prefix }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium mb-1" style="color: var(--text-secondary);">Issued</div>
                    <div class="text-sm" style="color: var(--text-primary);">{{ $connector->created_at->format('j M Y, H:i') }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium mb-1" style="color: var(--text-secondary);">Last used</div>
                    <div class="text-sm" style="color: var(--text-primary);">
                        {{ $connector->last_used_at?->diffForHumans() ?? 'Never' }}
                    </div>
                </div>
            </div>

            {{-- "Last used: Never" is the single most useful diagnostic on this page.
                 It distinguishes "the demo is not calling us" (wrong URL, wrong token,
                 role not flipped) from "the demo is calling and being refused".
                 §3.9 warning alert — needs attention, but not a danger state. --}}
            @unless ($connector->last_used_at)
                <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3 mb-4"
                     style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent);
                            border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);
                            color: var(--text-primary);">
                    <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-amber, #f59e0b);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
                    </svg>
                    <div class="flex-1">
                        <strong>The demo has never used this token.</strong> Either it has not been pasted in yet, or the
                        demo cannot reach this address. Check the demo's own <em>Demo Connection</em> page
                        and press <em>Test connection</em> there.
                    </div>
                </div>
            @endunless

            <form method="POST" action="{{ route('admin.demo-access.connection.revoke') }}"
                  onsubmit="return confirm('Revoke this connector?\n\nThe demo will immediately lose access to CoreX. Because the demo gate fails closed, NOBODY will be able to sign in to the demo until you issue a new token and paste it in.\n\nDo this if the token has leaked. Do not do it to “reset” anything.');">
                @csrf
                <button type="submit" class="corex-btn-danger text-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636"/>
                    </svg>
                    Revoke connector
                </button>
            </form>
        @else
            {{-- §3.10 empty state. The CTA is the "Issue a connector" form directly below,
                 so this names the consequence and points at it. --}}
            <div class="rounded-md py-8 px-6 text-center" style="background: var(--surface-2); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); color: var(--ds-crimson, #c41e3a);">
                    <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244"/>
                    </svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No connector</h3>
                <p class="text-sm" style="color: var(--text-muted);">
                    The demo cannot reach this system, so nobody can sign in to the demo — the gate
                    fails closed by design. Issue one below.
                </p>
            </div>
        @endif
    </div>

    {{-- Mint / rotate — §3.3 card. --}}
    <div class="rounded-md p-5 max-w-4xl" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-lg font-semibold mb-1" style="color: var(--text-primary);">
            {{ $connector ? 'Replace the connector' : 'Issue a connector' }}
        </h2>
        <p class="text-sm mb-4" style="color: var(--text-muted);">
            @if ($connector)
                Issuing a new token <strong>immediately revokes the current one</strong> — there is only
                ever one. The demo will stop working until you paste the new token into it. That is
                deliberate: rotating a leaked credential that kept working would achieve nothing.
            @else
                Issue the token, then paste it into the demo's <em>Demo Connection</em> page along with
                this system's address.
            @endif
        </p>

        <form method="POST" action="{{ route('admin.demo-access.connection.mint') }}"
              @if ($connector)
                  onsubmit="return confirm('Issue a new token?\n\nThis REVOKES the current one immediately. The demo will stop working until you paste the new token into it.');"
              @endif
              class="flex flex-wrap items-end gap-3">
            @csrf

            <div class="flex-1 min-w-[240px]">
                <label for="name" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                    Label
                </label>
                <input id="name" name="name" type="text" value="{{ old('name', 'CoreX Demo Host') }}" maxlength="100"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>

            <button type="submit" class="corex-btn-primary text-sm">
                {{ $connector ? 'Replace token' : 'Issue token' }}
            </button>
        </form>
    </div>

    {{-- What to paste into the demo --}}
    <div class="rounded-md p-5 max-w-4xl" style="background: var(--surface-2); border: 1px solid var(--border);">
        <h2 class="text-lg font-semibold mb-2" style="color: var(--text-primary);">On the demo, paste this address</h2>

        <p class="font-mono text-sm break-all rounded-md px-3 py-2.5"
           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ $apiBase }}</p>

        <p class="mt-3 text-sm" style="color: var(--text-muted);">
            Sign in to the demo as a System Owner, go to <strong>Dev Settings → Demo Connection</strong>,
            paste that address and the token, then press <strong>Test connection</strong>.
        </p>
    </div>

    {{-- Rotation history — the table doubles as the audit trail, because rotation is
         insert-and-revoke rather than update. §3.7. --}}
    @if ($history->count() > 1)
        <div class="space-y-3 max-w-4xl">
            <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Previous connectors</h2>

            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm ds-table">
                        <thead>
                            <tr style="background: var(--surface-2);">
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Token ID</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Label</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Issued</th>
                                <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($history as $h)
                            <tr>
                                <td class="px-4 py-3 font-mono" style="color: var(--text-primary);">{{ $h->key_prefix }}</td>
                                <td class="px-4 py-3" style="color: var(--text-secondary);">{{ $h->name }}</td>
                                <td class="px-4 py-3" style="color: var(--text-secondary);">
                                    {{ $h->created_at->format('j M Y') }}
                                    @if ($h->creator) · {{ $h->creator->name }} @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if ($h->isActive())
                                        <span class="ds-badge ds-badge-success">Active</span>
                                    @else
                                        <span class="ds-badge ds-badge-muted"
                                              title="Revoked {{ $h->revoked_at->format('j M Y') }}">Revoked</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
