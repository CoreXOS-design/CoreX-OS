{{--
    Buyer Pack download link (.ai/specs/viewing-pack.md "Whose details on the cover", AT-509).

    When the buyer is under ANOTHER agent, the click first asks whose details go on the cover:
      - "Show my details"      -> ?details=mine  (the downloader's details; the buyer's agent is told in their notifications)
      - "Show <agent>'s details" -> ?details=agent (ruling D, unchanged: the buyer's agent)
    Otherwise it is the plain download link it always was. Dialog = the confirm-submit pattern (no browser confirm box).

    Requires: $pack. Optional: $label, $class, $style, $title (the trigger's look, as the caller's link had it).
--}}
@php
    $__url = route('corex.viewing-packs.buyer-pack', $pack);
    $__buyersAgent = app(\App\Services\ViewingPack\ViewingPackBuyerPdfService::class)->buyersAgent($pack);
    $__ask = $__buyersAgent && (int) $__buyersAgent->id !== (int) auth()->id();
    $__label = $label ?? 'Download Buyer Pack';
@endphp
@if(! $__ask)
    <a href="{{ $__url }}" class="{{ $class ?? '' }}" style="{{ $style ?? '' }}" @isset($title) title="{{ $title }}" @endisset target="_blank" rel="noopener">{{ $__label }}</a>
@else
    @php
        $__agentName = $__buyersAgent->name ?: 'the buyer\'s agent';
        $__buyerName = trim((string) ($pack->contact?->full_name ?? trim(($pack->contact?->first_name ?? '') . ' ' . ($pack->contact?->last_name ?? '')))) ?: 'This buyer';
    @endphp
    <span x-data="{ open: false }" x-on:keydown.escape.window="open = false" class="inline-block">
        <button type="button" class="{{ $class ?? '' }}" style="{{ $style ?? '' }}" @isset($title) title="{{ $title }}" @endisset
                x-on:click="open = true" data-buyer-pack-ask>{{ $__label }}</button>
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(15, 23, 42, .5);"
             x-on:click.self="open = false" role="dialog" aria-modal="true" aria-label="Whose details go on the buyer pack?" data-buyer-pack-modal>
            <div class="rounded-md p-5 w-full max-w-md space-y-3 text-left" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <h3 class="text-sm font-semibold">Whose details go on the buyer pack?</h3>
                <p class="text-sm">{{ $__buyerName }} is {{ $__agentName }}'s buyer, so the pack shows {{ $__agentName }}'s name, number and photo. You can put your own details on it instead &mdash; {{ $__agentName }} will then get a notification that you made a buyer pack for their buyer.</p>
                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" class="corex-btn-outline text-xs" x-on:click="open = false">Cancel</button>
                    <a href="{{ $__url }}?details=agent" target="_blank" rel="noopener" class="corex-btn-outline text-xs no-underline"
                       x-on:click="open = false" data-buyer-pack-agent>Show {{ $__agentName }}'s details</a>
                    <a href="{{ $__url }}?details=mine" target="_blank" rel="noopener" class="corex-btn-primary text-xs no-underline"
                       x-on:click="open = false" data-buyer-pack-mine>Show my details</a>
                </div>
            </div>
        </div>
    </span>
@endif
