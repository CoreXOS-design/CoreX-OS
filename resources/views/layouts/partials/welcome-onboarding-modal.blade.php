{{-- ════════════════════════════════════════════════════════════════════════
     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

     WELCOME POP-UP — shown exactly once, on a new agency Admin's first
     successful login, after they've set their password via the invite link.

     Self-contained: the ONLY central wiring is a single include of this
     partial in the two app layouts (layouts/corex.blade.php and
     layouts/corex-app.blade.php), exactly like the system-update-modal.

     No persisted dismissal record — the trigger is a session-flashed flag set
     once by the Login listener in AppServiceProvider (spec §R1b), and Laravel's
     flash semantics already guarantee it survives exactly one redirect and
     never reappears on refresh or a later visit.

     Spec: .ai/specs/agency-admin-rule.md §R1b
     ════════════════════════════════════════════════════════════════════════ --}}
@auth
@if(session('show_welcome_onboarding_popup'))
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
            <a href="{{ session('welcome_onboarding_url') }}" class="corex-btn-primary text-sm">Start agency setup</a>
        </div>
    </div>
</div>
@endif
@endauth
