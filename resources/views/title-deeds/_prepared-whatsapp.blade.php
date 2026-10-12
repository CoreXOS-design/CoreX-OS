{{--
    WhatsApp messages CoreX has prepared but cannot send itself - ONE honest sequence (spec section 14.7):
      1. "Open WhatsApp and send" - records who opened it and when, and opens WhatsApp on the stored number with the message ready.
      2. Only AFTER that click: "Mark as sent" (or "Not sent"). Both are refused by the server for a message nobody opened.
    $prepared (TitleDeedEvent rows: status prepared | opened), optional $showProperty (the list shows the property too). After the open click the page reloads so the next control shows.
--}}
@if($prepared->isNotEmpty())
<div class="rounded-md p-4 space-y-2" style="background: var(--surface); border: 1px solid var(--border);">
    <div class="text-sm font-semibold" style="color: var(--text-primary);">WhatsApp waiting to be sent</div>
    @foreach($prepared as $ev)
    <div class="flex flex-wrap items-center justify-between gap-2 py-1" style="border-top: 1px solid var(--border);">
        <div class="text-sm" style="color: var(--text-primary);">
            @if(! empty($showProperty))<a href="{{ route('title-deeds.show', $ev->title_deed_id) }}" class="font-medium no-underline hover:underline">{{ $ev->deed?->property_display }}</a> &mdash; @endif{{ $ev->owner_name ?: 'Owner' }}
            <span class="text-xs" style="color: var(--text-muted);">{{ $ev->destination_masked }} &middot; {{ \App\Models\TitleDeeds\TitleDeedEvent::KIND_LABELS[$ev->kind] ?? $ev->kind }}</span>
            @if($ev->status === 'opened')
                <div class="text-xs" style="color: var(--text-secondary);">Opened by {{ $ev->openedBy?->name ?? 'a colleague' }} at {{ $ev->opened_at?->format('H:i') }} &mdash; not yet marked as sent</div>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route('title-deeds.events.open', [$ev->title_deed_id, $ev->id]) }}" target="_blank" onsubmit="setTimeout(function () { window.location.reload(); }, 900);">
                @csrf
                <button type="submit" class="{{ $ev->status === 'prepared' ? 'corex-btn-primary' : 'corex-btn-outline' }} text-xs">{{ $ev->status === 'prepared' ? 'Open WhatsApp and send' : 'Open again' }}</button>
            </form>
            @if($ev->status === 'opened')
            <form method="POST" action="{{ route('title-deeds.events.confirm', [$ev->title_deed_id, $ev->id]) }}">@csrf
                <button type="submit" class="corex-btn-primary text-xs">Mark as sent</button>
            </form>
            <form method="POST" action="{{ route('title-deeds.events.not-sent', [$ev->title_deed_id, $ev->id]) }}">@csrf
                <button type="submit" class="corex-btn-outline text-xs">Not sent</button>
            </form>
            @endif
        </div>
    </div>
    @endforeach
</div>
@endif
