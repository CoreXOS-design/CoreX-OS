{{--
    2026-08-24 — same reasoning as errors/404.blade.php: Laravel's
    convention-based fallback, used only if the explicit render() callback
    in bootstrap/app.php doesn't fire. No @extends, no conditional — see
    errors/404.blade.php's comment for why a conditional @extends here
    previously leaked the authenticated app shell into guest responses.
--}}
@include('errors.403-guest')
