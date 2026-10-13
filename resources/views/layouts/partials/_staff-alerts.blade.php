{{--
    AT-451 follow-up (14 Oct 2026) - the ONE include that gives a staff page the live alert popup. Every staff layout
    (layouts/corex.blade.php and layouts/corex-app.blade.php) includes THIS and nothing else for it, so a new alert pops up on
    every staff page, not only those on one layout. @once makes a second include in the same request a no-op.

    Pass withToast=true when the layout does not already carry <x-toast-notifications /> (corex-app); the popup needs
    window.showToast. In that case the toast is rendered with flash=false: a page that never turned its session flash into a
    toast keeps showing it its own way.
--}}
@once
    @auth
        @if(! empty($withToast))
            <x-toast-notifications :flash="false" />
        @endif
        @include('components.alert-popups')
    @endauth
@endonce
