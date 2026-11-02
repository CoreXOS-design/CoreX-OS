{{-- "· N hidden" after the banner's match count (mic-work-tab.md 13.17): opens the list of properties marked "not for <buyer>" (each with Restore); while that list is open it says so and links back.
     Reads the shared $buyerHidden (set by the controller when a buyer is selected). --}}
@if(!empty($buyerHidden))
@php $viewingHidden = !empty($buyerHidden['viewing']); @endphp
<span data-qa="buyer-hidden-link" data-viewing="{{ $viewingHidden ? 1 : 0 }}" style="font-size: .6875rem; flex-shrink: 0; {{ ($buyerHidden['count'] ?? 0) > 0 || $viewingHidden ? '' : 'display:none;' }}">
    @if($viewingHidden)
        · showing the <b data-n>{{ (int) $buyerHidden['count'] }}</b> hidden for {{ $buyerHidden['first_name'] ?: 'this buyer' }} · <a href="{{ route('market-intelligence.work', request()->except(['hidden', 'page'])) }}" style="color: var(--brand-icon);">Back to matches</a>
    @else
        · <a href="{{ route('market-intelligence.work', array_merge(request()->except(['page']), ['hidden' => 1])) }}" style="color: var(--brand-icon);"><span data-n>{{ (int) $buyerHidden['count'] }}</span> hidden</a>
    @endif
</span>
@endif
