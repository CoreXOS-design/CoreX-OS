{{-- Road to Sold 23.23: loads the pitch popup + the "What is this property?" window. Any <a data-pitch-popup> opens the popup; any [data-pitch-peek] opens the card.
     Kept as a page script (not inside a swapped list fragment, where scripts do not run). --}}
<script>window.CorexPitchConfig = { card: @json(route('market-intelligence.work', [], false)), steps: @json(route('seller-outreach.entry.pitch-steps', [], false)), offramp: @json(route('seller-outreach.entry.pitch-offramp', [], false)) };</script>
<script src="{{ asset('js/pitch-popup.js') }}?v={{ @filemtime(public_path('js/pitch-popup.js')) ?: 1 }}" defer></script>
<script src="{{ asset('js/pitch-offramp.js') }}?v={{ @filemtime(public_path('js/pitch-offramp.js')) ?: 1 }}" defer></script>
