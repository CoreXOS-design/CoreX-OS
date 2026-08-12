{{-- ════════════════════════════════════════════════════════════════════════
     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

     WELCOME POP-UP — shown exactly once, on a new agency Admin's first
     successful login, after they've set their password via the invite link.

     Self-contained: the ONLY central wiring is a single include of this
     partial in the two app layouts (layouts/corex.blade.php and
     layouts/corex-app.blade.php), exactly like the system-update-modal.

     No persisted dismissal record — the trigger is session PUT (not flash) by
     the Login listener in AppServiceProvider (spec §R1b). flash() was tried
     first and doesn't work here: it only survives ONE subsequent request, but
     the post-login redirect chain is TWO hops (POST /login -> GET /dashboard,
     a redirect-only closure with no view -> GET /corex/dashboard, the actual
     rendered page) — the flash aged out before this partial ever rendered.
     put() persists until THIS partial explicitly forgets it below, right
     after reading it, so it still only ever shows once.

     Spec: .ai/specs/agency-admin-rule.md §R1b
     ════════════════════════════════════════════════════════════════════════ --}}
@auth
@php
    $__welcomeOnboardingUrl = session('welcome_onboarding_url');
    $__showWelcomeOnboarding = session('show_welcome_onboarding_popup') && $__welcomeOnboardingUrl;
    if ($__showWelcomeOnboarding) {
        session()->forget(['show_welcome_onboarding_popup', 'welcome_onboarding_url']);
    }
@endphp
@if($__showWelcomeOnboarding)
<div x-data="{ open: true }"
     x-show="open"
     x-cloak
     @keydown.escape.window="open = false"
     class="fixed inset-0 z-[70] flex items-center justify-center p-4"
     role="dialog"
     aria-modal="true"
     aria-labelledby="welcome-onboarding-heading">

    <div class="absolute inset-0" style="background:rgba(0,0,0,0.55);" @click="open = false"></div>

    <div class="relative w-full max-w-md rounded-md shadow-2xl overflow-hidden"
         style="background:var(--surface); border:1px solid var(--border);"
         @click.stop>

        <div class="px-6 pt-6 pb-4">
            <div id="welcome-onboarding-heading" class="text-base font-bold" style="color:var(--text-primary);">
                Thank you for choosing CoreX OS
            </div>
            <p class="text-sm mt-2" style="color:var(--text-secondary);">
                Let's get your agency set up. A short guided walkthrough will take you through
                branding, commission, properties, compliance, and everything else CoreX needs to
                know about how your agency runs — with sane defaults already filled in so it only
                takes a few minutes.
            </p>
        </div>

        <div class="flex items-center justify-end gap-2 px-6 py-4" style="border-top:1px solid var(--border); background:var(--surface-2);">
            <button type="button" @click="open = false" class="corex-btn-outline text-sm">Maybe later</button>
            <a href="{{ $__welcomeOnboardingUrl }}" class="corex-btn-primary text-sm">Start agency setup</a>
        </div>
    </div>
</div>
@endif
@endauth
