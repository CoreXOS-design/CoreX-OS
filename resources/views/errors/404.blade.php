{{--
    2026-08-24 — this file is Laravel's convention-based fallback, used ONLY
    if the explicit render() callback in bootstrap/app.php (which picks
    errors.404-app vs errors.404-guest based on auth()->check(), same
    pattern as the existing 419 handler in that file) doesn't fire for some
    reason. It intentionally contains NO @extends and NO conditional logic:
    a conditional @auth/@extends/@else/@endauth here previously leaked the
    authenticated app shell into every guest response, because @extends
    compiles to a call that Blade hoists to the END of the compiled output
    UNCONDITIONALLY, regardless of which branch it appears in — confirmed
    by inspecting the compiled PHP directly (storage/framework/views/*.php
    had `$__env->make('layouts.corex', ...)->render()` sitting AFTER the
    if/else/endif, outside the conditional entirely). The safe default for
    a file that might render without the auth context being resolved yet is
    the neutral guest page, never the app shell.
--}}
@include('errors.404-guest')
