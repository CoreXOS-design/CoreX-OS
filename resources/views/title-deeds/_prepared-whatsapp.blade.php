{{-- WhatsApp messages CoreX has prepared but cannot send itself: open WhatsApp (message ready), send it, then confirm. --}}
@if($prepared->isNotEmpty())
<div class="rounded-md p-4 space-y-2" style="background: var(--surface); border: 1px solid var(--border);">
    <div class="text-sm font-semibold" style="color: var(--text-primary);">WhatsApp waiting to be sent</div>
    @foreach($prepared as $ev)
    <div class="flex flex-wrap items-center justify-between gap-2 py-1" style="border-top: 1px solid var(--border);">
        <div class="text-sm" style="color: var(--text-primary);">
            {{ $ev->owner_name ?: 'Owner' }}
            <span class="text-xs" style="color: var(--text-muted);">{{ $ev->destination_masked }} · {{ \App\Models\TitleDeeds\TitleDeedEvent::KIND_LABELS[$ev->kind] ?? $ev->kind }}</span>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('title-deeds.events.whatsapp', [$deed->id, $ev->id]) }}" target="_blank" rel="noopener noreferrer" class="corex-btn-outline text-xs">Open WhatsApp</a>
            <form method="POST" action="{{ route('title-deeds.events.confirm', [$deed->id, $ev->id]) }}">
                @csrf
                <button type="submit" class="corex-btn-primary text-xs">I sent it</button>
            </form>
        </div>
    </div>
    @endforeach
</div>
@endif
