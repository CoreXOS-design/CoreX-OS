{{--
    WhatsApp messages CoreX has prepared but cannot send itself (spec sections 14.7 and 16.2). Each line says WHICH message it is and
    SINCE WHEN, and can always be cleared with "Don't send - clear this". The sequence stays honest:
      1. "Open WhatsApp and send" - records who opened it and when, and opens WhatsApp on the stored number with the message ready.
      2. Only AFTER that: "Mark as sent" (refused by the server for a message nobody opened).
      3. At any point: "Don't send - clear this" takes it off the list (kept in the history as not sent).
    $prepared (TitleDeedEvent rows: status prepared | opened), optional $showProperty (the list shows the property too).
    The whole panel is absent when nothing is waiting.
--}}
@if($prepared->isNotEmpty())
@php($ret = ! empty($showProperty) ? request()->getRequestUri() : null)
<div class="rounded-md p-4 space-y-2" style="background: var(--surface); border: 1px solid var(--border);">
    <div class="text-sm font-semibold" style="color: var(--text-primary);">WhatsApp messages waiting for you to send ({{ $prepared->count() }})</div>
    @foreach($prepared as $ev)
    @php($pinUsed = \App\Services\TitleDeeds\TitleDeedPolicy::for($ev->agency_id)->pinUsed())
    <div class="flex flex-wrap items-center justify-between gap-2 py-2" style="border-top: 1px solid var(--border);">
        <div class="text-sm" style="color: var(--text-primary);">
            @if(! empty($showProperty))<a href="{{ route('title-deeds.show', $ev->title_deed_id) }}" class="font-medium no-underline hover:underline" style="color: var(--text-primary);">{{ $ev->deed?->property_display }}</a> &mdash; @endif<span class="font-medium">{{ $ev->messageLabel($pinUsed) }}</span> to {{ $ev->owner_name ?: 'the owner' }}
            <span class="text-xs" style="color: var(--text-muted);">({{ $ev->destination_masked }})</span>
            <div class="text-xs" style="color: var(--text-secondary);">Prepared {{ $ev->created_at?->diffForHumans() }} (since {{ $ev->created_at?->format('d M H:i') }})@if($ev->status === 'opened') &middot; opened by {{ $ev->openedBy?->name ?? 'a colleague' }} at {{ $ev->opened_at?->format('H:i') }}, not yet marked as sent @endif</div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route('title-deeds.events.open', [$ev->title_deed_id, $ev->id]) }}" target="_blank" onsubmit="setTimeout(function () { window.location.reload(); }, 900);">
                @csrf
                <button type="submit" class="{{ $ev->status === 'prepared' ? 'corex-btn-primary' : 'corex-btn-outline' }} text-xs">{{ $ev->status === 'prepared' ? 'Open WhatsApp and send' : 'Open WhatsApp again' }}</button>
            </form>
            @if($ev->status === 'opened')
            <form method="POST" action="{{ route('title-deeds.events.confirm', [$ev->title_deed_id, $ev->id]) }}">@csrf
                <button type="submit" class="corex-btn-primary text-xs">Mark as sent</button>
            </form>
            @endif
            {{-- Johan: a message the agent decides NOT to send must be clearable. Never a hard delete: the message stays in the deed's
                 history as "not sent", with who cleared it and when. --}}
            <form method="POST" action="{{ route('title-deeds.events.dismiss', [$ev->title_deed_id, $ev->id]) }}">@csrf
                @if($ret)<input type="hidden" name="return_to" value="{{ $ret }}">@endif
                <x-confirm-submit :plain="true" title="Clear this WhatsApp without sending it" message="This WhatsApp will not be sent. It stays in the history as 'not sent' with your name on it, and the owner is not counted as told." confirm-label="Don't send - clear this" class="corex-btn-outline text-xs">Don't send - clear this</x-confirm-submit>
            </form>
        </div>
    </div>
    @endforeach
</div>
@endif
