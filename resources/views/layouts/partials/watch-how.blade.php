{{-- AT-525 - the floating "Watch how" button of a screen's auto-generated how-to video, unless the page already drew its own inline one in its header
     (<x-watch-how inline />, which marks the request). Renders nothing for a screen without a video. See components/watch-how.blade.php. --}}
@unless(request()->attributes->get('watch_how_rendered'))
<x-watch-how />
@endunless
