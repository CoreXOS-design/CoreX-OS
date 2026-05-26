<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Api\CommandCenterApiController;
use App\Http\Controllers\Api\NotificationController as ApiNotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\ProspectingApiController;
use App\Http\Controllers\Api\MobilePropertyController;
use App\Http\Controllers\Api\MobileContactController;
use App\Http\Controllers\Api\MobileContactComplianceController;
use App\Http\Controllers\Api\MobileCoreMatchController;
use App\Http\Controllers\Api\PropertyPullController;
use App\Http\Controllers\Api\V1\ClientAuthController;
use App\Http\Controllers\Api\V1\ClientPortalController;
use App\Http\Controllers\FaultReportController;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

// ════════════════════════════════════════════════════════════════
// Top-level, unauthenticated / token-issuing endpoints
// Per Non-Negotiable #7, NEW endpoints must live under /api/v1/*.
// These three top-level routes (login, fault-report, pp/webhook)
// pre-date the rule; canonical v1 versions are registered below
// and the originals remain as LEGACY aliases.
// ════════════════════════════════════════════════════════════════

$loginHandler = function (Request $request) {
    $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    $user = User::where('email', $request->email)->first();

    if (! $user || ! Hash::check($request->password, $user->password)) {
        throw ValidationException::withMessages([
            'email' => ['The provided credentials are incorrect.'],
        ]);
    }

    $token = $user->createToken('corex-mobile')->plainTextToken;

    $agency = $user->effectiveAgencyId()
        ? \App\Models\Agency::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->find($user->effectiveAgencyId())
        : null;

    return response()->json([
        'token' => $token,
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'branch' => $user->branch?->name ?? null,
            'ffc_status' => $user->ffc_status ?? null,
            'agency' => $agency ? [
                'id'   => $agency->id,
                'slug' => $agency->slug,
                'name' => $agency->name,
            ] : null,
        ],
    ]);
};

// Canonical v1 versions
Route::post('v1/login', $loginHandler)->name('v1.login');
Route::post('v1/fault-report', [FaultReportController::class, 'capture'])
    ->middleware('throttle:30,1')
    ->name('v1.fault-report');
Route::post('v1/pp/webhook', [\App\Http\Controllers\PrivateProperty\PpWebhookController::class, 'receive'])
    ->name('v1.pp.webhook');

// LEGACY: remove after 2026-08-21
Route::post('/login', $loginHandler)->name('legacy.login');
// LEGACY: remove after 2026-08-21
Route::post('/fault-report', [FaultReportController::class, 'capture'])
    ->middleware('throttle:30,1')
    ->name('legacy.fault-report');
// LEGACY: remove after 2026-08-21
Route::post('/pp/webhook', [\App\Http\Controllers\PrivateProperty\PpWebhookController::class, 'receive'])
    ->name('pp.webhook');

// ════════════════════════════════════════════════════════════════
// API v1 — Client Auth (mobile client portal)
// Spec: .ai/specs/client-auth.md
// ════════════════════════════════════════════════════════════════
// NB: The /api/v1/p24/* location-tree endpoints live in routes/web.php so
// they get the full `web` middleware group (cookie + session). Calling them
// from a Blade-rendered page over fetch needs session-cookie auth, which
// isn't applied to routes registered here in api.php.

// ════════════════════════════════════════════════════════════════
// API v1 — Demo Mode (mobile app)
// Hard-gated to non-production via DemoLoginController::isEnabled()
// ════════════════════════════════════════════════════════════════
Route::prefix('v1/demo')->group(function () {
    // Route names are prefixed `api.` to avoid colliding with the web
    // `demo.login` route (routes/auth.php) — a duplicate name makes the
    // route() helper resolve to whichever loads last.
    Route::get('/status', [\App\Http\Controllers\Api\V1\DemoAuthController::class, 'status'])->name('api.demo.status');
    Route::post('/login', [\App\Http\Controllers\Api\V1\DemoAuthController::class, 'login'])->name('api.demo.login');
});

Route::prefix('v1/client-auth')->group(function () {
    Route::post('/lookup',          [ClientAuthController::class, 'lookup'])->name('client-auth.lookup');
    Route::post('/otp/send',        [ClientAuthController::class, 'sendOtp'])->name('client-auth.otp.send');
    Route::post('/otp/verify',      [ClientAuthController::class, 'verifyOtp'])->name('client-auth.otp.verify');
    Route::post('/login',           [ClientAuthController::class, 'login'])->name('client-auth.login');
    Route::post('/password/forgot', [ClientAuthController::class, 'forgotPassword'])->name('client-auth.password.forgot');

    // Agent QR onboarding — spec: .ai/specs/agent-qr-onboarding.md
    Route::get('/agent-qr/{slug}',           [\App\Http\Controllers\Api\V1\AgentQrController::class, 'show'])
        ->name('client-auth.agent-qr.show');
    Route::post('/agent-qr/{slug}/register', [\App\Http\Controllers\Api\V1\AgentQrController::class, 'register'])
        ->name('client-auth.agent-qr.register');

    // Activation token OR client sanctum token (both checked in controller)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/password/set', [ClientAuthController::class, 'setPassword'])->name('client-auth.password.set');
    });

    // Client sanctum token only
    Route::middleware(['auth:sanctum', 'client.ability'])->group(function () {
        Route::post('/password/change', [ClientAuthController::class, 'changePassword'])->name('client-auth.password.change');
        Route::post('/agency/select',   [ClientAuthController::class, 'selectAgency'])->name('client-auth.agency.select');
        Route::post('/logout',          [ClientAuthController::class, 'logout'])->name('client-auth.logout');
    });
});

Route::prefix('v1/client')->middleware(['auth:sanctum', 'client.ability'])->group(function () {
    Route::get('/me',                 [ClientPortalController::class, 'me'])->name('client.me');
    Route::get('/match-options',      [ClientPortalController::class, 'matchOptions'])->name('client.match-options');

    Route::get('/matches',                                  [ClientPortalController::class, 'matches'])->name('client.matches');
    Route::post('/matches',                                 [ClientPortalController::class, 'matchCreate'])->name('client.matches.create');
    Route::get('/matches/{match}',                          [ClientPortalController::class, 'matchShow'])->name('client.matches.show');
    Route::put('/matches/{match}',                          [ClientPortalController::class, 'matchUpdate'])->name('client.matches.update');
    Route::post('/matches/{match}/feedback/{property}',     [ClientPortalController::class, 'matchFeedback'])->name('client.matches.feedback');
    Route::post('/matches/{match}/view/{property}',         [ClientPortalController::class, 'matchView'])->name('client.matches.view');

    Route::get('/properties/{property}',  [ClientPortalController::class, 'propertyShow'])->name('client.properties.show');
});

// ════════════════════════════════════════════════════════════════
// Authenticated (sanctum) — canonical v1 routes
// ════════════════════════════════════════════════════════════════
Route::middleware('auth:sanctum')->group(function () {

    // ─────────────────────────────────────────────────────────────
    // Canonical /api/v1/* surface
    // ─────────────────────────────────────────────────────────────
    Route::prefix('v1')->group(function () {

        // Session-authed "who am I" — fired automatically on every page
        // via resources/js/corex-api.js (see Non-Negotiable #7).
        Route::get('/logged-user', function (Request $request) {
            $user = $request->user();
            $agency = $user->effectiveAgencyId()
                ? \App\Models\Agency::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
                    ->find($user->effectiveAgencyId())
                : null;
            return response()->json([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'branch' => $user->branch?->name ?? null,
                'ffc_status' => $user->ffc_status ?? null,
                'agency' => $agency ? [
                    'id'   => $agency->id,
                    'slug' => $agency->slug,
                    'name' => $agency->name,
                ] : null,
            ]);
        })->name('v1.logged-user');

        Route::get('/profile', function (Request $request) {
            $user = $request->user();
            $agency = $user->effectiveAgencyId()
                ? \App\Models\Agency::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
                    ->find($user->effectiveAgencyId())
                : null;
            return response()->json([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'branch' => $user->branch?->name ?? null,
                'ffc_status' => $user->ffc_status ?? null,
                'agency' => $agency ? [
                    'id'   => $agency->id,
                    'slug' => $agency->slug,
                    'name' => $agency->name,
                ] : null,
            ]);
        })->name('v1.profile');

        Route::post('/logout', function (Request $request) {
            $request->user()->currentAccessToken()->delete();
            return response()->json(['message' => 'Logged out']);
        })->name('v1.logout');

        // ── Mobile data-visibility descriptor ───────────────────────
        Route::get('/mobile/visibility', [\App\Http\Controllers\Api\MobileVisibilityController::class, 'show'])
            ->name('v1.mobile.visibility');

        // ── Prospecting ────────────────────────────────────────────
        Route::post('/prospecting/import',      [ProspectingApiController::class, 'import'])->name('v1.prospecting.import');
        Route::get('/prospecting/check-search', [ProspectingApiController::class, 'checkSearch'])->name('v1.prospecting.check-search');

        // ── Properties — portal pull ───────────────────────────────
        Route::post('/properties/pull-from-portal',          [PropertyPullController::class, 'pullFromPortal'])->name('v1.properties.pull-from-portal');
        Route::get('/properties/{propertyId}/pull-status',   [PropertyPullController::class, 'pullStatus'])->name('v1.properties.pull-status');

        // ── Mobile P24 location tree (token-authed) ──────────────────
        Route::prefix('mobile/p24')->group(function () {
            Route::get('/provinces', [\App\Http\Controllers\Api\V1\P24LocationController::class, 'provinces'])->name('v1.mobile.p24.provinces');
            Route::get('/cities',    [\App\Http\Controllers\Api\V1\P24LocationController::class, 'cities'])->name('v1.mobile.p24.cities');
            Route::get('/suburbs',   [\App\Http\Controllers\Api\V1\P24LocationController::class, 'suburbs'])->name('v1.mobile.p24.suburbs');
        });

        // ── Mobile Properties ────────────────────────────────────────
        Route::prefix('mobile/properties')->group(function () {
            Route::get('/',         [MobilePropertyController::class, 'index'])->name('v1.mobile.properties.index');
            Route::post('/',        [MobilePropertyController::class, 'store'])->name('v1.mobile.properties.store');

            Route::get('/options',        [MobilePropertyController::class, 'options'])->name('v1.mobile.properties.options');
            Route::get('/spaces/catalog', [MobilePropertyController::class, 'spacesCatalog'])->name('v1.mobile.properties.spaces.catalog');

            Route::get('/{property}',  [MobilePropertyController::class, 'show'])->name('v1.mobile.properties.show');
            Route::put('/{property}',  [MobilePropertyController::class, 'update'])->name('v1.mobile.properties.update');
            Route::post('/{property}/images', [MobilePropertyController::class, 'uploadImage'])->name('v1.mobile.properties.images.upload');

            Route::get('/{property}/overview', [MobilePropertyController::class, 'overview'])->name('v1.mobile.properties.overview');

            Route::get('/{property}/compliance',                 [MobilePropertyController::class, 'compliance'])->name('v1.mobile.properties.compliance');
            Route::post('/{property}/compliance/send-to-market', [MobilePropertyController::class, 'sendToMarket'])->name('v1.mobile.properties.compliance.send-to-market');

            Route::get('/{property}/contacts',              [MobilePropertyController::class, 'contactsIndex'])->name('v1.mobile.properties.contacts.index');
            Route::post('/{property}/contacts',             [MobilePropertyController::class, 'contactsLink'])->name('v1.mobile.properties.contacts.link');
            Route::delete('/{property}/contacts/{contact}', [MobilePropertyController::class, 'contactsUnlink'])->name('v1.mobile.properties.contacts.unlink');

            Route::get('/{property}/gallery/tags',          [MobilePropertyController::class, 'galleryTags'])->name('v1.mobile.properties.gallery.tags.index');
            Route::post('/{property}/gallery/tags',         [MobilePropertyController::class, 'addCustomTag'])->name('v1.mobile.properties.gallery.tags.add');
            Route::delete('/{property}/gallery/tags',       [MobilePropertyController::class, 'removeCustomTag'])->name('v1.mobile.properties.gallery.tags.remove');

            Route::get('/{property}/spaces', [MobilePropertyController::class, 'spacesShow'])->name('v1.mobile.properties.spaces.show');
            Route::put('/{property}/spaces', [MobilePropertyController::class, 'spacesUpdate'])->name('v1.mobile.properties.spaces.update');
        });

        // ── Mobile Contacts ─────────────────────────────────────────
        Route::prefix('mobile/contacts')->group(function () {
            Route::get('/',         [MobileContactController::class, 'index'])->name('v1.mobile.contacts.index');
            Route::post('/',        [MobileContactController::class, 'store'])->name('v1.mobile.contacts.store');
            Route::get('/options',  [MobileContactController::class, 'options'])->name('v1.mobile.contacts.options');
            Route::get('/{contact}',[MobileContactController::class, 'show'])->name('v1.mobile.contacts.show');
            Route::put('/{contact}',[MobileContactController::class, 'update'])->name('v1.mobile.contacts.update');
            Route::post('/{contact}/whatsapp', [MobileContactController::class, 'whatsapp'])->name('v1.mobile.contacts.whatsapp');
            Route::post('/{contact}/matches',  [MobileContactController::class, 'storeMatch'])->name('v1.mobile.contacts.matches.store');

            Route::get('/{contact}/consent',         [MobileContactComplianceController::class, 'consentIndex'])->name('v1.mobile.contacts.consent.index');
            Route::post('/{contact}/consent',        [MobileContactComplianceController::class, 'consentRecord'])->name('v1.mobile.contacts.consent.record');
            Route::post('/{contact}/consent/revoke', [MobileContactComplianceController::class, 'consentRevoke'])->name('v1.mobile.contacts.consent.revoke');

            Route::get('/{contact}/drive',                       [MobileContactComplianceController::class, 'driveIndex'])->name('v1.mobile.contacts.drive.index');
            Route::post('/{contact}/drive',                      [MobileContactComplianceController::class, 'driveStore'])->name('v1.mobile.contacts.drive.store');
            Route::put('/{contact}/drive/{document}',            [MobileContactComplianceController::class, 'driveUpdate'])->name('v1.mobile.contacts.drive.update');
            Route::get('/{contact}/drive/{document}/download',   [MobileContactComplianceController::class, 'driveDownload'])->name('v1.mobile.contacts.drive.download');
            Route::delete('/{contact}/drive/{document}',         [MobileContactComplianceController::class, 'driveDestroy'])->name('v1.mobile.contacts.drive.destroy');

            Route::get('/{contact}/fica', [MobileContactComplianceController::class, 'ficaIndex'])->name('v1.mobile.contacts.fica.index');
        });

        // ── Mobile Core Matches ─────────────────────────────────────
        Route::prefix('mobile/core-matches')->group(function () {
            Route::get('/settings',               [MobileCoreMatchController::class, 'settings'])->name('v1.mobile.core-matches.settings');
            Route::get('/',                       [MobileCoreMatchController::class, 'index'])->name('v1.mobile.core-matches.index');
            Route::get('/{match}',                [MobileCoreMatchController::class, 'show'])->name('v1.mobile.core-matches.show');
            Route::put('/{match}',                [MobileCoreMatchController::class, 'update'])->name('v1.mobile.core-matches.update');
            Route::patch('/{match}/status',       [MobileCoreMatchController::class, 'setStatus'])->name('v1.mobile.core-matches.status');
            Route::post('/{match}/hide/{property}', [MobileCoreMatchController::class, 'toggleHide'])->name('v1.mobile.core-matches.hide');
            Route::get('/{match}/share-whatsapp',  [MobileCoreMatchController::class, 'shareWhatsApp'])->name('v1.mobile.core-matches.share-whatsapp.get');
            Route::post('/{match}/share-whatsapp', [MobileCoreMatchController::class, 'shareWhatsApp'])->name('v1.mobile.core-matches.share-whatsapp.post');
            Route::delete('/{match}',             [MobileCoreMatchController::class, 'destroy'])->name('v1.mobile.core-matches.destroy');
        });

        // ── Command Center ────────────────────────────────────────────
        Route::prefix('command-center')->group(function () {
            Route::get('/dashboard',       [CommandCenterApiController::class, 'dashboard'])->name('v1.command-center.dashboard');
            Route::get('/today',           [CommandCenterApiController::class, 'today'])->name('v1.command-center.today');
            Route::post('/today/refresh',  [CommandCenterApiController::class, 'todayRefresh'])->name('v1.command-center.today.refresh');

            Route::get('/calendar',                                       [CommandCenterApiController::class, 'calendarIndex'])->name('v1.command-center.calendar.index');
            Route::post('/calendar',                                      [CommandCenterApiController::class, 'calendarStore'])->name('v1.command-center.calendar.store');
            Route::get('/calendar/conflicts',                             [CommandCenterApiController::class, 'calendarConflicts'])->name('v1.command-center.calendar.conflicts');
            Route::get('/calendar/invitations',                           [CommandCenterApiController::class, 'invitationsIndex'])->name('v1.command-center.calendar.invitations.index');
            Route::post('/calendar/invitations/{invitation}/respond',     [CommandCenterApiController::class, 'invitationRespond'])->name('v1.command-center.calendar.invitations.respond');
            Route::post('/calendar/invitations/{invitation}/acknowledge', [CommandCenterApiController::class, 'invitationAcknowledge'])->name('v1.command-center.calendar.invitations.acknowledge');
            Route::post('/calendar/{calendarEvent}/complete',             [CommandCenterApiController::class, 'calendarComplete'])->name('v1.command-center.calendar.complete');
            Route::post('/calendar/{calendarEvent}/dismiss',              [CommandCenterApiController::class, 'calendarDismiss'])->name('v1.command-center.calendar.dismiss');
            Route::put('/calendar/{calendarEvent}',                       [CommandCenterApiController::class, 'calendarUpdate'])->name('v1.command-center.calendar.update');
            Route::delete('/calendar/{calendarEvent}',                    [CommandCenterApiController::class, 'calendarDestroy'])->name('v1.command-center.calendar.destroy');

            Route::get('/tasks',                       [CommandCenterApiController::class, 'tasksIndex'])->name('v1.command-center.tasks.index');
            Route::get('/tasks/archived',              [CommandCenterApiController::class, 'tasksArchived'])->name('v1.command-center.tasks.archived');
            Route::post('/tasks/archive-done',         [CommandCenterApiController::class, 'tasksArchiveDone'])->name('v1.command-center.tasks.archive-done');
            Route::post('/tasks/{taskId}/restore',     [CommandCenterApiController::class, 'tasksRestore'])->name('v1.command-center.tasks.restore');
            Route::post('/tasks',                      [CommandCenterApiController::class, 'tasksStore'])->name('v1.command-center.tasks.store');
            Route::post('/tasks/{task}/complete',      [CommandCenterApiController::class, 'tasksComplete'])->name('v1.command-center.tasks.complete');
            Route::patch('/tasks/{task}/status',       [CommandCenterApiController::class, 'tasksUpdateStatus'])->name('v1.command-center.tasks.status');
            Route::put('/tasks/{task}',                [CommandCenterApiController::class, 'tasksUpdate'])->name('v1.command-center.tasks.update');
            Route::delete('/tasks/{task}',             [CommandCenterApiController::class, 'tasksDestroy'])->name('v1.command-center.tasks.destroy');

            Route::get('/tasks/{task}/notes',           [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'index'])->name('v1.command-center.tasks.notes.index');
            Route::post('/tasks/{task}/notes',          [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'store'])->name('v1.command-center.tasks.notes.store');
            Route::put('/tasks/{task}/notes/{note}',    [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'update'])->name('v1.command-center.tasks.notes.update');
            Route::delete('/tasks/{task}/notes/{note}', [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'destroy'])->name('v1.command-center.tasks.notes.destroy');

            Route::get('/tasks/{task}/checklist',             [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'checklistIndex'])->name('v1.command-center.tasks.checklist.index');
            Route::post('/tasks/{task}/checklist',            [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'checklistStore'])->name('v1.command-center.tasks.checklist.store');
            Route::patch('/tasks/{task}/checklist/{itemId}',  [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'checklistUpdate'])->name('v1.command-center.tasks.checklist.update');
            Route::delete('/tasks/{task}/checklist/{itemId}', [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'checklistDestroy'])->name('v1.command-center.tasks.checklist.destroy');

            Route::post('/resolve-task/{task}',          [CommandCenterApiController::class, 'resolveTask'])->name('v1.command-center.resolve-task');
            Route::post('/resolve-event/{calendarEvent}',[CommandCenterApiController::class, 'resolveEvent'])->name('v1.command-center.resolve-event');

            Route::get('/user-settings', [CommandCenterApiController::class, 'settingsIndex'])->name('v1.command-center.user-settings.index');
            Route::put('/user-settings', [CommandCenterApiController::class, 'settingsUpdate'])->name('v1.command-center.user-settings.update');
        });

        // ── Notifications (mobile) ──────────────────────────────────
        Route::get('/notifications',                 [ApiNotificationController::class, 'index'])->name('v1.notifications.index');
        Route::post('/notifications/{id}/read',      [ApiNotificationController::class, 'markRead'])->name('v1.notifications.read');
        Route::post('/notifications/mark-all-read',  [ApiNotificationController::class, 'markAllRead'])->name('v1.notifications.mark-all-read');
        Route::get('/notifications/overdue',         [ApiNotificationController::class, 'overdue'])->name('v1.notifications.overdue');

        Route::get('/notification-preferences',  [NotificationPreferenceController::class, 'index'])->name('v1.notification-preferences.index');
        Route::put('/notification-preferences',  [NotificationPreferenceController::class, 'update'])->name('v1.notification-preferences.update');

        Route::post('/device-tokens',           [DeviceTokenController::class, 'store'])->name('v1.device-tokens.store');
        Route::delete('/device-tokens/{token}', [DeviceTokenController::class, 'destroy'])->name('v1.device-tokens.destroy');

        // Agent's own onboarding QR — spec: .ai/specs/agent-qr-onboarding.md
        Route::get('/me/agent-qr', [\App\Http\Controllers\Api\V1\AgentQrController::class, 'mine'])
            ->name('v1.me.agent-qr');
    });

    // ═════════════════════════════════════════════════════════════
    // LEGACY ALIASES — duplicate registrations at the OLD URIs that
    // point at the SAME controller@method as the canonical v1 routes
    // above. Existing mobile clients keep working while we migrate.
    // Names are `legacy.*` so they never collide.
    // LEGACY: remove after 2026-08-21
    // ═════════════════════════════════════════════════════════════

    // /profile + /logout (top-level, pre-v1)
    // LEGACY: remove after 2026-08-21
    Route::get('/profile', function (Request $request) {
        $user = $request->user();
        $agency = $user->effectiveAgencyId()
            ? \App\Models\Agency::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
                ->find($user->effectiveAgencyId())
            : null;
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'branch' => $user->branch?->name ?? null,
            'ffc_status' => $user->ffc_status ?? null,
            'agency' => $agency ? [
                'id'   => $agency->id,
                'slug' => $agency->slug,
                'name' => $agency->name,
            ] : null,
        ]);
    })->name('legacy.profile');

    // LEGACY: remove after 2026-08-21
    Route::post('/logout', function (Request $request) {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    })->name('legacy.logout');

    // LEGACY: remove after 2026-08-21
    Route::get('/mobile/visibility', [\App\Http\Controllers\Api\MobileVisibilityController::class, 'show'])
        ->name('legacy.mobile.visibility');

    // LEGACY: remove after 2026-08-21
    Route::post('/prospecting/import',      [ProspectingApiController::class, 'import'])->name('legacy.prospecting.import');
    Route::get('/prospecting/check-search', [ProspectingApiController::class, 'checkSearch'])->name('legacy.prospecting.check-search');

    // LEGACY: remove after 2026-08-21
    Route::post('/properties/pull-from-portal',        [PropertyPullController::class, 'pullFromPortal'])->name('legacy.properties.pull-from-portal');
    Route::get('/properties/{propertyId}/pull-status', [PropertyPullController::class, 'pullStatus'])->name('legacy.properties.pull-status');

    // LEGACY: remove after 2026-08-21
    Route::prefix('mobile/p24')->group(function () {
        Route::get('/provinces', [\App\Http\Controllers\Api\V1\P24LocationController::class, 'provinces'])->name('legacy.mobile.p24.provinces');
        Route::get('/cities',    [\App\Http\Controllers\Api\V1\P24LocationController::class, 'cities'])->name('legacy.mobile.p24.cities');
        Route::get('/suburbs',   [\App\Http\Controllers\Api\V1\P24LocationController::class, 'suburbs'])->name('legacy.mobile.p24.suburbs');
    });

    // LEGACY: remove after 2026-08-21
    Route::prefix('mobile/properties')->group(function () {
        Route::get('/',         [MobilePropertyController::class, 'index'])->name('legacy.mobile.properties.index');
        Route::post('/',        [MobilePropertyController::class, 'store'])->name('legacy.mobile.properties.store');
        Route::get('/options',        [MobilePropertyController::class, 'options'])->name('legacy.mobile.properties.options');
        Route::get('/spaces/catalog', [MobilePropertyController::class, 'spacesCatalog'])->name('legacy.mobile.properties.spaces.catalog');
        Route::get('/{property}',  [MobilePropertyController::class, 'show'])->name('legacy.mobile.properties.show');
        Route::put('/{property}',  [MobilePropertyController::class, 'update'])->name('legacy.mobile.properties.update');
        Route::post('/{property}/images', [MobilePropertyController::class, 'uploadImage'])->name('legacy.mobile.properties.images.upload');
        Route::get('/{property}/overview', [MobilePropertyController::class, 'overview'])->name('legacy.mobile.properties.overview');
        Route::get('/{property}/compliance',                 [MobilePropertyController::class, 'compliance'])->name('legacy.mobile.properties.compliance');
        Route::post('/{property}/compliance/send-to-market', [MobilePropertyController::class, 'sendToMarket'])->name('legacy.mobile.properties.compliance.send-to-market');
        Route::get('/{property}/contacts',              [MobilePropertyController::class, 'contactsIndex'])->name('legacy.mobile.properties.contacts.index');
        Route::post('/{property}/contacts',             [MobilePropertyController::class, 'contactsLink'])->name('legacy.mobile.properties.contacts.link');
        Route::delete('/{property}/contacts/{contact}', [MobilePropertyController::class, 'contactsUnlink'])->name('legacy.mobile.properties.contacts.unlink');
        Route::get('/{property}/gallery/tags',          [MobilePropertyController::class, 'galleryTags'])->name('legacy.mobile.properties.gallery.tags.index');
        Route::post('/{property}/gallery/tags',         [MobilePropertyController::class, 'addCustomTag'])->name('legacy.mobile.properties.gallery.tags.add');
        Route::delete('/{property}/gallery/tags',       [MobilePropertyController::class, 'removeCustomTag'])->name('legacy.mobile.properties.gallery.tags.remove');
        Route::get('/{property}/spaces', [MobilePropertyController::class, 'spacesShow'])->name('legacy.mobile.properties.spaces.show');
        Route::put('/{property}/spaces', [MobilePropertyController::class, 'spacesUpdate'])->name('legacy.mobile.properties.spaces.update');
    });

    // LEGACY: remove after 2026-08-21
    Route::prefix('mobile/contacts')->group(function () {
        Route::get('/',         [MobileContactController::class, 'index'])->name('legacy.mobile.contacts.index');
        Route::post('/',        [MobileContactController::class, 'store'])->name('legacy.mobile.contacts.store');
        Route::get('/options',  [MobileContactController::class, 'options'])->name('legacy.mobile.contacts.options');
        Route::get('/{contact}',[MobileContactController::class, 'show'])->name('legacy.mobile.contacts.show');
        Route::put('/{contact}',[MobileContactController::class, 'update'])->name('legacy.mobile.contacts.update');
        Route::post('/{contact}/whatsapp', [MobileContactController::class, 'whatsapp'])->name('legacy.mobile.contacts.whatsapp');
        Route::post('/{contact}/matches',  [MobileContactController::class, 'storeMatch'])->name('legacy.mobile.contacts.matches.store');

        Route::get('/{contact}/consent',         [MobileContactComplianceController::class, 'consentIndex'])->name('mobile.contacts.consent.index');
        Route::post('/{contact}/consent',        [MobileContactComplianceController::class, 'consentRecord'])->name('mobile.contacts.consent.record');
        Route::post('/{contact}/consent/revoke', [MobileContactComplianceController::class, 'consentRevoke'])->name('mobile.contacts.consent.revoke');

        Route::get('/{contact}/drive',                       [MobileContactComplianceController::class, 'driveIndex'])->name('mobile.contacts.drive.index');
        Route::post('/{contact}/drive',                      [MobileContactComplianceController::class, 'driveStore'])->name('mobile.contacts.drive.store');
        Route::put('/{contact}/drive/{document}',            [MobileContactComplianceController::class, 'driveUpdate'])->name('mobile.contacts.drive.update');
        Route::get('/{contact}/drive/{document}/download',   [MobileContactComplianceController::class, 'driveDownload'])->name('mobile.contacts.drive.download');
        Route::delete('/{contact}/drive/{document}',         [MobileContactComplianceController::class, 'driveDestroy'])->name('mobile.contacts.drive.destroy');

        Route::get('/{contact}/fica', [MobileContactComplianceController::class, 'ficaIndex'])->name('mobile.contacts.fica.index');
    });

    // LEGACY: remove after 2026-08-21
    Route::prefix('mobile/core-matches')->group(function () {
        Route::get('/settings',               [MobileCoreMatchController::class, 'settings'])->name('legacy.mobile.core-matches.settings');
        Route::get('/',                       [MobileCoreMatchController::class, 'index'])->name('legacy.mobile.core-matches.index');
        Route::get('/{match}',                [MobileCoreMatchController::class, 'show'])->name('legacy.mobile.core-matches.show');
        Route::put('/{match}',                [MobileCoreMatchController::class, 'update'])->name('legacy.mobile.core-matches.update');
        Route::patch('/{match}/status',       [MobileCoreMatchController::class, 'setStatus'])->name('legacy.mobile.core-matches.status');
        Route::post('/{match}/hide/{property}', [MobileCoreMatchController::class, 'toggleHide'])->name('legacy.mobile.core-matches.hide');
        Route::get('/{match}/share-whatsapp',  [MobileCoreMatchController::class, 'shareWhatsApp'])->name('legacy.mobile.core-matches.share-whatsapp.get');
        Route::post('/{match}/share-whatsapp', [MobileCoreMatchController::class, 'shareWhatsApp'])->name('legacy.mobile.core-matches.share-whatsapp.post');
        Route::delete('/{match}',             [MobileCoreMatchController::class, 'destroy'])->name('legacy.mobile.core-matches.destroy');
    });

    // LEGACY: remove after 2026-08-21
    Route::prefix('command-center')->group(function () {
        Route::get('/dashboard',       [CommandCenterApiController::class, 'dashboard'])->name('legacy.command-center.dashboard');
        Route::get('/today',           [CommandCenterApiController::class, 'today'])->name('legacy.command-center.today');
        Route::post('/today/refresh',  [CommandCenterApiController::class, 'todayRefresh'])->name('legacy.command-center.today.refresh');

        Route::get('/calendar',                                       [CommandCenterApiController::class, 'calendarIndex'])->name('legacy.command-center.calendar.index');
        Route::post('/calendar',                                      [CommandCenterApiController::class, 'calendarStore'])->name('legacy.command-center.calendar.store');
        Route::get('/calendar/conflicts',                             [CommandCenterApiController::class, 'calendarConflicts'])->name('legacy.command-center.calendar.conflicts');
        Route::get('/calendar/invitations',                           [CommandCenterApiController::class, 'invitationsIndex'])->name('legacy.command-center.calendar.invitations.index');
        Route::post('/calendar/invitations/{invitation}/respond',     [CommandCenterApiController::class, 'invitationRespond'])->name('legacy.command-center.calendar.invitations.respond');
        Route::post('/calendar/invitations/{invitation}/acknowledge', [CommandCenterApiController::class, 'invitationAcknowledge'])->name('legacy.command-center.calendar.invitations.acknowledge');
        Route::post('/calendar/{calendarEvent}/complete',             [CommandCenterApiController::class, 'calendarComplete'])->name('legacy.command-center.calendar.complete');
        Route::post('/calendar/{calendarEvent}/dismiss',              [CommandCenterApiController::class, 'calendarDismiss'])->name('legacy.command-center.calendar.dismiss');
        Route::put('/calendar/{calendarEvent}',                       [CommandCenterApiController::class, 'calendarUpdate'])->name('legacy.command-center.calendar.update');
        Route::delete('/calendar/{calendarEvent}',                    [CommandCenterApiController::class, 'calendarDestroy'])->name('legacy.command-center.calendar.destroy');

        Route::get('/tasks',                       [CommandCenterApiController::class, 'tasksIndex'])->name('legacy.command-center.tasks.index');
        Route::get('/tasks/archived',              [CommandCenterApiController::class, 'tasksArchived'])->name('legacy.command-center.tasks.archived');
        Route::post('/tasks/archive-done',         [CommandCenterApiController::class, 'tasksArchiveDone'])->name('legacy.command-center.tasks.archive-done');
        Route::post('/tasks/{taskId}/restore',     [CommandCenterApiController::class, 'tasksRestore'])->name('legacy.command-center.tasks.restore');
        Route::post('/tasks',                      [CommandCenterApiController::class, 'tasksStore'])->name('legacy.command-center.tasks.store');
        Route::post('/tasks/{task}/complete',      [CommandCenterApiController::class, 'tasksComplete'])->name('legacy.command-center.tasks.complete');
        Route::patch('/tasks/{task}/status',       [CommandCenterApiController::class, 'tasksUpdateStatus'])->name('legacy.command-center.tasks.status');
        Route::put('/tasks/{task}',                [CommandCenterApiController::class, 'tasksUpdate'])->name('legacy.command-center.tasks.update');
        Route::delete('/tasks/{task}',             [CommandCenterApiController::class, 'tasksDestroy'])->name('legacy.command-center.tasks.destroy');

        Route::get('/tasks/{task}/notes',           [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'index'])->name('legacy.command-center.tasks.notes.index');
        Route::post('/tasks/{task}/notes',          [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'store'])->name('legacy.command-center.tasks.notes.store');
        Route::put('/tasks/{task}/notes/{note}',    [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'update'])->name('legacy.command-center.tasks.notes.update');
        Route::delete('/tasks/{task}/notes/{note}', [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'destroy'])->name('legacy.command-center.tasks.notes.destroy');

        Route::get('/tasks/{task}/checklist',             [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'checklistIndex'])->name('legacy.command-center.tasks.checklist.index');
        Route::post('/tasks/{task}/checklist',            [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'checklistStore'])->name('legacy.command-center.tasks.checklist.store');
        Route::patch('/tasks/{task}/checklist/{itemId}',  [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'checklistUpdate'])->name('legacy.command-center.tasks.checklist.update');
        Route::delete('/tasks/{task}/checklist/{itemId}', [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'checklistDestroy'])->name('legacy.command-center.tasks.checklist.destroy');

        Route::post('/resolve-task/{task}',          [CommandCenterApiController::class, 'resolveTask'])->name('legacy.command-center.resolve-task');
        Route::post('/resolve-event/{calendarEvent}',[CommandCenterApiController::class, 'resolveEvent'])->name('legacy.command-center.resolve-event');

        Route::get('/user-settings', [CommandCenterApiController::class, 'settingsIndex'])->name('legacy.command-center.user-settings.index');
        Route::put('/user-settings', [CommandCenterApiController::class, 'settingsUpdate'])->name('legacy.command-center.user-settings.update');
    });

    // LEGACY: remove after 2026-08-21
    Route::get('/notifications',                 [ApiNotificationController::class, 'index'])->name('legacy.notifications.index');
    Route::post('/notifications/{id}/read',      [ApiNotificationController::class, 'markRead'])->name('legacy.notifications.read');
    Route::post('/notifications/mark-all-read',  [ApiNotificationController::class, 'markAllRead'])->name('legacy.notifications.mark-all-read');
    Route::get('/notifications/overdue',         [ApiNotificationController::class, 'overdue'])->name('legacy.notifications.overdue');

    Route::get('/notification-preferences',  [NotificationPreferenceController::class, 'index'])->name('legacy.notification-preferences.index');
    Route::put('/notification-preferences',  [NotificationPreferenceController::class, 'update'])->name('legacy.notification-preferences.update');

    Route::post('/device-tokens',           [DeviceTokenController::class, 'store'])->name('legacy.device-tokens.store');
    Route::delete('/device-tokens/{token}', [DeviceTokenController::class, 'destroy'])->name('legacy.device-tokens.destroy');

    // LEGACY: remove after 2026-08-21
    Route::get('/me/agent-qr', [\App\Http\Controllers\Api\V1\AgentQrController::class, 'mine'])
        ->name('legacy.me.agent-qr');
});
