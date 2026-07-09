{{--
    Syndication control for a property card / list row.

    Shown only when BOTH hold (flags derived in PropertyController@index):
      - is_marketable   — compliance lets this listing go to market
      - has_syndication — it actually reaches at least one portal

    A blocked listing, or one that reaches no portal, has no syndication to
    look at, so the control is absent rather than disabled.

    Opens the shared modal in the page-root Alpine scope (openSyn) — one modal
    for the whole page, not one per card.

    @param \App\Models\Property $property
    @param string $variant  'card' (image overlay) | 'row' (table actions)
--}}
@php
    $synVariant = $variant ?? 'card';
    $synVisible = ($property->is_marketable ?? false) && ($property->has_syndication ?? false);
@endphp
@if($synVisible)
    @php
        $synLinks   = $property->syndication_links ?? [];
        $synLive    = collect($synLinks)->where('status', 'live');
        $synPayload = [
            'title' => $property->buildDisplayAddress() ?: ($property->title ?: 'Property'),
            'links' => $synLinks,
        ];
        $synTitle = 'Syndication — live on ' . $synLive->pluck('label')->join(', ');
    @endphp
    <button type="button"
            @click.prevent.stop="openSyn({{ Illuminate\Support\Js::from($synPayload) }})"
            aria-label="{{ $synTitle }}"
            title="{{ $synTitle }}"
            @class([
                'inline-flex items-center justify-center transition-all duration-300' => true,
                'w-7 h-7 rounded-md' => $synVariant === 'card',
                'corex-btn-outline text-[10px] px-2 py-1 gap-1' => $synVariant === 'row',
            ])
            @if($synVariant === 'card')
                style="background:rgba(0,0,0,0.5);color:#fff;backdrop-filter:blur(4px);"
                onmouseover="this.style.background='rgba(0,0,0,0.72)'"
                onmouseout="this.style.background='rgba(0,0,0,0.5)'"
            @endif
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="{{ $synVariant === 'card' ? 'w-4 h-4' : 'w-3 h-3' }}"
             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 0 1 1.06 0Z"/>
        </svg>
        @if($synVariant === 'row')
            <span>{{ $synLive->count() }}</span>
        @endif
    </button>
@endif
