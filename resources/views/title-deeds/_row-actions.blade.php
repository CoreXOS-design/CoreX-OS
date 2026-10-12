{{--
    AT-450 round 2 (item 6) - the compact actions group at the right of a list row. Only what is valid for the row's status and
    the user's permissions; icons with tooltips (no extra row height, no badges). Every action posts to the SAME route the record page
    uses (same one-time token, same audit); state-changing ones go through the shared dialog and come back to this list.
    Variables: $d (TitleDeed), $can, $pinRequired, $token (closure purpose,deedId -> one-time token).
--}}
@php
    $open = ! $d->trashed() && ! $d->isCollected();
    $pinIssued = $d->pin_version > 0 && $d->pin_encrypted !== null;
    $pinUsable = $pinRequired && $pinIssued && $d->pin_locked_at === null;
    $btn = 'inline-flex items-center justify-center w-7 h-7 rounded-md';
    $btnStyle = 'border: 1px solid var(--border); color: var(--text-secondary); background: var(--surface-2);';
    $name = $d->property_display;
    // Where a one-channel notice goes: a deed already notified with a usable PIN is a RESEND of the same PIN; otherwise the first notice.
    $oneChannelRoute = $pinUsable && $d->status === 'notified' ? route('title-deeds.pin.resend', $d->id) : route('title-deeds.notify', $d->id);
    $oneChannelPurpose = $pinUsable && $d->status === 'notified' ? 'pin_resend' : 'notify';
@endphp
<div class="inline-flex items-center gap-1">
    <a href="{{ route('title-deeds.show', $d->id) }}" class="{{ $btn }}" style="{{ $btnStyle }}" title="Open" aria-label="Open {{ $name }}">
        <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
    </a>

    @if($open && $can['notify'])
        @if($d->status === 'received')
        <button type="button" class="{{ $btn }}" style="{{ $btnStyle }}" title="Notify owners" aria-label="Notify the owners of {{ $name }}"
                @click="openAsk({ url: @js(route('title-deeds.notify', $d->id)), token: @js($token('notify', $d->id)), channel: null, title: 'Notify owners', message: @js('Email every owner and prepare their WhatsApp for ' . $name . '?'), label: 'Notify owners' })">
            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/></svg>
        </button>
        @endif

        @if($d->status === 'notified' && $pinUsable)
        <button type="button" class="{{ $btn }}" style="{{ $btnStyle }}" title="Resend PIN" aria-label="Resend the PIN for {{ $name }}"
                @click="openAsk({ url: @js(route('title-deeds.pin.resend', $d->id)), token: @js($token('pin_resend', $d->id)), channel: null, title: 'Resend PIN', message: @js('Send the same PIN again to every owner of ' . $name . '?'), label: 'Resend PIN' })">
            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
        </button>
        @endif

        @if($pinRequired)
        <button type="button" class="{{ $btn }}" style="{{ $btnStyle }}" title="New PIN" aria-label="Issue a new PIN for {{ $name }}"
                @click="openAsk({ url: @js(route('title-deeds.pin.new', $d->id)), token: @js($token('pin_new', $d->id)), channel: null, title: 'Issue a new PIN', message: 'The old PIN will stop working and the new one will be sent to every owner. Continue?', label: 'Issue new PIN' })">
            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z"/></svg>
        </button>
        @endif

        <button type="button" class="{{ $btn }}" style="{{ $btnStyle }}" title="Email owner" aria-label="Email the owners of {{ $name }}"
                @click="openAsk({ url: @js($oneChannelRoute), token: @js($token($oneChannelPurpose, $d->id)), channel: 'email', title: 'Email owner', message: @js('Email the owners of ' . $name . ' (to the address stored on each contact)?'), label: 'Send email' })">
            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
        </button>

        <button type="button" class="{{ $btn }}" style="{{ $btnStyle }}" title="WhatsApp owner" aria-label="Prepare WhatsApp for the owners of {{ $name }}"
                @click="openAsk({ url: @js($oneChannelRoute), token: @js($token($oneChannelPurpose, $d->id)), channel: 'whatsapp', title: 'WhatsApp owner', message: @js('Prepare the WhatsApp message for the owners of ' . $name . '? You then press "Open WhatsApp and send" and mark it as sent.'), label: 'Prepare WhatsApp' })">
            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 0 1 1.037-.443 48.282 48.282 0 0 0 5.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z"/></svg>
        </button>
    @endif

    @if($open && $can['handover'])
    <a href="{{ route('title-deeds.handover', $d->id) }}" class="{{ $btn }}" style="{{ $btnStyle }}" title="Hand over" aria-label="Hand over {{ $name }}">
        <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
    </a>
    @endif
</div>
