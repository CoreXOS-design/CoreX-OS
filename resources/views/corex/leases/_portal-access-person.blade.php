{{-- .ai/specs/rental-portal-access.md §16 — one tenant / landlord inside the lease screen's portal access cards.
     Expects: $lease, $contact, $role ('tenant'|'landlord'). The person's login is shown as configured with its
     status, the personal link with Copy / Email / WhatsApp, and one obvious "set up and email" action when there is
     no login yet. No fields to type: the login is always the email saved on the contact. --}}
@php
    $access   = app(\App\Services\Rentals\RentalPortalAccessService::class);
    $st       = $access->status($contact, $role);
    $canAct   = auth()->user()->hasPermission('client_app.create_login');
    $flash    = session('portal_access_flash');
    $flash    = ($flash && (int) ($flash['contact_id'] ?? 0) === (int) $contact->id) ? $flash : null;
    $roles    = $access->rolesOnLease($lease, $contact);
    $phone    = trim((string) $contact->phone);
    $waDigits = $phone !== '' ? \App\Support\WhatsAppNumberFormatter::forDeepLink($phone, $contact->primaryPhone?->dial_code ?? '+27') : '';
    $waText   = rawurlencode('Hi ' . ($contact->first_name ?: 'there') . ', here is your CoreX portal link: ' . ($st['url'] ?? ''));
    $badge    = ['success' => 'ds-badge ds-badge-success', 'warning' => 'ds-badge ds-badge-warning', 'info' => 'ds-badge', 'muted' => 'ds-badge'][$st['tone']] ?? 'ds-badge';
    $post     = fn (string $action) => route('corex.leases.portal-access.' . $action, [$lease, $contact->id]);
@endphp

<div id="portal-access-{{ $contact->id }}" class="rounded-md p-3 space-y-2" style="background: var(--surface-2); border: 1px solid var(--border);"
     x-data="{ copied: false, copy(t) { navigator.clipboard.writeText(t).then(() => { this.copied = true; setTimeout(() => this.copied = false, 2000); }); } }"
     data-test="portal-access-person" data-state="{{ $st['state'] }}">
    <div class="flex items-center justify-between gap-2 flex-wrap">
        <p class="text-xs font-medium">
            {{ $contact->full_name }}
            @if(count($roles) > 1)<span class="text-[11px]" style="color: var(--text-muted);">(tenant and landlord)</span>@endif
        </p>
        <span class="{{ $badge }}" data-test="portal-access-status">{{ $st['label'] }}</span>
    </div>

    @if($st['login_email'])
        <p class="text-xs" style="color: var(--text-muted);">Login: <span style="color: var(--text-primary);">{{ $st['login_email'] }}</span></p>
    @elseif($st['contact_email'])
        <p class="text-xs" style="color: var(--text-muted);">Email: <span style="color: var(--text-primary);">{{ $st['contact_email'] }}</span></p>
    @endif

    @if($flash)
        <p class="text-xs rounded px-2 py-1" data-test="portal-access-flash" data-type="{{ $flash['type'] }}"
           style="{{ $flash['type'] === 'ok' ? 'background: color-mix(in srgb, var(--ds-green) 15%, transparent); color: var(--ds-green);' : 'background: color-mix(in srgb, var(--ds-crimson) 15%, transparent); color: var(--ds-crimson);' }}">
            {{ $flash['message'] }}
        </p>
    @endif

    @switch($st['state'])
        @case('disabled')
            <p class="text-xs" style="color: var(--text-muted);">Tenant / landlord portal access is switched off in your agency's rental portal settings, so no link is offered.</p>
            @break

        @case('no_email')
            <p class="text-xs" style="color: var(--text-muted);">There is no email address on this contact yet, and the portal login is the person's email.
                @if(auth()->user()->hasPermission('contacts.view'))
                    <a href="{{ route('corex.contacts.show', $contact) }}" target="_blank" rel="noopener" class="underline">Add an email on the contact</a>, then come back here.
                @else
                    Ask someone with access to the contact to add one.
                @endif
            </p>
            @break

        @case('not_set_up')
            @if($canAct)
                <div class="flex flex-wrap items-center gap-2">
                    <form method="POST" action="{{ $post('invite') }}">@csrf
                        <button type="submit" class="corex-btn-primary text-xs px-3 py-1.5" data-test="portal-access-setup-and-send">Set up portal access &amp; email the link</button>
                    </form>
                    <form method="POST" action="{{ $post('setup') }}">@csrf
                        <button type="submit" class="text-xs underline" style="color: var(--text-muted);" data-test="portal-access-setup-only">Set up only</button>
                    </form>
                </div>
            @else
                <p class="text-xs" style="color: var(--text-muted);">Not set up. You do not have permission to create portal access.</p>
            @endif
            @break

        @case('email_changed')
            <p class="text-xs" style="color: var(--text-muted);">
                @if($st['label'] === 'Login is a placeholder address')
                    This login uses a placeholder address that cannot receive email, but the contact now has a real one ({{ $st['contact_email'] }}).
                @else
                    The email on the contact is now <strong>{{ $st['contact_email'] }}</strong>, but the portal login is still for {{ $st['login_email'] }}.
                @endif
            </p>
            @if($canAct)
                <form method="POST" action="{{ $post('switch') }}">@csrf
                    <button type="submit" class="corex-btn-primary text-xs px-3 py-1.5" data-test="portal-access-switch">Move the login to {{ $st['contact_email'] }}</button>
                </form>
            @endif
            @break
    @endswitch

    @if($st['can_share'] && $st['url'])
        @if($st['managed_by'])
            <p class="text-xs" style="color: var(--text-muted);">This login is managed by {{ $st['managed_by'] }}.</p>
        @endif
        @if($st['state'] === 'pending')
            <p class="text-xs" style="color: var(--text-muted);">They have not chosen a password yet. The first time they open the link they confirm their email with a code.</p>
        @endif
        <div class="flex items-center gap-2 flex-wrap">
            <input type="text" readonly value="{{ $st['url'] }}" class="corex-input text-xs flex-1" style="min-width: 0;" onclick="this.select()" data-test="portal-access-link" aria-label="Portal link for {{ $contact->full_name }}">
            <button type="button" class="corex-btn-outline text-xs" @click="copy(@js($st['url']))" data-test="portal-access-copy"><span x-text="copied ? 'Copied' : 'Copy'">Copy</span></button>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            @if($canAct && ! $access->isPlaceholderEmail((string) $st['login_email']))
                <form method="POST" action="{{ $post('invite') }}">@csrf
                    <button type="submit" class="corex-btn-outline text-xs" data-test="portal-access-resend">Email link / Resend invite</button>
                </form>
            @endif
            @if($waDigits !== '')
                <a href="https://wa.me/{{ $waDigits }}?text={{ $waText }}" target="_blank" rel="noopener" class="corex-btn-outline text-xs" data-test="portal-access-whatsapp">Share on WhatsApp</a>
            @endif
        </div>
        @if(auth()->user()->hasPermission('contacts.view'))
            <p class="text-[11px]" style="color: var(--text-muted);">Reset a password, sign out devices or remove access: <a href="{{ route('corex.contacts.show', $contact) }}" target="_blank" rel="noopener" class="underline">open the contact</a>.</p>
        @endif
    @endif
</div>
