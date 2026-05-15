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
use App\Http\Controllers\Api\MobileCoreMatchController;
use App\Http\Controllers\Api\PropertyPullController;
use App\Http\Controllers\Api\V1\ClientAuthController;
use App\Http\Controllers\Api\V1\ClientPortalController;
use App\Http\Controllers\FaultReportController;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

Route::post('/login', function (Request $request) {
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
});

Route::post('/fault-report', [FaultReportController::class, 'capture'])
    ->middleware('throttle:30,1');

// Private Property webhook — leads delivered by PP.
// Authentication is HMAC (X-Signature header) verified inside the controller.
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

Route::middleware('auth:sanctum')->group(function () {
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
    });

    Route::post('/logout', function (Request $request) {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    });

    Route::post('/prospecting/import', [ProspectingApiController::class, 'import']);
    Route::get('/prospecting/check-search', [ProspectingApiController::class, 'checkSearch']);

    Route::post('/properties/pull-from-portal', [PropertyPullController::class, 'pullFromPortal']);
    Route::get('/properties/{propertyId}/pull-status', [PropertyPullController::class, 'pullStatus']);

    // ── Mobile Properties ────────────────────────────────────────
    Route::prefix('mobile/properties')->group(function () {
        Route::get('/',         [MobilePropertyController::class, 'index']);
        Route::post('/',        [MobilePropertyController::class, 'store']);

        // Static catalogs (must be defined BEFORE /{property} so they
        // aren't treated as a property id by the route binding).
        Route::get('/options',        [MobilePropertyController::class, 'options']);
        Route::get('/spaces/catalog', [MobilePropertyController::class, 'spacesCatalog']);

        Route::get('/{property}',  [MobilePropertyController::class, 'show']);
        Route::put('/{property}',  [MobilePropertyController::class, 'update']);
        Route::post('/{property}/images', [MobilePropertyController::class, 'uploadImage']);

        // Overview screen (everything the Overview tab needs in one call,
        // including the live portal placements)
        Route::get('/{property}/overview', [MobilePropertyController::class, 'overview']);

        // Gallery tags (derived live from this property's spaces + custom tags)
        Route::get('/{property}/gallery/tags',          [MobilePropertyController::class, 'galleryTags']);
        Route::post('/{property}/gallery/tags',         [MobilePropertyController::class, 'addCustomTag']);
        Route::delete('/{property}/gallery/tags',       [MobilePropertyController::class, 'removeCustomTag']);

        // Spaces & features (Bedroom, Bathroom, Kitchen, …)
        Route::get('/{property}/spaces', [MobilePropertyController::class, 'spacesShow']);
        Route::put('/{property}/spaces', [MobilePropertyController::class, 'spacesUpdate']);
    });

    // ── Mobile Contacts ─────────────────────────────────────────
    Route::prefix('mobile/contacts')->group(function () {
        Route::get('/',         [MobileContactController::class, 'index']);
        Route::post('/',        [MobileContactController::class, 'store']);
        Route::get('/options',  [MobileContactController::class, 'options']);
        Route::get('/{contact}',[MobileContactController::class, 'show']);
        Route::put('/{contact}',[MobileContactController::class, 'update']);
        Route::post('/{contact}/whatsapp', [MobileContactController::class, 'whatsapp']);
        Route::post('/{contact}/matches',  [MobileContactController::class, 'storeMatch']);
    });

    // ── Mobile Core Matches ─────────────────────────────────────
    Route::prefix('mobile/core-matches')->group(function () {
        Route::get('/settings',               [MobileCoreMatchController::class, 'settings']);
        Route::get('/',                       [MobileCoreMatchController::class, 'index']);
        Route::get('/{match}',                [MobileCoreMatchController::class, 'show']);
        Route::put('/{match}',                [MobileCoreMatchController::class, 'update']);
        Route::patch('/{match}/status',       [MobileCoreMatchController::class, 'setStatus']);
        Route::post('/{match}/hide/{property}', [MobileCoreMatchController::class, 'toggleHide']);
        Route::get('/{match}/share-whatsapp',  [MobileCoreMatchController::class, 'shareWhatsApp']);
        Route::post('/{match}/share-whatsapp', [MobileCoreMatchController::class, 'shareWhatsApp']);
        Route::delete('/{match}',             [MobileCoreMatchController::class, 'destroy']);
    });

    // ── Command Center ────────────────────────────────────────────
    Route::prefix('command-center')->group(function () {
        Route::get('/dashboard', [CommandCenterApiController::class, 'dashboard']);
        Route::get('/today', [CommandCenterApiController::class, 'today']);
        Route::post('/today/refresh', [CommandCenterApiController::class, 'todayRefresh']);

        Route::get('/calendar', [CommandCenterApiController::class, 'calendarIndex']);
        Route::post('/calendar', [CommandCenterApiController::class, 'calendarStore']);
        // Conflict + invitations MUST be declared before /calendar/{calendarEvent} wildcard
        Route::get('/calendar/conflicts', [CommandCenterApiController::class, 'calendarConflicts']);
        Route::get('/calendar/invitations', [CommandCenterApiController::class, 'invitationsIndex']);
        Route::post('/calendar/invitations/{invitation}/respond', [CommandCenterApiController::class, 'invitationRespond']);
        Route::post('/calendar/invitations/{invitation}/acknowledge', [CommandCenterApiController::class, 'invitationAcknowledge']);
        Route::post('/calendar/{calendarEvent}/complete', [CommandCenterApiController::class, 'calendarComplete']);
        Route::post('/calendar/{calendarEvent}/dismiss', [CommandCenterApiController::class, 'calendarDismiss']);
        Route::put('/calendar/{calendarEvent}', [CommandCenterApiController::class, 'calendarUpdate']);
        Route::delete('/calendar/{calendarEvent}', [CommandCenterApiController::class, 'calendarDestroy']);

        Route::get('/tasks', [CommandCenterApiController::class, 'tasksIndex']);
        Route::get('/tasks/archived', [CommandCenterApiController::class, 'tasksArchived']);
        Route::post('/tasks/archive-done', [CommandCenterApiController::class, 'tasksArchiveDone']);
        Route::post('/tasks/{taskId}/restore', [CommandCenterApiController::class, 'tasksRestore']);
        Route::post('/tasks', [CommandCenterApiController::class, 'tasksStore']);
        Route::post('/tasks/{task}/complete', [CommandCenterApiController::class, 'tasksComplete']);
        Route::patch('/tasks/{task}/status', [CommandCenterApiController::class, 'tasksUpdateStatus']);
        Route::put('/tasks/{task}', [CommandCenterApiController::class, 'tasksUpdate']);
        Route::delete('/tasks/{task}', [CommandCenterApiController::class, 'tasksDestroy']);

        // ── Task Notes (threaded) ──
        Route::get('/tasks/{task}/notes',           [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'index']);
        Route::post('/tasks/{task}/notes',          [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'store']);
        Route::put('/tasks/{task}/notes/{note}',    [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'update']);
        Route::delete('/tasks/{task}/notes/{note}', [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'destroy']);

        // ── Task Checklist ──
        Route::get('/tasks/{task}/checklist',             [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'checklistIndex']);
        Route::post('/tasks/{task}/checklist',            [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'checklistStore']);
        Route::patch('/tasks/{task}/checklist/{itemId}',  [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'checklistUpdate']);
        Route::delete('/tasks/{task}/checklist/{itemId}', [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'checklistDestroy']);

        Route::post('/resolve-task/{task}', [CommandCenterApiController::class, 'resolveTask']);
        Route::post('/resolve-event/{calendarEvent}', [CommandCenterApiController::class, 'resolveEvent']);

        Route::get('/user-settings', [CommandCenterApiController::class, 'settingsIndex']);
        Route::put('/user-settings', [CommandCenterApiController::class, 'settingsUpdate']);
    });

    // ── Notifications (mobile) ──────────────────────────────────
    Route::get('/notifications',                 [ApiNotificationController::class, 'index']);
    Route::post('/notifications/{id}/read',      [ApiNotificationController::class, 'markRead']);
    Route::post('/notifications/mark-all-read',  [ApiNotificationController::class, 'markAllRead']);
    Route::get('/notifications/overdue',         [ApiNotificationController::class, 'overdue']);

    Route::get('/notification-preferences',  [NotificationPreferenceController::class, 'index']);
    Route::put('/notification-preferences',  [NotificationPreferenceController::class, 'update']);

    Route::post('/device-tokens',           [DeviceTokenController::class, 'store']);
    Route::delete('/device-tokens/{token}', [DeviceTokenController::class, 'destroy']);

    // Agent's own onboarding QR — spec: .ai/specs/agent-qr-onboarding.md
    Route::get('/me/agent-qr', [\App\Http\Controllers\Api\V1\AgentQrController::class, 'mine'])
        ->name('me.agent-qr');
});
