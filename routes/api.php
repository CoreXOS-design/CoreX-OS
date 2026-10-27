<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Api\CommandCenterApiController;
use App\Http\Controllers\CommandCenter\CalendarController as CommandCenterCalendarController;
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
use App\Http\Controllers\Api\V1\ClientSellerInsightsController;
use App\Http\Controllers\Api\V1\ClientTenantRentalsController;
use App\Http\Controllers\Api\V1\ClientLandlordRentalsController;
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

    if (! $user) {
        return response()->json([
            'message' => 'No user account found for this email.',
            'code'    => 'user_not_found',
        ], 404);
    }

    if (! Hash::check($request->password, $user->password)) {
        return response()->json([
            'message' => 'Incorrect password.',
            'code'    => 'invalid_password',
        ], 401);
    }

    // Mobile "Delete my account" (Apple 5.1.1(v)) — checked AFTER the password
    // so a wrong-password guess can never be used to probe whether an
    // account has deleted app access. See .ai/specs/mobile-app-access.md §4.1.
    if (! $user->hasAppAccess()) {
        return response()->json([
            'message' => 'This account has been deleted.',
            'code'    => 'account_deleted',
        ], 403);
    }

    // AT-423 — an admin reset this person's password to a temporary one. The app has no
    // "choose a new password" screen, so they must do that on the website first.
    // Checked AFTER the password, like the app-access gate above. Spec: one-email-sub-users.md §6.5.
    if ($user->must_change_password) {
        return response()->json([
            'message' => 'Please sign in on the website first to choose a new password.',
            'code'    => 'password_change_required',
        ], 403);
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

// ════════════════════════════════════════════════════════════════
// API v1 — Mobile app config / forced-update gate
// UNAUTHENTICATED on purpose: a build old enough to need forcing may not be
// able to authenticate, so this must answer on a cold start with no token.
// See MobileAppConfigController for the DevSetting keys that drive it.
// ════════════════════════════════════════════════════════════════
Route::get('v1/mobile/app-config', [\App\Http\Controllers\Api\V1\MobileAppConfigController::class, 'show'])
    ->name('v1.mobile.app-config');

// AT-445 — EnsureFrontendRequestsAreStateful is prepended here, scoped ONLY
// to the client-auth/client groups (NOT re-added to the global 'api'
// middleware group, which bootstrap/app.php deliberately strips it from
// to keep the mobile app's bearer-token flow immune to Origin/Referer
// tricks). Scoping it to exactly these two groups lets the tenant/landlord
// WEB portal authenticate via a same-origin session cookie against the
// SAME endpoints, while every other /api route — including the rest of
// this mobile client API — is completely unaffected.
Route::prefix('v1/client-auth')->middleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class])->group(function () {
    Route::post('/lookup',          [ClientAuthController::class, 'lookup'])->name('client-auth.lookup');
    Route::post('/otp/send',        [ClientAuthController::class, 'sendOtp'])->name('client-auth.otp.send');
    Route::post('/otp/verify',      [ClientAuthController::class, 'verifyOtp'])->name('client-auth.otp.verify');
    Route::post('/login',           [ClientAuthController::class, 'login'])->name('client-auth.login');
    // §28.5 - a portal tab with no token of its own asks for one (only for a person who already signed in on this browser)
    Route::post('/tab-token',       [ClientAuthController::class, 'tabToken'])->middleware('throttle:60,1')->name('client-auth.tab-token');
    Route::post('/password/forgot', [ClientAuthController::class, 'forgotPassword'])->name('client-auth.password.forgot');

    // Agent QR onboarding — spec: .ai/specs/agent-qr-onboarding.md
    Route::get('/agent-qr/{slug}',           [\App\Http\Controllers\Api\V1\AgentQrController::class, 'show'])
        ->name('client-auth.agent-qr.show');
    Route::post('/agent-qr/{slug}/register', [\App\Http\Controllers\Api\V1\AgentQrController::class, 'register'])
        ->name('client-auth.agent-qr.register');

    // Activation token OR client sanctum token (both checked in controller)
    Route::middleware('client.auth')->group(function () {
        Route::post('/password/set', [ClientAuthController::class, 'setPassword'])->name('client-auth.password.set');
    });

    // Client sanctum token only
    Route::middleware(['client.auth', 'client.ability', 'throttle:client-portal', 'portal.same-login'])->group(function () {
        Route::post('/password/change', [ClientAuthController::class, 'changePassword'])->name('client-auth.password.change');
        Route::post('/agency/select',   [ClientAuthController::class, 'selectAgency'])->name('client-auth.agency.select');
        Route::post('/logout',          [ClientAuthController::class, 'logout'])->name('client-auth.logout');
        Route::delete('/account',       [ClientAuthController::class, 'deleteAccount'])->name('client-auth.account.delete');
    });
});

Route::prefix('v1/client')->middleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class, 'client.auth', 'client.ability', 'throttle:client-portal'])->group(function () {
    Route::get('/me',                 [ClientPortalController::class, 'me'])->name('client.me');
    // AT-508 (P1) — tenant / owner phone registers for push. Spec: .ai/specs/at-508-mobile-rentals.md
    Route::post('/device-tokens',           [\App\Http\Controllers\Api\V1\ClientDeviceTokenController::class, 'store'])->name('client.device-tokens.store');
    Route::delete('/device-tokens/{token}', [\App\Http\Controllers\Api\V1\ClientDeviceTokenController::class, 'destroy'])->name('client.device-tokens.destroy');
    Route::get('/match-options',      [ClientPortalController::class, 'matchOptions'])->name('client.match-options');

    Route::get('/matches',                                  [ClientPortalController::class, 'matches'])->name('client.matches');
    Route::post('/matches',                                 [ClientPortalController::class, 'matchCreate'])->name('client.matches.create');
    Route::get('/matches/{match}',                          [ClientPortalController::class, 'matchShow'])->name('client.matches.show');
    Route::put('/matches/{match}',                          [ClientPortalController::class, 'matchUpdate'])->name('client.matches.update');
    Route::post('/matches/{match}/feedback/{property}',     [ClientPortalController::class, 'matchFeedback'])->name('client.matches.feedback');
    Route::post('/matches/{match}/view/{property}',         [ClientPortalController::class, 'matchView'])->name('client.matches.view');

    Route::get('/properties/{property}',  [ClientPortalController::class, 'propertyShow'])->name('client.properties.show');

    // Consent self-service — the client views + sets their own POPIA/CPA consent
    // (the same ledger the agent sees on the Contact page). Spec: .ai/specs/contact-consent.md §6.
    Route::get('/consent',  [ClientPortalController::class, 'consentIndex'])->name('client.consent.index');
    Route::post('/consent', [ClientPortalController::class, 'consentUpdate'])->name('client.consent.update');

    // Testimonials — the client leaves feedback about their agent from the app.
    // Captured unpublished; syncs to the agent's Contact tab + notifies them.
    // Spec: .ai/specs/testimonials.md §13.
    Route::get('/testimonials',  [ClientPortalController::class, 'testimonials'])->name('client.testimonials.index');
    Route::post('/testimonials', [ClientPortalController::class, 'testimonialCreate'])->name('client.testimonials.create');

    // Seller-side property intelligence — client sees the same seller-facing
    // dataset as the Seller Live Link page for properties they own/sell.
    // Spec: .ai/specs/client-seller-insights.md
    Route::get('/seller-properties',                       [ClientSellerInsightsController::class, 'index'])->name('client.seller-properties.index');
    Route::get('/seller-properties/{property}/insights',   [ClientSellerInsightsController::class, 'show'])->name('client.seller-properties.insights');

    // AT-445 — .ai/specs/rental-portal-access.md §9. Tenant rentals.
    // §22 — `portal.once`: every state-changing request in the tenant / owner portal does its work once (double tap = one record).
    Route::prefix('rentals')->name('client.rentals.')->middleware(['portal.same-login', 'portal.role', 'portal.once'])->group(function () {
        // §22 — the agency's logo / name for the portal header (any signed-in tenant or owner).
        Route::get('/branding', [ClientTenantRentalsController::class, 'branding'])->name('branding');
        // §22 — a PDF / document on a fault type's first-aid panel (gated to the person's own agency's active fault types).
        Route::get('/fault-type-documents/{document}/file', [\App\Http\Controllers\Api\V1\ClientFaultTypeDocumentController::class, 'file'])->whereNumber('document')->name('fault-type-documents.file');
        Route::middleware('rental-portal.enabled:tenant')->group(function () {
            // §20 — the portal home: agent contact, inspection dates, lease end / renewal status.
            Route::get('/overview', [ClientTenantRentalsController::class, 'overview'])->name('overview');
            // AT-508 P1 — finished work waiting for the tenant's check (badge + list) and the tenant's own phone-notification choices.
            Route::get('/waiting', [\App\Http\Controllers\Api\V1\ClientRentalWaitingController::class, 'tenant'])->name('waiting');
            // AT-508 P4 - the tenant's My Lease card (lease facts only; nothing about the lessor).
            Route::get('/my-lease', [\App\Http\Controllers\Api\V1\ClientRentalMyLeaseController::class, 'tenant'])->name('my-lease');
            Route::get('/push-preferences', [\App\Http\Controllers\Api\V1\ClientRentalPushController::class, 'tenantShow'])->name('push-preferences.show');
            Route::put('/push-preferences', [\App\Http\Controllers\Api\V1\ClientRentalPushController::class, 'tenantUpdate'])->name('push-preferences.update');
            // lease-notices.md - the tenant gives notice (it goes to the agent for review; nothing goes to the landlord)
            Route::get('/notices', [\App\Http\Controllers\Api\V1\ClientRentalNoticesController::class, 'tenantIndex'])->name('notices.index');
            Route::post('/notices', [\App\Http\Controllers\Api\V1\ClientRentalNoticesController::class, 'tenantStore'])->name('notices.store');
            Route::get('/leases', [ClientTenantRentalsController::class, 'leases'])->name('leases.index');
            Route::get('/leases/{lease}', [ClientTenantRentalsController::class, 'leaseShow'])->name('leases.show');
            Route::get('/documents', [ClientTenantRentalsController::class, 'documents'])->name('documents.index');
            // §19 — the one authorised way to open a document: only if it is in the tenant's OWN list.
            Route::get('/documents/{document}/file', [ClientTenantRentalsController::class, 'documentFile'])->whereNumber('document')->name('documents.file');
            Route::get('/inspections', [ClientTenantRentalsController::class, 'inspections'])->name('inspections.index');
            // AT-508 - open a SENT move-in / move-out inspection (rooms, conditions, notes, photos, who signed). Read-only.
            Route::get('/inspections/{inspection}', [\App\Http\Controllers\Api\V1\ClientRentalInspectionController::class, 'tenantShow'])->whereNumber('inspection')->name('inspections.show');
            Route::get('/inventories', [ClientTenantRentalsController::class, 'inventories'])->name('inventories.index');
            Route::get('/properties/{property}/fault-types', [ClientTenantRentalsController::class, 'faultTypes'])->name('fault-types.index');
            Route::post('/properties/{property}/fault-reports', [ClientTenantRentalsController::class, 'faultReportStore'])->middleware('portal.photos')->name('fault-reports.store');
            Route::get('/fault-reports', [ClientTenantRentalsController::class, 'faultReports'])->name('fault-reports.index');
            Route::get('/fault-reports/{faultReport}', [ClientTenantRentalsController::class, 'faultReportShow'])->name('fault-reports.show');
            // Audit fixes 19 Oct 2026 (C-A5 / D20): add a note / photos to an open fault, or withdraw it (replay-safe: the group carries portal.once)
            Route::post('/fault-reports/{faultReport}/notes', [ClientTenantRentalsController::class, 'faultReportAddTo'])->whereNumber('faultReport')->middleware('portal.photos')->name('fault-reports.add-to');
            Route::post('/fault-reports/{faultReport}/withdraw', [ClientTenantRentalsController::class, 'faultReportWithdraw'])->whereNumber('faultReport')->name('fault-reports.withdraw');
            Route::get('/work-orders/{workOrder}', [ClientTenantRentalsController::class, 'workOrderShow'])->name('work-orders.show');
            // DEPRECATED (audit fixes D19): delegates to the same rules as /progress and /completion-response; new clients use those two
            Route::post('/work-orders/{workOrder}/confirm', [ClientTenantRentalsController::class, 'workOrderConfirm'])->middleware('portal.photos')->name('work-orders.confirm');
            // W4 (8 Oct 2026): the job card is INTERNAL - tenants and owners see the work order's progress, never the job card.
            // §17.21.1 — Build 3 only (tenant: work-orders index, completion-response).
            // BUILD 3 BEGIN — tenant maintenance-flow routes (.ai/specs/rental-work-orders.md §17.21.5)
            // §17.3.5 — the portal's Jobs are work orders: the tenant's list, and their answer to "is this finished?".
            Route::get('/work-orders', [\App\Http\Controllers\Api\V1\ClientRentalWorkOrdersController::class, 'tenantIndex'])->name('work-orders.index');
            Route::post('/work-orders/{workOrder}/completion-response', [\App\Http\Controllers\Api\V1\ClientRentalWorkOrdersController::class, 'completionResponse'])->middleware('portal.photos')->name('work-orders.completion-response');
            // spec 22.18 (Johan, 14 Oct 2026): with a tenant in the property THEY tell us the work has started / is finished / is not fixed (the owner only watches)
            Route::post('/work-orders/{workOrder}/progress', [\App\Http\Controllers\Api\V1\ClientRentalWorkOrdersController::class, 'tenantProgress'])->whereNumber('workOrder')->middleware('portal.photos')->name('work-orders.progress');
            // BUILD 3 END
        });

        // Landlord rentals.
        Route::prefix('landlord')->name('landlord.')->middleware('rental-portal.enabled:landlord')->group(function () {
            Route::get('/overview', [ClientLandlordRentalsController::class, 'overview'])->name('overview');
            // AT-508 P1 — decisions waiting for the owner (badge + list) and the owner's own phone-notification choices.
            Route::get('/waiting', [\App\Http\Controllers\Api\V1\ClientRentalWaitingController::class, 'landlord'])->name('waiting');
            // AT-508 P4 - the owner's My Lease card, one block per property.
            Route::get('/my-lease', [\App\Http\Controllers\Api\V1\ClientRentalMyLeaseController::class, 'landlord'])->name('my-lease');
            Route::get('/push-preferences', [\App\Http\Controllers\Api\V1\ClientRentalPushController::class, 'landlordShow'])->name('push-preferences.show');
            Route::put('/push-preferences', [\App\Http\Controllers\Api\V1\ClientRentalPushController::class, 'landlordUpdate'])->name('push-preferences.update');
            // lease-notices.md - the owner gives notice (it goes to the agent for review; nothing goes to the tenant)
            Route::get('/notices', [\App\Http\Controllers\Api\V1\ClientRentalNoticesController::class, 'landlordIndex'])->name('notices.index');
            Route::post('/notices', [\App\Http\Controllers\Api\V1\ClientRentalNoticesController::class, 'landlordStore'])->name('notices.store');
            Route::get('/properties', [ClientLandlordRentalsController::class, 'properties'])->name('properties.index');
            Route::get('/properties/{property}', [ClientLandlordRentalsController::class, 'propertyShow'])->name('properties.show');
            // §15 (AT-447 follow-up) — "Request work / report a problem."
            Route::get('/properties/{property}/fault-types', [ClientLandlordRentalsController::class, 'faultTypes'])->name('fault-types.index');
            // rentals-faults-work-orders.md section 22 - the owner's "How I want faults handled" per property.
            Route::get('/properties/{property}/fault-settings', [ClientLandlordRentalsController::class, 'faultSettingsShow'])->whereNumber('property')->name('fault-settings.show');
            Route::post('/properties/{property}/fault-settings', [ClientLandlordRentalsController::class, 'faultSettingsSave'])->whereNumber('property')->name('fault-settings.save');
            Route::post('/properties/{property}/fault-settings/change-request', [ClientLandlordRentalsController::class, 'faultSettingsChangeRequest'])->whereNumber('property')->name('fault-settings.change-request');
            Route::post('/properties/{property}/fault-reports', [ClientLandlordRentalsController::class, 'faultReportStore'])->middleware('portal.photos')->name('fault-reports.store');
            Route::get('/fault-reports', [ClientLandlordRentalsController::class, 'faultReports'])->name('fault-reports.index');
            Route::get('/fault-reports/{faultReport}', [ClientLandlordRentalsController::class, 'faultReportShow'])->whereNumber('faultReport')->name('fault-reports.show');
            Route::post('/fault-reports/{faultReport}/repair-request', [ClientLandlordRentalsController::class, 'faultReportRepairRequest'])->whereNumber('faultReport')->name('fault-reports.repair-request');
            Route::post('/fault-reports/{faultReport}/query', [ClientLandlordRentalsController::class, 'faultReportQuery'])->whereNumber('faultReport')->name('fault-reports.query');
            Route::post('/fault-reports/{faultReport}/stop', [ClientLandlordRentalsController::class, 'faultReportStop'])->whereNumber('faultReport')->name('fault-reports.stop');
            Route::post('/fault-reports/{faultReport}/resume', [ClientLandlordRentalsController::class, 'faultReportResume'])->whereNumber('faultReport')->name('fault-reports.resume');
            Route::post('/fault-reports/{faultReport}/decision', [ClientLandlordRentalsController::class, 'faultReportDecision'])->name('fault-reports.decision');
            Route::get('/work-orders', [ClientLandlordRentalsController::class, 'workOrders'])->name('work-orders.index');
            Route::post('/work-orders/{workOrder}/decision', [ClientLandlordRentalsController::class, 'workOrderDecision'])->name('work-orders.decision');
            // W6 (8 Oct 2026): the owner may book the appointment and report progress on their work order.
            Route::post('/work-orders/{workOrder}/appointment', [ClientLandlordRentalsController::class, 'workOrderAppointment'])->whereNumber('workOrder')->name('work-orders.appointment');
            Route::post('/work-orders/{workOrder}/progress', [ClientLandlordRentalsController::class, 'workOrderProgress'])->whereNumber('workOrder')->name('work-orders.progress');
            Route::get('/inspections', [ClientLandlordRentalsController::class, 'inspections'])->name('inspections.index');
            // AT-508 - open a SENT move-in / move-out inspection of the owner's property. Read-only.
            Route::get('/inspections/{inspection}', [\App\Http\Controllers\Api\V1\ClientRentalInspectionController::class, 'landlordShow'])->whereNumber('inspection')->name('inspections.show');
            Route::get('/documents', [ClientLandlordRentalsController::class, 'documents'])->name('documents.index');
            Route::get('/documents/{document}/file', [ClientLandlordRentalsController::class, 'documentFile'])->whereNumber('document')->name('documents.file');
            Route::get('/decisions', [ClientLandlordRentalsController::class, 'decisions'])->name('decisions.index');
            // §17.21.1 — Build 2 (variation decision, decisions list) and Build 3 (work-orders/{id}) add routes only between their own markers.
            // BUILD 2 BEGIN — landlord variation decision (.ai/specs/rental-work-orders.md §17.21.5)
            // §17.7.4 — approve / decline extra work beyond the owner's agreed terms (body: decision, revision, note). The open ones are listed by GET decisions above.
            Route::post('/variations/{variation}/decision', [ClientLandlordRentalsController::class, 'variationDecision'])->name('variations.decision');
            // BUILD 2 END
            // BUILD 3 BEGIN — landlord work-order show (.ai/specs/rental-work-orders.md §17.21.5)
            Route::get('/work-orders/{workOrder}', [\App\Http\Controllers\Api\V1\ClientRentalWorkOrdersController::class, 'landlordShow'])->whereNumber('workOrder')->name('work-orders.show');
            // §17.31 — a supplier invoice the agent has chosen to share with the owner. Tenants never reach this: the work order is resolved through the OWNER scope.
            Route::get('/work-orders/{workOrder}/invoices/{invoice}/file', [\App\Http\Controllers\Api\V1\ClientRentalWorkOrdersController::class, 'landlordInvoiceFile'])->whereNumber(['workOrder', 'invoice'])->name('work-orders.invoices.file');
            // P2 (9 Oct 2026): the owner can open the selected quote's document - only when no agency fee sits on top of it (the document shows the contractor's own total).
            Route::get('/work-orders/{workOrder}/quote-file', [\App\Http\Controllers\Api\V1\ClientRentalWorkOrdersController::class, 'landlordQuoteFile'])->whereNumber('workOrder')->name('work-orders.quote-file');
            // BUILD 3 END
        });
    });
});

// ════════════════════════════════════════════════════════════════
// Agency Public API (website API) — external agency websites
// Authenticated by a per-website API key (agency-api guard). Gated by the
// master "website is live" switch + per-route scopes + per-key rate limit.
// Auto-listed under the "Website" section at /admin/api.
// Spec: .ai/specs/agency-public-api.md §5
// ════════════════════════════════════════════════════════════════
Route::prefix('v1/website')
    ->middleware(['auth:agency-api', 'website.live', 'throttle:website-api'])
    ->group(function () {
        Route::get('/ping',  [\App\Http\Controllers\Api\V1\Website\AgencyController::class, 'ping'])->name('v1.website.ping');

        Route::middleware('website.scope:agency:read')->group(function () {
            Route::get('/agency', [\App\Http\Controllers\Api\V1\Website\AgencyController::class, 'show'])->name('v1.website.agency.show');
        });

        Route::middleware('website.scope:listings:read')->group(function () {
            Route::get('/listings',          [\App\Http\Controllers\Api\V1\Website\ListingsController::class, 'index'])->name('v1.website.listings.index');
            Route::get('/listings/{idOrRef}', [\App\Http\Controllers\Api\V1\Website\ListingsController::class, 'show'])->name('v1.website.listings.show');
            // Sold showcase / sold map - frozen sales from sold_stock (.ai/specs/sold-stock.md section 6).
            Route::get('/sold', [\App\Http\Controllers\Api\V1\Website\SoldStockController::class, 'index'])->name('v1.website.sold.index');
        });

        Route::middleware('website.scope:agents:read')->group(function () {
            Route::get('/agents',       [\App\Http\Controllers\Api\V1\Website\AgentsController::class, 'index'])->name('v1.website.agents.index');
            Route::get('/agents/{id}',  [\App\Http\Controllers\Api\V1\Website\AgentsController::class, 'show'])->name('v1.website.agents.show');
        });

        Route::middleware('website.scope:branches:read')->group(function () {
            Route::get('/branches',      [\App\Http\Controllers\Api\V1\Website\BranchesController::class, 'index'])->name('v1.website.branches.index');
            Route::get('/branches/{id}', [\App\Http\Controllers\Api\V1\Website\BranchesController::class, 'show'])->name('v1.website.branches.show');
        });

        Route::middleware('website.scope:testimonials:read')->group(function () {
            Route::get('/testimonials',      [\App\Http\Controllers\Api\V1\Website\TestimonialsController::class, 'index'])->name('v1.website.testimonials.index');
            Route::get('/testimonials/{id}', [\App\Http\Controllers\Api\V1\Website\TestimonialsController::class, 'show'])->name('v1.website.testimonials.show');
        });

        Route::middleware('website.scope:articles:read')->group(function () {
            Route::get('/articles',      [\App\Http\Controllers\Api\V1\Website\ArticlesController::class, 'index'])->name('v1.website.articles.index');
            Route::get('/articles/{id}', [\App\Http\Controllers\Api\V1\Website\ArticlesController::class, 'show'])->name('v1.website.articles.show');
        });

        // Inbound lead capture (write). The website POSTs property enquiries here;
        // they land in the shared portal_leads pipeline. Gated by leads:write.
        Route::middleware('website.scope:leads:write')->group(function () {
            Route::post('/leads', [\App\Http\Controllers\Api\V1\Website\LeadsController::class, 'store'])->name('v1.website.leads.store');
        });

        // Inbound listing engagement counters (write). The website batches page
        // views / impressions / contact clicks locally and POSTs them hourly —
        // never per page view. Gated by stats:write.
        // Spec: .ai/specs/website-listing-stats.md §3.1
        Route::middleware('website.scope:stats:write')->group(function () {
            Route::post('/listings/stats', [\App\Http\Controllers\Api\V1\Website\ListingStatsController::class, 'store'])->name('v1.website.listings.stats.store');
        });
    });

// ════════════════════════════════════════════════════════════════
// Demo Access Control (AT-230) — the demo host's control API.
// SERVED BY PRIMARY. Called by demo1.corexos.co.za, whose own database is
// destroyed every 3 days and therefore cannot hold the durable records.
//
// Authenticated by the UNIVERSAL DEMO CONNECTOR (demo.connector middleware), not
// by the agency-api guard. There is exactly ONE demo instance, so there is exactly
// one credential — minted on Live at Dev Settings → Demo Access → Connection, and
// pasted into the demo's own Demo Connection page.
//
// NOT an AgencyApiKey: that guard resolves an AGENCY from the key and hands it to
// AgencyScope as the tenant. Correct for an agency's public website; wrong here —
// demo access grants are RR Technologies' sales data, not tenant data. Hanging
// them off an arbitrary agency would be a lie in the data model, and would put a
// grantable "demo:*" scope in the agency key UI.
//
// PREFIX IS v1/demo-access, NOT v1/demo — v1/demo is already taken by the
// mobile app's demo-login group above (api.demo.status / api.demo.login).
//
// Spec: .ai/specs/demo-access-control.md §5, §5.1
// ════════════════════════════════════════════════════════════════
Route::prefix('v1/demo-access')
    ->middleware(['demo.connector', 'throttle:website-api'])
    ->group(function () {
        // Reachability probe. Powers the "Test connection" button on the demo's
        // Demo Connection page, so a misconfigured token is caught THERE — at the
        // moment someone pastes it — rather than by a prospect hitting a gate that
        // (correctly) fails closed and looks like an outage.
        Route::get('/ping', [\App\Http\Controllers\Api\V1\DemoAccessApiController::class, 'ping'])->name('v1.demo-access.ping');

        // Gate: verify a credential, re-check a session, record acceptance.
        Route::post('/verify',         [\App\Http\Controllers\Api\V1\DemoAccessApiController::class, 'verify'])->name('v1.demo-access.verify');
        Route::get('/session/{token}', [\App\Http\Controllers\Api\V1\DemoAccessApiController::class, 'session'])->name('v1.demo-access.session');
        Route::post('/accept-tnc',     [\App\Http\Controllers\Api\V1\DemoAccessApiController::class, 'acceptTnc'])->name('v1.demo-access.accept-tnc');

        // Telemetry.
        Route::post('/page-view', [\App\Http\Controllers\Api\V1\DemoAccessApiController::class, 'pageView'])->name('v1.demo-access.page-view');
    });

// ════════════════════════════════════════════════════════════════
// Webinars (AT-383) — the public registration front door.
// SERVED BY PRIMARY. Called SERVER-TO-SERVER by the CoreX marketing website:
// the registration page lives there (it owns the design and the funnel), CoreX
// owns the credentials, the email and the record. A visitor's browser never
// reaches these routes and never sees an access code.
//
// Authenticated by the UNIVERSAL SITE CONNECTOR (site.connector), a separate
// credential from the demo connector on purpose. Reusing the demo's token would
// hand a public brochure site the credential that opens demo SESSIONS (verify /
// session / page-view). Two audiences, two credentials, two blast radii —
// rotating one must never disturb the other.
//
// NOT an AgencyApiKey: that guard resolves an AGENCY as the tenant, and webinar
// registrations are RR Technologies' sales data, not an agency's.
//
// PREFIX IS v1/webinars — v1/demo is the mobile demo-login group and
// v1/demo-access is the demo host's control API. Neither is this.
//
// Spec: .ai/specs/webinar-registration.md §4
// ════════════════════════════════════════════════════════════════
Route::prefix('v1/webinars')
    ->middleware(['site.connector', 'throttle:website-api'])
    ->group(function () {
        // Reachability probe — lets a freshly pasted token be proven on the admin
        // connector card, rather than by a prospect hitting a form that 401s.
        Route::get('/ping', [\App\Http\Controllers\Api\V1\WebinarApiController::class, 'ping'])->name('v1.webinars.ping');

        // ── Admin API (AT-383 amendment). Spec: webinar-registration.md §4.3 ──
        // The marketing website's own console. Declared BEFORE /{slug}, because
        // that route would otherwise swallow the collection and every path with
        // a second segment. Same connector, same throttle.
        Route::get('/', [\App\Http\Controllers\Api\V1\WebinarApiController::class, 'index'])->name('v1.webinars.index');
        Route::post('/', [\App\Http\Controllers\Api\V1\WebinarApiController::class, 'store'])->name('v1.webinars.store');

        Route::get('/{slug}/registrations.csv', [\App\Http\Controllers\Api\V1\WebinarApiController::class, 'registrationsCsv'])->name('v1.webinars.registrations-csv');
        Route::get('/{slug}/registrations', [\App\Http\Controllers\Api\V1\WebinarApiController::class, 'registrations'])->name('v1.webinars.registrations');

        // Save the joining link AND mail it to everyone already registered, in one
        // action. A webinar is created before its Zoom link exists, so this is the
        // ONLY way to reach the cohort whose confirmation went out without one.
        // No ordering hazard: there is no POST /{slug} for this to collide with.
        // Spec: webinar-registration.md §4.4
        Route::post('/{slug}/join-link', [\App\Http\Controllers\Api\V1\WebinarApiController::class, 'sendJoinLink'])->name('v1.webinars.send-join-link');

        Route::put('/{slug}', [\App\Http\Controllers\Api\V1\WebinarApiController::class, 'update'])->name('v1.webinars.update');

        // Archive, never delete — the registration link stops working, but
        // everyone already registered keeps the access they were promised.
        Route::delete('/{slug}', [\App\Http\Controllers\Api\V1\WebinarApiController::class, 'archive'])->name('v1.webinars.archive');

        // Public detail, so the website renders live copy instead of hard-coding it.
        // Returns NO join_url — that is earned by registering.
        Route::get('/{slug}', [\App\Http\Controllers\Api\V1\WebinarApiController::class, 'show'])->name('v1.webinars.show');

        // The registration itself: row + demo grant on the webinar's fixed deadline
        // + ONE email (confirmation, join link, .ics, credentials).
        Route::post('/{slug}/register', [\App\Http\Controllers\Api\V1\WebinarApiController::class, 'register'])->name('v1.webinars.register');
    });

// ════════════════════════════════════════════════════════════════
// Other Agency Stock — Chrome extension import (.ai/specs/other-agency-stock.md §4)
//
// 2026-09-29 URGENT FIX — found live: this was WRONGLY built on
// auth.portal_capture (checks users.api_token, a separate hashed column)
// on the assumption that was "the same mechanism" the extension's other
// calls use. It is NOT. Pull Property / healthCheck / logged-user all sit
// inside the auth:sanctum group below and authenticate via a REAL Sanctum
// personal access token (confirmed live: user 22 has an active
// personal_access_tokens row named "corex-extension", last_used_at
// matching the exact minute of his working Pull-Property calls) — a
// completely different credential from users.api_token. The extension
// sends the SAME bearer token to every endpoint; this route family
// rejected the only token it actually has ("Invalid API token" — every
// single time, confirmed via nginx access log + Johan's screenshots).
// Moved inside auth:sanctum + app_access to match every other route the
// extension successfully calls. deny_assistant_property_write mirrors
// /prospecting/import (AT-267) — an assistant may never bring a property
// onto the books by any path, imported-from-another-agency included.
// ════════════════════════════════════════════════════════════════

// ════════════════════════════════════════════════════════════════
// Authenticated (sanctum) — canonical v1 routes
// ════════════════════════════════════════════════════════════════
// app_access: mobile "Delete my account" (Apple 5.1.1(v)) — rejects an
// already-issued token immediately once revoked. The one exception is the
// delete-account route itself, opted out below so it stays reachable and
// idempotent even after access is already off. Spec: .ai/specs/mobile-app-access.md §4.3
Route::middleware(['auth:sanctum', 'app_access', 'throttle:staff-api'])->group(function () {

    // ─────────────────────────────────────────────────────────────
    // Canonical /api/v1/* surface
    // ─────────────────────────────────────────────────────────────
    Route::prefix('v1')->group(function () {

        // Other Agency Stock — Chrome extension import (see the
        // 2026-09-29 URGENT FIX comment above this group for why this
        // lives here and not on auth.portal_capture).
        Route::middleware('deny_assistant_property_write')->group(function () {
            Route::get('/other-agency-stock/consent-wording', [\App\Http\Controllers\Api\OtherAgencyStockImportController::class, 'consentWording'])
                ->name('v1.other-agency-stock.consent-wording');
            Route::post('/other-agency-stock/import', [\App\Http\Controllers\Api\OtherAgencyStockImportController::class, 'import'])
                ->name('v1.other-agency-stock.import');
        });

        // CoreX Desk phase 3 - the CoreX Chrome extension shares the agent's OTHER tabs into their open Desk session (spec
        // .ai/specs/corex-desk.md section 13). Sanctum bearer token like every extension call; own session only; desk.request_help.
        Route::prefix('desk/ext')->name('v1.desk.ext.')->middleware('permission:desk.request_help')->group(function () {
            Route::get('/session', [\App\Http\Controllers\Api\V1\DeskExtensionController::class, 'session'])->name('session');
            Route::post('/broadcasting-auth', [\App\Http\Controllers\Api\V1\DeskExtensionController::class, 'channelAuth'])->name('channel-auth');
            Route::post('/sessions/{id}/heartbeat', [\App\Http\Controllers\Api\V1\DeskExtensionController::class, 'heartbeat'])->whereNumber('id')->name('heartbeat');
            Route::post('/sessions/{id}/tabs', [\App\Http\Controllers\Api\V1\DeskExtensionController::class, 'tab'])->whereNumber('id')->name('tab');
        });

        // AT-441 — .ai/specs/rental-command-centre.md §5. Mirrors the web
        // screen exactly (same RentalCommandCentreService) — Andre's mobile
        // app's rentals landing screen calls this.
        Route::get('/rentals/command-centre', [\App\Http\Controllers\Api\V1\RentalCommandCentreController::class, 'index'])
            ->middleware('permission:rental_command_centre.view')->name('v1.rentals.command-centre');

        // AT-443 — .ai/specs/rentals-reports.md §7. One endpoint per report key,
        // same RentalReportService the web screen reads from — Andre's mobile
        // app and any future BI export call this, not a second implementation.
        Route::get('/rentals/reports/{reportKey}', [\App\Http\Controllers\Api\V1\RentalReportController::class, 'show'])
            ->middleware('permission:rental_reports.view')->name('v1.rentals.reports.show');

        // AT-366 — interactive agency Performance & ROI report backend (read-only, agency-scoped).
        Route::get('/performance/deal-breakdown', [\App\Http\Controllers\Api\V1\PerformanceDrilldownController::class, 'dealBreakdown'])
            ->middleware('permission:view_performance')->name('v1.performance.deal-breakdown');
        Route::get('/performance/drilldown', [\App\Http\Controllers\Api\V1\PerformanceDrilldownController::class, 'drilldown'])
            ->middleware('permission:view_performance')->name('v1.performance.drilldown');

        // .ai/specs/leases.md §12.4 — Lease Hub tenancy log. Session-authed
        // (the Lease Hub's own panel fetches this), and the same endpoint
        // Andre's mobile app calls for a tenant/agent mobile tenancy view.
        // Scope-guarded the same way as the web show() route (AuthorizesLeaseAccess).
        Route::get('/leases/{lease}/tenancy-log', [\App\Http\Controllers\CoreX\LeaseController::class, 'tenancyLog'])
            ->middleware('permission:leases.view')->name('v1.leases.tenancy-log');

        // LEASE-CAPTURE BEGIN (leases.md §15.15 — Build L2): the single capture screen's JSON mirror. The mode
        // (new lease / renewal) is read from the body (`previous_lease_id`) and its permission is checked by the
        // request itself — leases.create for a new lease, leases.renew for a renewal.
        Route::post('/leases/capture', [\App\Http\Controllers\Api\V1\LeaseCaptureApiController::class, 'store'])
            ->middleware('permission:leases.view')->name('v1.leases.capture');
        // Build L3a (§15.15): where a lease's agreement stands — status, signers in signing order, what is still missing.
        Route::get('/leases/{lease}/signing', [\App\Http\Controllers\Api\V1\LeaseCaptureApiController::class, 'signing'])
            ->middleware('permission:leases.view')->name('v1.leases.signing');
        // Build L3c (§15.9, §15.15): the confirmation of the differences between the lease and its agreement.
        Route::post('/leases/{lease}/signing/confirm', [\App\Http\Controllers\Api\V1\LeaseCaptureApiController::class, 'confirm'])
            ->middleware('permission:leases.view')->name('v1.leases.signing.confirm');
        // LEASE-CAPTURE END

        // .ai/specs/rental-renewals.md §10 — JSON mirror of the Lease Hub's
        // renewal/one-click-outcome web actions, for Andre's mobile app.
        Route::middleware('permission:leases.renew')->prefix('leases/{lease}/renewal')->group(function () {
            Route::post('/draft', [\App\Http\Controllers\Api\V1\LeaseRenewalApiController::class, 'draftCopyForward'])->name('v1.leases.renewal.draft');
            Route::post('/draft-from-template', [\App\Http\Controllers\Api\V1\LeaseRenewalApiController::class, 'draftFromTemplate'])->name('v1.leases.renewal.draft-from-template');
            Route::post('/upload', [\App\Http\Controllers\Api\V1\LeaseRenewalApiController::class, 'uploadRenewal'])->name('v1.leases.renewal.upload');
            Route::post('/month-to-month', [\App\Http\Controllers\Api\V1\LeaseRenewalApiController::class, 'monthToMonth'])->name('v1.leases.renewal.month-to-month');
            Route::post('/month-to-month/reverse', [\App\Http\Controllers\Api\V1\LeaseRenewalApiController::class, 'reverseMonthToMonth'])->name('v1.leases.renewal.month-to-month.reverse');
            Route::post('/tenant-notice', [\App\Http\Controllers\Api\V1\LeaseRenewalApiController::class, 'tenantNotice'])->middleware('permission:rental_notices.review')->name('v1.leases.renewal.tenant-notice');
            Route::post('/landlord-notice', [\App\Http\Controllers\Api\V1\LeaseRenewalApiController::class, 'landlordNotice'])->middleware('permission:rental_notices.review')->name('v1.leases.renewal.landlord-notice');
            Route::post('/notice/reverse', [\App\Http\Controllers\Api\V1\LeaseRenewalApiController::class, 'reverseNotice'])->middleware('permission:rental_notices.review')->name('v1.leases.renewal.notice.reverse');
            Route::post('/notice/change-outcome', [\App\Http\Controllers\Api\V1\LeaseRenewalApiController::class, 'changeNoticeOutcome'])->middleware('permission:rental_notices.review')->name('v1.leases.renewal.notice.change-outcome');
        });

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
                'is_assistant' => (bool) $user->is_assistant,
                'branch' => $user->branch?->name ?? null,
                'ffc_status' => $user->ffc_status ?? null,
                'agency' => $agency ? [
                    'id'   => $agency->id,
                    'slug' => $agency->slug,
                    'name' => $agency->name,
                ] : null,
            ]);
        })->name('v1.logged-user');

        // Mobile app theme preference (light/dark)
        Route::get('/me/theme', function (Request $request) {
            return response()->json([
                'theme' => $request->user()->theme ?? 'dark',
            ]);
        })->name('v1.me.theme.show');

        Route::put('/me/theme', function (Request $request) {
            $data = $request->validate([
                'theme' => ['required', 'string', 'in:light,dark'],
            ]);
            $request->user()->update(['theme' => $data['theme']]);
            return response()->json([
                'theme' => $data['theme'],
                'updated' => true,
            ]);
        })->name('v1.me.theme.update');

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
                'role' => $user->role,
                'role_label' => $user->roleModel()?->label ?? ucfirst((string) ($user->role ?? 'agent')),
                'is_assistant' => (bool) $user->is_assistant,
                'branch' => $user->branch?->name ?? null,
                'ffc_status' => $user->ffc_status ?? null,
                'agency' => $agency ? [
                    'id'   => $agency->id,
                    'slug' => $agency->slug,
                    'name' => $agency->name,
                ] : null,
            ]);
        })->name('v1.profile');

        // Mobile Profile screen — FFC/cell/WhatsApp/socials view+edit, plus the
        // public agent-page preview URL. Spec: .ai/specs/mobile-agent-profile.md
        Route::get('/mobile/profile', [\App\Http\Controllers\Api\MobileProfileController::class, 'show'])
            ->name('v1.mobile.profile.show');
        Route::patch('/mobile/profile', [\App\Http\Controllers\Api\MobileProfileController::class, 'update'])
            ->name('v1.mobile.profile.update');

        // Mobile "Delete my account" (Apple 5.1.1(v)). Turns app_access OFF —
        // see .ai/specs/mobile-app-access.md. Does not touch the User row.
        // Opted OUT of the group's app_access gate so it stays reachable (and
        // idempotent) even after access is already revoked.
        Route::delete('/me/app-access', [\App\Http\Controllers\Api\V1\AppAccessController::class, 'destroy'])
            ->withoutMiddleware(\App\Http\Middleware\EnsureAppAccess::class)
            ->name('v1.me.app-access.destroy');

        Route::post('/logout', function (Request $request) {
            $request->user()->currentAccessToken()->delete();
            return response()->json(['message' => 'Logged out']);
        })->name('v1.logout');

        // ── Mobile data-visibility descriptor ───────────────────────
        Route::get('/mobile/visibility', [\App\Http\Controllers\Api\MobileVisibilityController::class, 'show'])
            ->name('v1.mobile.visibility');

        // ── Prospecting ────────────────────────────────────────────
        // AT-267 — /import creates tracked-property records and carries NO permission key of
        // its own; an assistant may never bring a property onto the books by any path.
        Route::post('/prospecting/import',      [ProspectingApiController::class, 'import'])->middleware('deny_assistant_property_write')->name('v1.prospecting.import');
        Route::get('/prospecting/check-search', [ProspectingApiController::class, 'checkSearch'])->name('v1.prospecting.check-search');

        // ── CMA / deeds capture (phase 1) — mirrors the portal-capture ingest. ──
        // AT-449 audit: the Chrome extension posts to these three with only a valid token — no permission check.
        // They now require the same key as the Deeds Capture screen they feed (deeds_capture.access), so a user
        // who cannot open that screen cannot push records into it.
        Route::post('/deeds-capture', [\App\Http\Controllers\Api\DeedsCaptureController::class, 'store'])->middleware('permission:deeds_capture.access')->name('v1.deeds-capture');

        // Pre-check (.ai/specs/deeds-capture.md §9) — called BEFORE the extension
        // reveals an owner ID (CMA Info's paid, R3-per-ID step). Read-only; mirrors
        // the check-search precedent above.
        Route::post('/deeds-capture/check-duplicate', [\App\Http\Controllers\Api\DeedsCaptureController::class, 'checkDuplicate'])->name('v1.deeds-capture.check-duplicate');
        Route::post('/deeds-capture/check-duplicate/decision', [\App\Http\Controllers\Api\DeedsCaptureController::class, 'logPrecheckDecision'])->name('v1.deeds-capture.check-duplicate.decision');

        // ── TVA (The Virtual Agent) contact capture — mirrors deeds-capture. ──
        Route::post('/tva-contact-capture', [\App\Http\Controllers\Api\TvaContactCaptureController::class, 'store'])->middleware('permission:deeds_capture.access')->name('v1.tva-contact-capture');

        // ── TVA company DIRECTORSHIP capture — directors → natural-person
        //    contacts linked to the company entity contact (representatives). ──
        Route::post('/tva-company-directors', [\App\Http\Controllers\Api\TvaCompanyDirectorsController::class, 'store'])->middleware('permission:deeds_capture.access')->name('v1.tva-company-directors');

        // ── Properties — portal pull ───────────────────────────────
        // AT-267 — pulls a listing off a portal INTO a property. Same rule, same reason: no
        // permission key of its own, and it creates stock.
        Route::post('/properties/pull-from-portal',          [PropertyPullController::class, 'pullFromPortal'])->middleware('deny_assistant_property_write')->name('v1.properties.pull-from-portal');
        Route::get('/properties/{propertyId}/pull-status',   [PropertyPullController::class, 'pullStatus'])->name('v1.properties.pull-status');

        // ── Properties — geocoding (Map strip → AddressResolverService) ──
        // Replaces the legacy frontend Nominatim suburb-only call with a
        // building-level lookup. ?force=1 triggers re-resolve even when the
        // property already has coords (used after street_number / street_name
        // edits to overwrite stale suburb-centroid pins).
        Route::post('/properties/{property}/geocode',        [\App\Http\Controllers\CoreX\PropertyController::class, 'geocode'])->middleware('permission:access_properties')->name('v1.properties.geocode');

        // .ai/specs/rental-work-orders.md §14 (AT-442 req #10) — the
        // internal job-card mirror for Andre's app: read, tick a task,
        // upload a photo. Same scoping as the web screen
        // (RentalJobCard::scopeVisibleTo()), enforced in the controller.
        // AT-508 P1 — what is waiting for ME (the command centre's own action queue, flattened for the app badge/list).
        Route::get('/mobile/rentals/waiting', [\App\Http\Controllers\Api\V1\MobileRentalWaitingController::class, 'index'])
            ->middleware('permission:rental_command_centre.view')->name('v1.mobile.rentals.waiting');

        Route::prefix('mobile/rental-job-cards')->group(function () {
            Route::get('/{rentalJobCard}', [\App\Http\Controllers\Api\MobileRentalJobCardController::class, 'show'])->whereNumber('rentalJobCard')->name('v1.mobile.rental-job-cards.show');
            Route::put('/{rentalJobCard}', [\App\Http\Controllers\Api\MobileRentalJobCardController::class, 'update'])->middleware('permission:rental_job_cards.create')->name('v1.mobile.rental-job-cards.update');
            Route::post('/{rentalJobCard}/tasks/{task}/tick', [\App\Http\Controllers\Api\MobileRentalJobCardController::class, 'tickTask'])->middleware('permission:rental_job_cards.create')->name('v1.mobile.rental-job-cards.tasks.tick');
            Route::post('/{rentalJobCard}/photos', [\App\Http\Controllers\Api\MobileRentalJobCardController::class, 'storePhoto'])->middleware('permission:rental_job_cards.create')->name('v1.mobile.rental-job-cards.photos.store');
        });

        // AT-508 P2/P3 BEGIN - staff rentals on the phone: faults and work orders (P2), leases and notices to review (P3).
        // Spec: .ai/specs/at-508-mobile-rentals.md section 4; routes of P2 are the table in .ai/specs/rentals-faults-work-orders.md section 7.1.
        // Every route calls the same model / service the web screen calls (no second implementation). Scoping: global AgencyScope (404 other agency)
        // + guardRentalRecordScope / scopeVisibleTo (403 / filtered out for same-agency records outside the user's own / branch ceiling).
        // Write routes bind with withTrashed() so a queued write for a record archived meanwhile answers 410 `archived` instead of a 404 (spec section 5.5).
        $faults = \App\Http\Controllers\Api\MobileRentalFaultController::class;
        $workOrders = \App\Http\Controllers\Api\MobileRentalWorkOrderController::class;

        Route::get('/mobile/rental-fault-types', [$faults, 'types'])->middleware('permission:rental_fault_reports.create')->name('v1.mobile.rental-fault-types');
        Route::get('/mobile/fault-reports', [$faults, 'index'])->middleware('permission:rental_fault_reports.view')->name('v1.mobile.fault-reports.index');
        Route::get('/mobile/fault-reports/{faultReport}', [$faults, 'show'])->withTrashed()->middleware('permission:rental_fault_reports.view')->name('v1.mobile.fault-reports.show');
        Route::post('/mobile/properties/{property}/fault-reports', [$faults, 'store'])->withTrashed()->middleware('permission:rental_fault_reports.create')->name('v1.mobile.properties.fault-reports.store');
        Route::post('/mobile/fault-reports/{faultReport}/photos', [$faults, 'storePhoto'])->withTrashed()->middleware('permission:rental_fault_reports.create')->name('v1.mobile.fault-reports.photos.store');
        Route::post('/mobile/fault-reports/{faultReport}/approval', [$faults, 'recordApproval'])->withTrashed()->middleware('permission:rental_fault_reports.record_approval')->name('v1.mobile.fault-reports.approval.store');
        Route::post('/mobile/fault-reports/{faultReport}/outcome', [$faults, 'setOutcome'])->withTrashed()->middleware('permission:rental_fault_reports.resolve')->name('v1.mobile.fault-reports.outcome.store');

        Route::get('/mobile/work-orders', [$workOrders, 'index'])->middleware('permission:rental_work_orders.view')->name('v1.mobile.work-orders.index');
        Route::get('/mobile/work-orders/{workOrder}', [$workOrders, 'show'])->withTrashed()->middleware('permission:rental_work_orders.view')->name('v1.mobile.work-orders.show');
        Route::post('/mobile/properties/{property}/work-orders', [$workOrders, 'store'])->withTrashed()->middleware('permission:rental_work_orders.create')->name('v1.mobile.properties.work-orders.store');
        Route::post('/mobile/work-orders/{workOrder}/photos', [$workOrders, 'storePhoto'])->withTrashed()->middleware('permission:rental_work_orders.create')->name('v1.mobile.work-orders.photos.store');
        Route::post('/mobile/work-orders/{workOrder}/assign-supplier', [$workOrders, 'assignSupplier'])->withTrashed()->middleware('permission:rental_work_orders.create')->name('v1.mobile.work-orders.assign-supplier');
        Route::post('/mobile/work-orders/{workOrder}/contractor-response', [$workOrders, 'contractorResponse'])->withTrashed()->middleware('permission:rental_work_orders.create')->name('v1.mobile.work-orders.contractor-response');
        Route::post('/mobile/work-orders/{workOrder}/invoices', [$workOrders, 'storeInvoice'])->withTrashed()->middleware('permission:rental_work_orders.manage_invoices')->name('v1.mobile.work-orders.invoices.store');
        Route::post('/mobile/work-orders/{workOrder}/complete', [$workOrders, 'complete'])->withTrashed()->middleware('permission:rental_work_orders.complete')->name('v1.mobile.work-orders.complete');

        // AT-508 P3: the staff lease list / lease file (read-only; the actions are the existing /leases/{lease}/renewal/* routes) and the notices waiting for review.
        Route::get('/mobile/leases', [\App\Http\Controllers\Api\MobileRentalLeaseController::class, 'index'])->middleware('permission:leases.view')->name('v1.mobile.leases.index');
        Route::get('/mobile/leases/{lease}', [\App\Http\Controllers\Api\MobileRentalLeaseController::class, 'show'])->whereNumber('lease')->middleware('permission:leases.view')->name('v1.mobile.leases.show');
        Route::get('/mobile/rental-notices', [\App\Http\Controllers\Api\MobileRentalNoticeController::class, 'index'])->middleware('permission:rental_notices.review')->name('v1.mobile.rental-notices.index');
        // AT-508 P2/P3 END

        // AT-508 P5/P6 BEGIN
        // Staff inspections + inventories for the app. Spec: .ai/specs/rental-inspections.md §14.2, .ai/specs/at-508-mobile-rentals.md §4-§7,
        // handoff contract .ai/specs/at-508-P5P6-build-notes.md. Every write is a thin call into the SAME action the web recording screen uses;
        // queueable writes carry client_idempotency_key (replay = 200 + duplicate:true) and captured_at.
        Route::get('/mobile/properties/{property}/rental-inspection-items', [\App\Http\Controllers\Api\V1\MobileRentalInspectionController::class, 'checklist'])
            ->whereNumber('property')->middleware('permission:rental_inspections.view')->name('v1.mobile.rental-inspections.checklist');
        Route::prefix('mobile/rental-inspections')->group(function () {
            $c = \App\Http\Controllers\Api\V1\MobileRentalInspectionController::class;
            Route::get('/', [$c, 'index'])->middleware('permission:rental_inspections.view')->name('v1.mobile.rental-inspections.index');
            Route::get('/due', [$c, 'due'])->middleware('permission:rental_inspections.view')->name('v1.mobile.rental-inspections.due');
            Route::post('/', [$c, 'store'])->middleware('permission:rental_inspections.create')->name('v1.mobile.rental-inspections.store');
            Route::get('/{inspection}', [$c, 'show'])->whereNumber('inspection')->middleware('permission:rental_inspections.view')->name('v1.mobile.rental-inspections.show');
            Route::post('/{inspection}/observations', [$c, 'storeObservation'])->whereNumber('inspection')
                ->middleware('permission:rental_inspections.create')->name('v1.mobile.rental-inspections.observations.store');
            Route::post('/{inspection}/observations/{observation}/photos', [$c, 'storePhoto'])->whereNumber(['inspection', 'observation'])
                ->middleware('permission:rental_inspections.create')->name('v1.mobile.rental-inspections.observations.photos.store');
            Route::post('/{inspection}/photos', [$c, 'storeTaggedPhoto'])->whereNumber('inspection')
                ->middleware('permission:rental_inspections.create')->name('v1.mobile.rental-inspections.photos.store');
            Route::post('/{inspection}/rooms/{room}/notes', [$c, 'storeRoomNote'])->whereNumber(['inspection', 'room'])
                ->middleware('permission:rental_inspections.create')->name('v1.mobile.rental-inspections.rooms.notes.store');
            Route::post('/{inspection}/overall-notes', [$c, 'updateOverallNotes'])->whereNumber('inspection')
                ->middleware('permission:rental_inspections.create')->name('v1.mobile.rental-inspections.overall-notes');
            Route::post('/{inspection}/attendance', [$c, 'storeAttendance'])->whereNumber('inspection')
                ->middleware('permission:rental_inspections.record_attendance')->name('v1.mobile.rental-inspections.attendance.store');
            Route::post('/{inspection}/discrepancies/{discrepancy}/resolve', [$c, 'resolveDiscrepancy'])->whereNumber(['inspection', 'discrepancy'])
                ->middleware('permission:rental_inspections.resolve_discrepancy')->name('v1.mobile.rental-inspections.discrepancies.resolve');
            Route::post('/{inspection}/signatures', [$c, 'storeSignature'])->whereNumber('inspection')
                ->middleware('permission:rental_inspections.create')->name('v1.mobile.rental-inspections.signatures.store');
            Route::post('/{inspection}/complete', [$c, 'complete'])->whereNumber('inspection')
                ->middleware('permission:rental_inspections.create')->name('v1.mobile.rental-inspections.complete');
        });
        // inventories (P6): list, record, add a photo
        Route::prefix('mobile/rental-inventories')->group(function () {
            $c = \App\Http\Controllers\Api\V1\MobileRentalInventoryController::class;
            Route::get('/', [$c, 'index'])->middleware('permission:rental_inventories.view')->name('v1.mobile.rental-inventories.index');
            Route::get('/{inventory}', [$c, 'show'])->whereNumber('inventory')->middleware('permission:rental_inventories.view')->name('v1.mobile.rental-inventories.show');
            Route::post('/{inventory}/photos', [$c, 'storePhoto'])->whereNumber('inventory')
                ->middleware('permission:rental_inventories.create')->name('v1.mobile.rental-inventories.photos.store');
            // typing: add / change / remove a line (what the item is, how many, its condition, which room)
            Route::post('/{inventory}/lines', [$c, 'storeLine'])->whereNumber('inventory')
                ->middleware('permission:rental_inventories.create')->name('v1.mobile.rental-inventories.lines.store');
            Route::put('/{inventory}/lines/{line}', [$c, 'updateLine'])->whereNumber(['inventory', 'line'])
                ->middleware('permission:rental_inventories.create')->name('v1.mobile.rental-inventories.lines.update');
            Route::post('/{inventory}/lines/{line}/retire', [$c, 'retireLine'])->whereNumber(['inventory', 'line'])
                ->middleware('permission:rental_inventories.create')->name('v1.mobile.rental-inventories.lines.retire');
        });
        // AT-508 P5/P6 END

        // MOBILE PARITY (inspections) BEGIN
        // Everything the web inspection screen does that the phone could not yet. Spec: .ai/specs/at-508-mobile-parity-addendum.md.
        // No new rule: each route is either a wrapper over the web's own model method (MobileRentalInspectionLifecycleController) or points
        // STRAIGHT at the web controller action, which already answers JSON. Permission keys are the web's own, route for route.
        Route::prefix('mobile/rental-inspections')->middleware('permission:rental_inspections.view')->group(function () {
            $l = \App\Http\Controllers\Api\V1\MobileRentalInspectionLifecycleController::class;
            $rec = \App\Http\Controllers\CoreX\RentalInspectionRecordingController::class;
            $links = \App\Http\Controllers\CoreX\RentalInspectionSigningLinkController::class;
            $notes = \App\Http\Controllers\CoreX\RentalInspectionPhotoNoteController::class;
            $att = \App\Http\Controllers\CoreX\RentalInspectionAttendanceController::class;
            $web = \App\Http\Controllers\CoreX\RentalInspectionController::class;
            $n = 'v1.mobile.rental-inspections.';

            // lifecycle
            Route::post('/schedule', [$l, 'schedule'])->middleware('permission:rental_inspections.create')->name($n . 'schedule');
            Route::post('/{inspection}/reschedule', [$l, 'reschedule'])->whereNumber('inspection')->middleware('permission:rental_inspections.reschedule')->name($n . 'reschedule');
            Route::post('/{inspection}/cancel', [$l, 'cancel'])->whereNumber('inspection')->middleware('permission:rental_inspections.cancel')->name($n . 'cancel');
            Route::delete('/{inspection}', [$l, 'archive'])->whereNumber('inspection')->middleware('permission:rental_inspections.archive')->name($n . 'archive');
            Route::post('/{inspection}/restore', [$l, 'restore'])->whereNumber('inspection')->middleware('permission:rental_inspections.restore')->name($n . 'restore');
            Route::post('/{inspection}/next', [$l, 'next'])->whereNumber('inspection')->middleware('permission:rental_inspections.create')->name($n . 'next');
            Route::post('/{inspection}/replace', [$l, 'replace'])->whereNumber('inspection')->middleware('permission:rental_inspections.create')->name($n . 'replace');
            Route::post('/{inspection}/public-link', [$l, 'generatePublicLink'])->whereNumber('inspection')->middleware('permission:rental_inspections.public_link')->name($n . 'public-link.generate');
            Route::delete('/{inspection}/public-link', [$l, 'revokePublicLink'])->whereNumber('inspection')->middleware('permission:rental_inspections.public_link')->name($n . 'public-link.revoke');

            // ready to sign, header block, room marks
            Route::post('/{rentalInspection}/ready-to-sign', [$rec, 'startAwaitingSignature'])->whereNumber('rentalInspection')->middleware('permission:rental_inspections.create')->name($n . 'ready-to-sign');
            Route::post('/{rentalInspection}/details', [$rec, 'updateDetails'])->whereNumber('rentalInspection')->middleware('permission:rental_inspections.edit_details')->name($n . 'details.update');
            Route::post('/{rentalInspection}/rooms/{room}/mark-na', [$rec, 'markRoomNa'])->whereNumber(['rentalInspection', 'room'])->middleware('permission:rental_inspections.create')->name($n . 'rooms.mark-na');
            Route::post('/{rentalInspection}/rooms/{room}/mark-good', [$rec, 'markRoomGood'])->whereNumber(['rentalInspection', 'room'])->middleware('permission:rental_inspections.create')->name($n . 'rooms.mark-good');
            Route::post('/{rentalInspection}/mark-all-good', [$rec, 'markAllGood'])->whereNumber('rentalInspection')->middleware('permission:rental_inspections.create')->name($n . 'mark-all-good');

            // photos: tag, untag, archive, notes
            Route::post('/{rentalInspection}/photos/tag-bulk', [$rec, 'tagPhotosBulk'])->whereNumber('rentalInspection')->middleware('permission:rental_inspections.create')->name($n . 'photos.tag-bulk');
            Route::post('/{rentalInspection}/photos/{photo}/tag', [$rec, 'tagPhoto'])->whereNumber(['rentalInspection', 'photo'])->middleware('permission:rental_inspections.create')->name($n . 'photos.tag');
            Route::post('/{rentalInspection}/photos/{photo}/untag', [$rec, 'untagPhoto'])->whereNumber(['rentalInspection', 'photo'])->middleware('permission:rental_inspections.create')->name($n . 'photos.untag');
            Route::delete('/{rentalInspection}/photos/{photo}', [$rec, 'archivePhoto'])->whereNumber(['rentalInspection', 'photo'])->middleware('permission:rental_inspections.create')->name($n . 'photos.archive');
            Route::post('/{rentalInspection}/photos/{photo}/notes', [$notes, 'store'])->whereNumber(['rentalInspection', 'photo'])->middleware('permission:rental_inspections.create')->name($n . 'photos.notes.store');
            Route::patch('/{rentalInspection}/photos/{photo}/notes/{note}', [$notes, 'update'])->whereNumber(['rentalInspection', 'photo', 'note'])->middleware('permission:rental_inspections.create')->name($n . 'photos.notes.update');
            Route::delete('/{rentalInspection}/photos/{photo}/notes/{note}', [$notes, 'archive'])->whereNumber(['rentalInspection', 'photo', 'note'])->middleware('permission:rental_inspections.create')->name($n . 'photos.notes.archive');
            Route::post('/{rentalInspection}/photos/{photo}/notes/{note}/restore', [$notes, 'restore'])->whereNumber(['rentalInspection', 'photo', 'note'])->withTrashed()->middleware('permission:rental_inspections.create')->name($n . 'photos.notes.restore');

            // attendance: withdraw, invitations given
            Route::post('/{rentalInspection}/attendance/{attendance}/withdraw', [$att, 'withdraw'])->whereNumber(['rentalInspection', 'attendance'])->middleware('permission:rental_inspections.record_attendance')->name($n . 'attendance.withdraw');
            Route::post('/{rentalInspection}/attendance-invitations', [$att, 'storeInvitation'])->whereNumber('rentalInspection')->middleware('permission:rental_inspections.record_attendance')->name($n . 'attendance.invitations.store');

            // signing: personal links (email / WhatsApp / QR), sign on the agent's phone, paper (wet-ink) correction
            Route::get('/{rentalInspection}/signing-links', [$links, 'index'])->whereNumber('rentalInspection')->name($n . 'signing-links.index');
            Route::post('/{rentalInspection}/signing-links', [$links, 'issue'])->whereNumber('rentalInspection')->middleware('permission:rental_inspections.public_link')->name($n . 'signing-links.issue');
            Route::post('/{rentalInspection}/signing-links/send-all', [$links, 'sendAll'])->whereNumber('rentalInspection')->middleware('permission:rental_inspections.public_link')->name($n . 'signing-links.send-all');
            Route::post('/{rentalInspection}/signing-links/{link}/email', [$links, 'email'])->whereNumber(['rentalInspection', 'link'])->name($n . 'signing-links.email');
            Route::post('/{rentalInspection}/signing-links/{link}/revoke', [$links, 'revoke'])->whereNumber(['rentalInspection', 'link'])->name($n . 'signing-links.revoke');
            Route::get('/{rentalInspection}/signing-links/{link}/qr', [$links, 'qr'])->whereNumber(['rentalInspection', 'link'])->name($n . 'signing-links.qr');
            Route::post('/{rentalInspection}/signing-links/{link}/device', [$links, 'deviceSubmit'])->whereNumber(['rentalInspection', 'link'])->name($n . 'signing-links.device-submit');
            Route::post('/{rentalInspection}/signatures/{signature}/supersede-wet-ink', [$rec, 'supersedeWetInkSignature'])->whereNumber(['rentalInspection', 'signature'])->middleware('permission:rental_inspections.create')->name($n . 'signatures.supersede-wet-ink');

            // the report: PDF for the agent, the paper form to print for signature, and resending a completed report
            Route::get('/{rentalInspection}/report', [$web, 'report'])->whereNumber('rentalInspection')->name($n . 'report');
            Route::get('/{rentalInspection}/print-for-signature', [$web, 'printForSignature'])->whereNumber('rentalInspection')->name($n . 'print-for-signature');
            Route::post('/{rentalInspection}/resend-report', [$rec, 'resendReport'])->whereNumber('rentalInspection')->middleware('permission:rental_inspections.create')->name($n . 'resend-report');
        });
        // MOBILE PARITY (inspections) END

        // MOBILE PARITY (inventories) BEGIN
        // Same idea as above: wrappers for the actions that answer a redirect on the web (start, cancel, archive, restore, move-out), and the
        // web's own JSON actions pointed at directly for the rest. Permission keys are the web's own (.create on every write).
        Route::post('/mobile/properties/{property}/inventory', [\App\Http\Controllers\Api\V1\MobileRentalInventoryLifecycleController::class, 'start'])
            ->whereNumber('property')->middleware('permission:rental_inventories.create')->name('v1.mobile.properties.inventory.start');
        Route::prefix('mobile/rental-inventories')->middleware('permission:rental_inventories.view')->group(function () {
            $l = \App\Http\Controllers\Api\V1\MobileRentalInventoryLifecycleController::class;
            $rec = \App\Http\Controllers\CoreX\RentalInventoryRecordingController::class;
            $cap = \App\Http\Controllers\CoreX\RentalInventoryCaptureController::class;
            $web = \App\Http\Controllers\CoreX\RentalInventoryController::class;
            $buyer = \App\Http\Controllers\CoreX\RentalInventoryBuyerAcceptanceController::class;
            $n = 'v1.mobile.rental-inventories.';
            $w = 'permission:rental_inventories.create';

            // lifecycle and move-out
            Route::post('/{inventory}/cancel', [$l, 'cancel'])->whereNumber('inventory')->middleware($w)->name($n . 'cancel');
            Route::delete('/{inventory}', [$l, 'archive'])->whereNumber('inventory')->middleware($w)->name($n . 'archive');
            Route::post('/{inventory}/restore', [$l, 'restore'])->whereNumber('inventory')->middleware($w)->name($n . 'restore');
            Route::get('/{inventory}/comparison', [$l, 'comparison'])->whereNumber('inventory')->name($n . 'comparison');
            Route::post('/{inventory}/move-out/complete', [$l, 'completeMoveOut'])->whereNumber('inventory')->middleware($w)->name($n . 'move-out.complete');
            Route::post('/{inventory}/move-out/sign-off', [$l, 'signOffMoveOut'])->whereNumber('inventory')->middleware($w)->name($n . 'move-out.sign-off');

            // rooms and copying the last inventory
            Route::post('/{rentalInventory}/rooms/{room}/mark-empty', [$rec, 'markRoomEmpty'])->whereNumber(['rentalInventory', 'room'])->middleware($w)->name($n . 'rooms.mark-empty');
            Route::delete('/{rentalInventory}/rooms/{room}/mark-empty', [$rec, 'unmarkRoomEmpty'])->whereNumber(['rentalInventory', 'room'])->middleware($w)->name($n . 'rooms.unmark-empty');
            Route::post('/{rentalInventory}/copy-from-last', [$rec, 'copyFromLastInventory'])->whereNumber('rentalInventory')->middleware($w)->name($n . 'copy-from-last');

            // photos: archive, tag / untag to a line, share the inspection's photos, move-out photos
            Route::delete('/{rentalInventory}/photos/{photo}', [$cap, 'archivePhoto'])->whereNumber(['rentalInventory', 'photo'])->middleware($w)->name($n . 'photos.archive');
            Route::get('/{rentalInventory}/inspection-photos', [$cap, 'inspectionPhotos'])->whereNumber('rentalInventory')->middleware($w)->name($n . 'inspection-photos.index');
            Route::post('/{rentalInventory}/inspection-photos/{inspectionPhoto}', [$cap, 'shareInspectionPhoto'])->whereNumber(['rentalInventory', 'inspectionPhoto'])->middleware($w)->name($n . 'inspection-photos.share');
            Route::post('/{rentalInventory}/lines/{line}/photos/{photo}', [$cap, 'attachLinePhoto'])->whereNumber(['rentalInventory', 'line', 'photo'])->middleware($w)->name($n . 'lines.photos.attach');
            Route::delete('/{rentalInventory}/lines/{line}/photos/{photo}', [$cap, 'detachLinePhoto'])->whereNumber(['rentalInventory', 'line', 'photo'])->middleware($w)->name($n . 'lines.photos.detach');
            Route::post('/{rentalInventory}/lines/{line}/dispositions', [$rec, 'storeLineDisposition'])->whereNumber(['rentalInventory', 'line'])->middleware($w)->name($n . 'lines.dispositions.store');
            Route::post('/{rentalInventory}/lines/{line}/move-out-photos', [$rec, 'storeLineMoveOutPhoto'])->whereNumber(['rentalInventory', 'line'])->middleware($w)->name($n . 'lines.move-out-photos.store');
            Route::get('/{rentalInventory}/lines/{line}/move-out-inspection-photos', [$rec, 'moveOutInspectionPhotos'])->whereNumber(['rentalInventory', 'line'])->middleware($w)->name($n . 'lines.move-out-inspection-photos.index');
            Route::post('/{rentalInventory}/lines/{line}/move-out-inspection-photos/{inspectionPhoto}', [$rec, 'shareMoveOutInspectionPhoto'])->whereNumber(['rentalInventory', 'line', 'inspectionPhoto'])->middleware($w)->name($n . 'lines.move-out-inspection-photos.share');

            // signing, completing, the report, buyer acceptance
            Route::post('/{rentalInventory}/signatures', [$rec, 'storeSignature'])->whereNumber('rentalInventory')->middleware($w)->name($n . 'signatures.store');
            Route::post('/{rentalInventory}/signatures/{signature}/supersede-wet-ink', [$rec, 'supersedeWetInkSignature'])->whereNumber(['rentalInventory', 'signature'])->middleware($w)->name($n . 'signatures.supersede-wet-ink');
            Route::post('/{rentalInventory}/complete', [$rec, 'complete'])->whereNumber('rentalInventory')->middleware($w)->name($n . 'complete');
            Route::post('/{rentalInventory}/resend-report', [$rec, 'resendReport'])->whereNumber('rentalInventory')->middleware($w)->name($n . 'resend-report');
            Route::get('/{rentalInventory}/report', [$web, 'report'])->whereNumber('rentalInventory')->name($n . 'report');
            Route::get('/{rentalInventory}/print-for-signature', [$web, 'printForSignature'])->whereNumber('rentalInventory')->name($n . 'print-for-signature');
            Route::post('/{rentalInventory}/buyer-acceptances/{contact}/send', [$buyer, 'send'])->whereNumber(['rentalInventory', 'contact'])->middleware($w)->name($n . 'buyer-acceptances.send');
        });
        // MOBILE PARITY (inventories) END

        // MOBILE PARITY (leases + notices) BEGIN
        // The lease screen's actions, run through the web controller's own action (RunsWebAction): same rules, same refusals, the web's own
        // permission key on each route. Spec: .ai/specs/at-508-mobile-parity-addendum.md §3. NOT exposed: reject a notice (see the addendum).
        Route::prefix('mobile/leases/{lease}')->whereNumber('lease')->group(function () {
            $c = \App\Http\Controllers\Api\V1\MobileRentalLeaseActionsController::class;
            $n = 'v1.mobile.leases.';

            Route::put('/', [$c, 'update'])->middleware('permission:leases.create')->name($n . 'update');
            Route::put('/agents', [$c, 'agents'])->middleware('permission:leases.create')->name($n . 'agents.update');
            Route::put('/notice-terms', [$c, 'noticeTerms'])->middleware('permission:lease_notice_terms.edit')->name($n . 'notice-terms.update');
            Route::post('/notice-terms/confirm', [$c, 'confirmNoticeTerms'])->middleware('permission:lease_notice_terms.edit')->name($n . 'notice-terms.confirm');
            Route::post('/expiry-notice/send', [$c, 'sendExpiryNotice'])->middleware('permission:rental_notices.review')->name($n . 'expiry-notice.send');
            Route::post('/expiry-notice/mark-given', [$c, 'markExpiryNoticeGiven'])->middleware('permission:rental_notices.review')->name($n . 'expiry-notice.mark-given');
            Route::post('/activate', [$c, 'activate'])->middleware('permission:leases.create')->name($n . 'activate');
            Route::post('/cancel', [$c, 'cancel'])->middleware('permission:leases.cancel')->name($n . 'cancel');
            Route::post('/escalate', [$c, 'escalate'])->middleware('permission:leases.renew')->name($n . 'escalate');
            Route::post('/archive', [$c, 'archive'])->middleware('permission:leases.create')->name($n . 'archive');
            Route::post('/run-date-checks', [$c, 'runDateChecks'])->middleware('permission:leases.view')->name($n . 'run-date-checks');
            Route::post('/signing/prepare', [$c, 'prepareForSigning'])->middleware('permission:leases.create')->name($n . 'signing.prepare');
            Route::post('/signing/prepare-again', [$c, 'prepareAgain'])->middleware('permission:leases.view')->name($n . 'signing.prepare-again');
            Route::post('/signing/signed-copy', [$c, 'signedCopy'])->middleware('permission:leases.create')->name($n . 'signing.signed-copy');
            Route::post('/renewal/cancel-draft', [$c, 'cancelRenewalDraft'])->middleware('permission:leases.renew')->name($n . 'renewal.cancel-draft');
            Route::post('/breaches', [$c, 'storeBreach'])->middleware('permission:rental_breaches.manage')->name($n . 'breaches.store');
            Route::post('/notice-cases', [$c, 'storeNoticeCase'])->middleware('permission:rental_notices.review')->name($n . 'notice-cases.store');
            Route::post('/notices', [$c, 'storeNotice'])->middleware('permission:rental_notices.create')->name($n . 'notices.store');
        });
        Route::post('/mobile/leases/{lease}/restore', [\App\Http\Controllers\Api\V1\MobileRentalLeaseActionsController::class, 'restore'])
            ->whereNumber('lease')->withTrashed()->middleware('permission:leases.create')->name('v1.mobile.leases.restore');
        Route::post('/mobile/rental-notices/{notice}/accept', [\App\Http\Controllers\Api\V1\MobileRentalLeaseActionsController::class, 'acceptNotice'])
            ->whereNumber('notice')->middleware('permission:rental_notices.review')->name('v1.mobile.rental-notices.accept');
        // MOBILE PARITY (leases + notices) END

        // MOBILE PARITY (faults + work orders + job cards) BEGIN
        // Generated from the web route table: each route is the web action itself, with the web's own permission keys, wrapped by
        // mobile.web-action so a redirect answer becomes JSON. Spec: .ai/specs/at-508-mobile-parity-addendum.md.
        Route::put('/mobile/rental-fault-reports/{rentalFaultReport}', [\App\Http\Controllers\CoreX\RentalFaultReportController::class, 'update'])->whereNumber('rentalFaultReport')->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.create'])->name('v1.mobile.rental-fault-reports.update');
        Route::delete('/mobile/rental-fault-reports/{rentalFaultReport}', [\App\Http\Controllers\CoreX\RentalFaultReportController::class, 'destroy'])->whereNumber('rentalFaultReport')->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.create'])->name('v1.mobile.rental-fault-reports.destroy');
        Route::post('/mobile/rental-fault-reports/{rentalFaultReport}/cancel', [\App\Http\Controllers\CoreX\RentalFaultReportController::class, 'cancel'])->whereNumber('rentalFaultReport')->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.cancel'])->name('v1.mobile.rental-fault-reports.cancel');
        Route::post('/mobile/rental-fault-reports/{rentalFaultReport}/owner-decision', [\App\Http\Controllers\CoreX\RentalFaultAuthorityController::class, 'recordOwnerDecision'])->whereNumber('rentalFaultReport')->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.record_approval'])->name('v1.mobile.rental-fault-reports.owner-decision.store');
        Route::post('/mobile/rental-fault-reports/{rentalFaultReport}/owner-questions/{query}/answer', [\App\Http\Controllers\CoreX\RentalFaultAuthorityController::class, 'answerQuery'])->whereNumber(['rentalFaultReport', 'query'])->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.send_to_owner'])->name('v1.mobile.rental-fault-reports.owner-query.answer');
        Route::post('/mobile/rental-fault-reports/{rentalFaultReport}/owner-version', [\App\Http\Controllers\CoreX\RentalFaultReportController::class, 'saveOwnerVersion'])->whereNumber('rentalFaultReport')->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.create'])->name('v1.mobile.rental-fault-reports.owner-version.store');
        Route::post('/mobile/rental-fault-reports/{rentalFaultReport}/photos/{photo}/marks', [\App\Http\Controllers\CoreX\RentalFaultReportController::class, 'storeMark'])->whereNumber(['rentalFaultReport', 'photo'])->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.create'])->name('v1.mobile.rental-fault-reports.photos.marks.store');
        Route::put('/mobile/rental-fault-reports/{rentalFaultReport}/photos/{photo}/marks/{mark}', [\App\Http\Controllers\CoreX\RentalFaultReportController::class, 'updateMark'])->whereNumber(['rentalFaultReport', 'photo', 'mark'])->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.create'])->name('v1.mobile.rental-fault-reports.photos.marks.update');
        Route::delete('/mobile/rental-fault-reports/{rentalFaultReport}/photos/{photo}/marks/{mark}', [\App\Http\Controllers\CoreX\RentalFaultReportController::class, 'destroyMark'])->whereNumber(['rentalFaultReport', 'photo', 'mark'])->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.create'])->name('v1.mobile.rental-fault-reports.photos.marks.destroy');
        Route::post('/mobile/rental-fault-reports/{rentalFaultReport}/photos/{photo}/marks/{mark}/restore', [\App\Http\Controllers\CoreX\RentalFaultReportController::class, 'restoreMark'])->whereNumber(['rentalFaultReport', 'photo', 'mark'])->withTrashed()->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.create'])->name('v1.mobile.rental-fault-reports.photos.marks.restore');
        Route::put('/mobile/rental-fault-reports/{rentalFaultReport}/photos/{photo}/note', [\App\Http\Controllers\CoreX\RentalFaultReportController::class, 'saveNote'])->whereNumber(['rentalFaultReport', 'photo'])->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.create'])->name('v1.mobile.rental-fault-reports.photos.note.save');
        Route::delete('/mobile/rental-fault-reports/{rentalFaultReport}/photos/{photo}/note', [\App\Http\Controllers\CoreX\RentalFaultReportController::class, 'clearNote'])->whereNumber(['rentalFaultReport', 'photo'])->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.create'])->name('v1.mobile.rental-fault-reports.photos.note.clear');
        Route::post('/mobile/rental-fault-reports/{rentalFaultReport}/raise-work-order', [\App\Http\Controllers\CoreX\RentalFaultReportController::class, 'raiseWorkOrder'])->whereNumber('rentalFaultReport')->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.raise_work_order'])->name('v1.mobile.rental-fault-reports.raise-work-order');
        Route::post('/mobile/rental-fault-reports/{rentalFaultReport}/reclassify', [\App\Http\Controllers\CoreX\RentalFaultReportController::class, 'reclassify'])->whereNumber('rentalFaultReport')->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.create'])->name('v1.mobile.rental-fault-reports.reclassify');
        Route::post('/mobile/rental-fault-reports/{rentalFaultReport}/repair-anyway', [\App\Http\Controllers\CoreX\RentalFaultReportController::class, 'repairAnyway'])->whereNumber('rentalFaultReport')->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.create'])->name('v1.mobile.rental-fault-reports.repair-anyway');
        Route::post('/mobile/rental-fault-reports/{rentalFaultReport}/restore', [\App\Http\Controllers\CoreX\RentalFaultReportController::class, 'restore'])->whereNumber('rentalFaultReport')->withTrashed()->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.create'])->name('v1.mobile.rental-fault-reports.restore');
        Route::post('/mobile/rental-fault-reports/{rentalFaultReport}/resume', [\App\Http\Controllers\CoreX\RentalFaultAuthorityController::class, 'resume'])->whereNumber('rentalFaultReport')->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.record_approval'])->name('v1.mobile.rental-fault-reports.resume');
        Route::post('/mobile/rental-fault-reports/{rentalFaultReport}/send-to-inspect', [\App\Http\Controllers\CoreX\RentalFaultAuthorityController::class, 'inspect'])->whereNumber('rentalFaultReport')->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.raise_work_order'])->name('v1.mobile.rental-fault-reports.send-to-inspect');
        Route::post('/mobile/rental-fault-reports/{rentalFaultReport}/send-to-owner', [\App\Http\Controllers\CoreX\RentalFaultReportController::class, 'requestApproval'])->whereNumber('rentalFaultReport')->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.send_to_owner'])->name('v1.mobile.rental-fault-reports.send-to-owner');
        Route::post('/mobile/rental-fault-reports/{rentalFaultReport}/send-to-owner-to-authorise', [\App\Http\Controllers\CoreX\RentalFaultAuthorityController::class, 'sendToOwner'])->whereNumber('rentalFaultReport')->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.send_to_owner'])->name('v1.mobile.rental-fault-reports.send-to-owner-to-authorise');
        Route::post('/mobile/rental-fault-reports/{rentalFaultReport}/tenant-confirmation', [\App\Http\Controllers\CoreX\RentalFaultAuthorityController::class, 'confirmWithTenant'])->whereNumber('rentalFaultReport')->middleware(['mobile.web-action', 'permission:rental_fault_reports.view', 'permission:rental_fault_reports.create'])->name('v1.mobile.rental-fault-reports.tenant-confirmation');
        Route::put('/mobile/rental-work-orders/{rentalWorkOrder}', [\App\Http\Controllers\CoreX\RentalWorkOrderController::class, 'update'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.create'])->name('v1.mobile.rental-work-orders.update');
        Route::delete('/mobile/rental-work-orders/{rentalWorkOrder}', [\App\Http\Controllers\CoreX\RentalWorkOrderController::class, 'destroy'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.create'])->name('v1.mobile.rental-work-orders.destroy');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/access-contact', [\App\Http\Controllers\CoreX\RentalWorkOrderController::class, 'updateAccessContact'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.create'])->name('v1.mobile.rental-work-orders.access-contact.update');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/appointment', [\App\Http\Controllers\CoreX\RentalWorkOrderController::class, 'setAppointment'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.create'])->name('v1.mobile.rental-work-orders.appointment.store');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/approval', [\App\Http\Controllers\CoreX\RentalWorkOrderController::class, 'recordApproval'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.record_approval'])->name('v1.mobile.rental-work-orders.approval.store');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/cancel', [\App\Http\Controllers\CoreX\RentalWorkOrderController::class, 'cancel'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.cancel'])->name('v1.mobile.rental-work-orders.cancel');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/completion-rounds/{round}/answer', [\App\Http\Controllers\CoreX\RentalWorkOrderCompletionController::class, 'recordTenantAnswer'])->whereNumber(['rentalWorkOrder', 'round'])->middleware(['mobile.web-action', 'permission:rental_work_orders.manage_completion'])->name('v1.mobile.rental-work-orders.completion-rounds.answer');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/contractor', [\App\Http\Controllers\CoreX\RentalJobDispatchController::class, 'appoint'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.create'])->name('v1.mobile.rental-work-orders.contractor.appoint');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/contractor-done', [\App\Http\Controllers\CoreX\RentalWorkOrderCompletionController::class, 'contractorDone'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.manage_completion'])->name('v1.mobile.rental-work-orders.contractor-done');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/contractor/dispatches/{dispatch}/whatsapp', [\App\Http\Controllers\CoreX\RentalJobDispatchController::class, 'openWhatsApp'])->whereNumber(['rentalWorkOrder', 'dispatch'])->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.create'])->name('v1.mobile.rental-work-orders.contractor.whatsapp-open');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/contractor/dispatches/{dispatch}/whatsapp-sent', [\App\Http\Controllers\CoreX\RentalJobDispatchController::class, 'markWhatsAppSent'])->whereNumber(['rentalWorkOrder', 'dispatch'])->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.create'])->name('v1.mobile.rental-work-orders.contractor.whatsapp-sent');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/contractor/resend', [\App\Http\Controllers\CoreX\RentalJobDispatchController::class, 'resend'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.create'])->name('v1.mobile.rental-work-orders.contractor.resend');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/cost/authorise', [\App\Http\Controllers\CoreX\RentalWorkOrderCostController::class, 'authorise'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.record_approval'])->name('v1.mobile.rental-work-orders.cost.authorise');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/cost/owner-decision', [\App\Http\Controllers\CoreX\RentalWorkOrderCostController::class, 'recordOwnerDecision'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.record_approval'])->name('v1.mobile.rental-work-orders.cost.owner-decision');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/cost/use-same-call', [\App\Http\Controllers\CoreX\RentalWorkOrderCostController::class, 'useSameCall'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.record_approval'])->name('v1.mobile.rental-work-orders.cost.use-same-call');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/dispute-seen', [\App\Http\Controllers\CoreX\RentalWorkOrderCompletionController::class, 'disputeSeen'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.manage_completion'])->name('v1.mobile.rental-work-orders.dispute-seen');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/emergency-approval/{approval}/void', [\App\Http\Controllers\CoreX\RentalEmergencyApprovalController::class, 'void'])->whereNumber(['rentalWorkOrder', 'approval'])->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.record_emergency_approval'])->name('v1.mobile.rental-work-orders.emergency-approval.void');
        Route::put('/mobile/rental-work-orders/{rentalWorkOrder}/external-fee', [\App\Http\Controllers\CoreX\RentalWorkOrderController::class, 'updateExternalFee'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_job_cards.price'])->name('v1.mobile.rental-work-orders.external-fee.update');
        Route::put('/mobile/rental-work-orders/{rentalWorkOrder}/invoices/{invoice}', [\App\Http\Controllers\CoreX\RentalWorkOrderInvoiceController::class, 'update'])->whereNumber(['rentalWorkOrder', 'invoice'])->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.manage_invoices'])->name('v1.mobile.rental-work-orders.invoices.update');
        Route::delete('/mobile/rental-work-orders/{rentalWorkOrder}/invoices/{invoice}', [\App\Http\Controllers\CoreX\RentalWorkOrderInvoiceController::class, 'destroy'])->whereNumber(['rentalWorkOrder', 'invoice'])->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.manage_invoices'])->name('v1.mobile.rental-work-orders.invoices.destroy');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/invoices/{invoice}/restore', [\App\Http\Controllers\CoreX\RentalWorkOrderInvoiceController::class, 'restore'])->whereNumber(['rentalWorkOrder', 'invoice'])->withTrashed()->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.manage_invoices'])->name('v1.mobile.rental-work-orders.invoices.restore');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/invoices/{invoice}/share', [\App\Http\Controllers\CoreX\RentalWorkOrderInvoiceController::class, 'share'])->whereNumber(['rentalWorkOrder', 'invoice'])->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.manage_invoices'])->name('v1.mobile.rental-work-orders.invoices.share');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/notes', [\App\Http\Controllers\CoreX\RentalWorkOrderController::class, 'addNote'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.create'])->name('v1.mobile.rental-work-orders.notes.store');
        Route::put('/mobile/rental-work-orders/{rentalWorkOrder}/owner-contractor', [\App\Http\Controllers\CoreX\RentalWorkOrderController::class, 'updateOwnerContractor'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.create'])->name('v1.mobile.rental-work-orders.owner-contractor.update');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/quotes', [\App\Http\Controllers\CoreX\RentalWorkOrderQuoteController::class, 'store'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.manage_quotes'])->name('v1.mobile.rental-work-orders.quotes.store');
        Route::put('/mobile/rental-work-orders/{rentalWorkOrder}/quotes/{quote}', [\App\Http\Controllers\CoreX\RentalWorkOrderQuoteController::class, 'update'])->whereNumber(['rentalWorkOrder', 'quote'])->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.manage_quotes'])->name('v1.mobile.rental-work-orders.quotes.update');
        Route::delete('/mobile/rental-work-orders/{rentalWorkOrder}/quotes/{quote}', [\App\Http\Controllers\CoreX\RentalWorkOrderQuoteController::class, 'destroy'])->whereNumber(['rentalWorkOrder', 'quote'])->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.manage_quotes'])->name('v1.mobile.rental-work-orders.quotes.destroy');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/quotes/{quote}/restore', [\App\Http\Controllers\CoreX\RentalWorkOrderQuoteController::class, 'restore'])->whereNumber(['rentalWorkOrder', 'quote'])->withTrashed()->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.manage_quotes'])->name('v1.mobile.rental-work-orders.quotes.restore');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/quotes/{quote}/select', [\App\Http\Controllers\CoreX\RentalWorkOrderQuoteController::class, 'select'])->whereNumber(['rentalWorkOrder', 'quote'])->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.record_approval'])->name('v1.mobile.rental-work-orders.quotes.select');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/restore', [\App\Http\Controllers\CoreX\RentalWorkOrderController::class, 'restore'])->whereNumber('rentalWorkOrder')->withTrashed()->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.create'])->name('v1.mobile.rental-work-orders.restore');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/send-back', [\App\Http\Controllers\CoreX\RentalWorkOrderCompletionController::class, 'sendBack'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.manage_completion'])->name('v1.mobile.rental-work-orders.send-back');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/start-progress', [\App\Http\Controllers\CoreX\RentalWorkOrderController::class, 'startProgress'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.create'])->name('v1.mobile.rental-work-orders.start-progress');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/variations/{variation}/decision', [\App\Http\Controllers\CoreX\RentalWorkOrderVariationController::class, 'decide'])->whereNumber(['rentalWorkOrder', 'variation'])->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.record_approval'])->name('v1.mobile.rental-work-orders.variations.decision');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/variations/{variation}/resend', [\App\Http\Controllers\CoreX\RentalWorkOrderVariationController::class, 'resend'])->whereNumber(['rentalWorkOrder', 'variation'])->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.record_approval'])->name('v1.mobile.rental-work-orders.variations.resend');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/who-pays/findings', [\App\Http\Controllers\CoreX\RentalWhoPaysController::class, 'findings'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.manage_completion'])->name('v1.mobile.rental-work-orders.who-pays.findings');
        Route::post('/mobile/rental-work-orders/{rentalWorkOrder}/who-pays/rule', [\App\Http\Controllers\CoreX\RentalWhoPaysController::class, 'rule'])->whereNumber('rentalWorkOrder')->middleware(['mobile.web-action', 'permission:rental_work_orders.view', 'permission:rental_work_orders.manage_completion'])->name('v1.mobile.rental-work-orders.who-pays.rule');
        Route::post('/mobile/rental-job-cards', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'store'])->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.create'])->name('v1.mobile.rental-job-cards.store');
        Route::delete('/mobile/rental-job-cards/{rentalJobCard}', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'destroy'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.create'])->name('v1.mobile.rental-job-cards.destroy');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/agent-sign-off', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'agentSignOff'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.sign_off'])->name('v1.mobile.rental-job-cards.agent-sign-off');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/assign-crew', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'assignCrew'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.create'])->name('v1.mobile.rental-job-cards.assign-crew');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/cancel', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'cancel'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.cancel'])->name('v1.mobile.rental-job-cards.cancel');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/complete', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'complete'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.sign_off'])->name('v1.mobile.rental-job-cards.complete');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/crew-lines/accept-all', [\App\Http\Controllers\CoreX\RentalJobCardCrewLineController::class, 'acceptAll'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.price'])->name('v1.mobile.rental-job-cards.crew-lines.accept-all');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/crew-lines/{line}/accept', [\App\Http\Controllers\CoreX\RentalJobCardCrewLineController::class, 'accept'])->whereNumber(['rentalJobCard', 'line'])->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.price'])->name('v1.mobile.rental-job-cards.crew-lines.accept');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/crew-lines/{line}/reject', [\App\Http\Controllers\CoreX\RentalJobCardCrewLineController::class, 'reject'])->whereNumber(['rentalJobCard', 'line'])->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.price'])->name('v1.mobile.rental-job-cards.crew-lines.reject');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/crew-link', [\App\Http\Controllers\CoreX\RentalJobCardCrewLinkController::class, 'issue'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.share'])->name('v1.mobile.rental-job-cards.crew-link.issue');
        Route::delete('/mobile/rental-job-cards/{rentalJobCard}/crew-link', [\App\Http\Controllers\CoreX\RentalJobCardCrewLinkController::class, 'revoke'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.share'])->name('v1.mobile.rental-job-cards.crew-link.revoke');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/crew-link/dispatches/{dispatch}/whatsapp', [\App\Http\Controllers\CoreX\RentalJobDispatchController::class, 'crewOpenWhatsApp'])->whereNumber(['rentalJobCard', 'dispatch'])->middleware(['mobile.web-action', 'permission:rental_job_cards.share'])->name('v1.mobile.rental-job-cards.crew-link.whatsapp-open');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/crew-link/dispatches/{dispatch}/whatsapp-sent', [\App\Http\Controllers\CoreX\RentalJobDispatchController::class, 'crewMarkWhatsAppSent'])->whereNumber(['rentalJobCard', 'dispatch'])->middleware(['mobile.web-action', 'permission:rental_job_cards.share'])->name('v1.mobile.rental-job-cards.crew-link.whatsapp-sent');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/crew-link/email', [\App\Http\Controllers\CoreX\RentalJobCardCrewLinkController::class, 'email'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.share'])->name('v1.mobile.rental-job-cards.crew-link.email');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/crew-link/send', [\App\Http\Controllers\CoreX\RentalJobDispatchController::class, 'crewSend'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.share'])->name('v1.mobile.rental-job-cards.crew-link.send');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/lines', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'storeLine'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.create'])->name('v1.mobile.rental-job-cards.lines.store');
        Route::put('/mobile/rental-job-cards/{rentalJobCard}/lines/{line}', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'updateLine'])->whereNumber(['rentalJobCard', 'line'])->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.create'])->name('v1.mobile.rental-job-cards.lines.update');
        Route::delete('/mobile/rental-job-cards/{rentalJobCard}/lines/{line}', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'destroyLine'])->whereNumber(['rentalJobCard', 'line'])->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.create'])->name('v1.mobile.rental-job-cards.lines.destroy');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/lines/{line}/restore', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'restoreLine'])->whereNumber(['rentalJobCard', 'line'])->withTrashed()->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.create'])->name('v1.mobile.rental-job-cards.lines.restore');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/markup', [\App\Http\Controllers\CoreX\RentalJobCardPricingController::class, 'markup'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.price'])->name('v1.mobile.rental-job-cards.markup');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/price-requests', [\App\Http\Controllers\CoreX\RentalJobCardPriceRequestController::class, 'store'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.share'])->name('v1.mobile.rental-job-cards.price-requests.store');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/price-requests/{priceRequest}/close', [\App\Http\Controllers\CoreX\RentalJobCardPriceRequestController::class, 'close'])->whereNumber(['rentalJobCard', 'priceRequest'])->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.share'])->name('v1.mobile.rental-job-cards.price-requests.close');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/restore', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'restore'])->whereNumber('rentalJobCard')->withTrashed()->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.create'])->name('v1.mobile.rental-job-cards.restore');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/schedule', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'schedule'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.create'])->name('v1.mobile.rental-job-cards.schedule');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/send-quote', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'sendQuote'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.send_quote'])->name('v1.mobile.rental-job-cards.send-quote');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/signed-copy', [\App\Http\Controllers\CoreX\RentalJobCardSignedCopyController::class, 'store'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.sign_off'])->name('v1.mobile.rental-job-cards.signed-copy.store');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/start', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'start'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.create'])->name('v1.mobile.rental-job-cards.start');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/tasks', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'storeTask'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.create'])->name('v1.mobile.rental-job-cards.tasks.store');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/tasks/reorder', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'reorderTasks'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.create'])->name('v1.mobile.rental-job-cards.tasks.reorder');
        Route::put('/mobile/rental-job-cards/{rentalJobCard}/tasks/{task}', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'updateTask'])->whereNumber(['rentalJobCard', 'task'])->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.create'])->name('v1.mobile.rental-job-cards.tasks.update');
        Route::delete('/mobile/rental-job-cards/{rentalJobCard}/tasks/{task}', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'destroyTask'])->whereNumber(['rentalJobCard', 'task'])->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.create'])->name('v1.mobile.rental-job-cards.tasks.destroy');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/tasks/{task}/restore', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'restoreTask'])->whereNumber(['rentalJobCard', 'task'])->withTrashed()->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.create'])->name('v1.mobile.rental-job-cards.tasks.restore');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/tenant-confirm', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'tenantConfirm'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.sign_off'])->name('v1.mobile.rental-job-cards.tenant-confirm');
        Route::post('/mobile/rental-job-cards/{rentalJobCard}/worker-sign-off', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'workerSignOff'])->whereNumber('rentalJobCard')->middleware(['mobile.web-action', 'permission:rental_job_cards.view', 'permission:rental_job_cards.sign_off'])->name('v1.mobile.rental-job-cards.worker-sign-off');
        // MOBILE PARITY (faults + work orders + job cards) END

        // MOBILE PARITY (inspections, the rest) BEGIN
        // Generated from the web route table: each route is the web action itself, with the web's own permission keys, wrapped by
        // mobile.web-action so a redirect answer becomes JSON. Spec: .ai/specs/at-508-mobile-parity-addendum.md.
        Route::post('/mobile/rental-inspections/planned-dates', [\App\Http\Controllers\CoreX\RentalInspectionDueController::class, 'store'])->middleware(['mobile.web-action', 'permission:rental_inspections.view', 'permission:rental_inspections.manage_planned_dates'])->name('v1.mobile.rental-inspections.planned-dates.store');
        Route::post('/mobile/rental-inspections/planned-dates/{plannedDate}', [\App\Http\Controllers\CoreX\RentalInspectionDueController::class, 'update'])->whereNumber('plannedDate')->middleware(['mobile.web-action', 'permission:rental_inspections.view', 'permission:rental_inspections.manage_planned_dates'])->name('v1.mobile.rental-inspections.planned-dates.update');
        Route::post('/mobile/rental-inspections/planned-dates/{plannedDate}/archive', [\App\Http\Controllers\CoreX\RentalInspectionDueController::class, 'archive'])->whereNumber('plannedDate')->middleware(['mobile.web-action', 'permission:rental_inspections.view', 'permission:rental_inspections.manage_planned_dates'])->name('v1.mobile.rental-inspections.planned-dates.archive');
        Route::post('/mobile/rental-inspections/planned-dates/{plannedDate}/reopen', [\App\Http\Controllers\CoreX\RentalInspectionDueController::class, 'reopen'])->whereNumber('plannedDate')->middleware(['mobile.web-action', 'permission:rental_inspections.view', 'permission:rental_inspections.manage_planned_dates'])->name('v1.mobile.rental-inspections.planned-dates.reopen');
        Route::post('/mobile/rental-inspections/planned-dates/{plannedDate}/restore', [\App\Http\Controllers\CoreX\RentalInspectionDueController::class, 'restore'])->whereNumber('plannedDate')->withTrashed()->middleware(['mobile.web-action', 'permission:rental_inspections.view', 'permission:rental_inspections.manage_planned_dates'])->name('v1.mobile.rental-inspections.planned-dates.restore');
        Route::post('/mobile/rental-inspections/planned-dates/{plannedDate}/skip', [\App\Http\Controllers\CoreX\RentalInspectionDueController::class, 'skip'])->whereNumber('plannedDate')->middleware(['mobile.web-action', 'permission:rental_inspections.view', 'permission:rental_inspections.manage_planned_dates'])->name('v1.mobile.rental-inspections.planned-dates.skip');
        Route::post('/mobile/rental-inspections/{rentalInspection}/deposit-comparison/items/{rentalInspectionItem}/finding', [\App\Http\Controllers\CoreX\RentalInspectionComparisonController::class, 'recordFinding'])->whereNumber(['rentalInspection', 'rentalInspectionItem'])->middleware(['mobile.web-action', 'permission:rental_inspections.view', 'permission:rental_inspections.review_deposit_comparison'])->name('v1.mobile.rental-inspections.deposit-comparison.finding');
        Route::post('/mobile/rental-inspections/{rentalInspection}/follow-up/fault-reports', [\App\Http\Controllers\CoreX\RentalInspectionController::class, 'storeFollowUpFaultReports'])->whereNumber('rentalInspection')->middleware(['mobile.web-action', 'permission:rental_inspections.view', 'permission:rental_fault_reports.create'])->name('v1.mobile.rental-inspections.follow-up.fault-reports');
        Route::post('/mobile/rental-inspections/{rentalInspection}/print-for-signature', [\App\Http\Controllers\CoreX\RentalInspectionController::class, 'printForSignatureWithLink'])->whereNumber('rentalInspection')->middleware(['mobile.web-action', 'permission:rental_inspections.view', 'permission:rental_inspections.public_link'])->name('v1.mobile.rental-inspections.print-for-signature.with-link');
        Route::post('/mobile/rental-inspections/{rentalInspection}/resend-recipient', [\App\Http\Controllers\CoreX\RentalInspectionCopiesController::class, 'resendRecipient'])->whereNumber('rentalInspection')->middleware(['mobile.web-action', 'permission:rental_inspections.view', 'permission:rental_inspections.create'])->name('v1.mobile.rental-inspections.resend-recipient');
        Route::post('/mobile/rental-inspections/{rentalInspection}/scans', [\App\Http\Controllers\CoreX\RentalInspectionScanController::class, 'store'])->whereNumber('rentalInspection')->middleware(['mobile.web-action', 'permission:rental_inspections.view', 'permission:rental_inspections.create'])->name('v1.mobile.rental-inspections.scans.store');
        Route::delete('/mobile/rental-inspections/{rentalInspection}/scans/{scan}', [\App\Http\Controllers\CoreX\RentalInspectionScanController::class, 'destroy'])->whereNumber(['rentalInspection', 'scan'])->middleware(['mobile.web-action', 'permission:rental_inspections.view', 'permission:rental_inspections.create'])->name('v1.mobile.rental-inspections.scans.destroy');
        Route::post('/mobile/rental-inspections/{rentalInspection}/scans/{scan}/apply', [\App\Http\Controllers\CoreX\RentalInspectionScanController::class, 'apply'])->whereNumber(['rentalInspection', 'scan'])->middleware(['mobile.web-action', 'permission:rental_inspections.view', 'permission:rental_inspections.create'])->name('v1.mobile.rental-inspections.scans.apply');
        // MOBILE PARITY (inspections, the rest) END

        // MOBILE PARITY (inspection checklist + pairing) BEGIN
        // Generated from the web route table: each route is the web action itself, with the web's own permission keys, wrapped by
        // mobile.web-action so a redirect answer becomes JSON. Spec: .ai/specs/at-508-mobile-parity-addendum.md.
        Route::post('/mobile/properties/{property}/rental-inspection-items', [\App\Http\Controllers\CoreX\RentalInspectionRecordingController::class, 'storeItem'])->whereNumber('property')->middleware(['mobile.web-action', 'permission:access_properties', 'permission:rental_inspections.create'])->name('v1.mobile.properties.rental-inspection-items.store');
        Route::post('/mobile/properties/{property}/rental-inspection-items/reorder', [\App\Http\Controllers\CoreX\RentalInspectionRecordingController::class, 'reorderItems'])->whereNumber('property')->middleware(['mobile.web-action', 'permission:access_properties', 'permission:rental_inspections.create'])->name('v1.mobile.properties.rental-inspection-items.reorder');
        Route::post('/mobile/properties/{property}/rental-inspection-items/seed-from-advertising', [\App\Http\Controllers\CoreX\RentalInspectionRecordingController::class, 'seedFromAdvertising'])->whereNumber('property')->middleware(['mobile.web-action', 'permission:access_properties', 'permission:rental_inspections.create'])->name('v1.mobile.properties.rental-inspection-items.seed-from-advertising');
        Route::post('/mobile/properties/{property}/rental-inspection-items/seed-from-standard', [\App\Http\Controllers\CoreX\RentalInspectionRecordingController::class, 'seedFromStandardChecklist'])->whereNumber('property')->middleware(['mobile.web-action', 'permission:access_properties', 'permission:rental_inspections.create'])->name('v1.mobile.properties.rental-inspection-items.seed-from-standard');
        Route::post('/mobile/properties/{property}/rental-inspection-items/{item}/assign-type', [\App\Http\Controllers\CoreX\RentalInspectionRecordingController::class, 'assignType'])->whereNumber(['property', 'item'])->middleware(['mobile.web-action', 'permission:access_properties', 'permission:rental_inspections.create'])->name('v1.mobile.properties.rental-inspection-items.assign-type');
        Route::post('/mobile/properties/{property}/rental-inspection-items/{item}/rename', [\App\Http\Controllers\CoreX\RentalInspectionRecordingController::class, 'renameItem'])->whereNumber(['property', 'item'])->middleware(['mobile.web-action', 'permission:access_properties', 'permission:rental_inspections.create'])->name('v1.mobile.properties.rental-inspection-items.rename');
        Route::post('/mobile/properties/{property}/rental-inspection-items/{item}/restore', [\App\Http\Controllers\CoreX\RentalInspectionRecordingController::class, 'restoreItem'])->whereNumber(['property', 'item'])->withTrashed()->middleware(['mobile.web-action', 'permission:access_properties', 'permission:rental_inspections.create'])->name('v1.mobile.properties.rental-inspection-items.restore');
        Route::post('/mobile/properties/{property}/rental-inspection-items/{item}/retire', [\App\Http\Controllers\CoreX\RentalInspectionRecordingController::class, 'retireItem'])->whereNumber(['property', 'item'])->middleware(['mobile.web-action', 'permission:access_properties', 'permission:rental_inspections.create'])->name('v1.mobile.properties.rental-inspection-items.retire');
        Route::post('/mobile/properties/{property}/rental-inspection-photo-matches', [\App\Http\Controllers\CoreX\RentalInspectionRecordingController::class, 'storePhotoMatch'])->whereNumber('property')->middleware(['mobile.web-action', 'permission:access_properties', 'permission:rental_inspections.create'])->name('v1.mobile.properties.rental-inspection-photo-matches.store');
        Route::post('/mobile/properties/{property}/rental-inspection-photo-matches/auto-pair', [\App\Http\Controllers\CoreX\RentalInspectionRecordingController::class, 'autoPairPhotoMatches'])->whereNumber('property')->middleware(['mobile.web-action', 'permission:access_properties', 'permission:rental_inspections.create'])->name('v1.mobile.properties.rental-inspection-photo-matches.auto-pair');
        Route::delete('/mobile/properties/{property}/rental-inspection-photo-matches/{member}', [\App\Http\Controllers\CoreX\RentalInspectionRecordingController::class, 'destroyPhotoMatch'])->whereNumber(['property', 'member'])->middleware(['mobile.web-action', 'permission:access_properties', 'permission:rental_inspections.create'])->name('v1.mobile.properties.rental-inspection-photo-matches.destroy');
        Route::post('/mobile/properties/{property}/rental-inspection-rooms/apply-default-order', [\App\Http\Controllers\CoreX\RentalInspectionRecordingController::class, 'applyDefaultRoomOrder'])->whereNumber('property')->middleware(['mobile.web-action', 'permission:access_properties', 'permission:rental_inspections.create'])->name('v1.mobile.properties.rental-inspection-rooms.apply-default-order');
        Route::post('/mobile/properties/{property}/rental-inspection-rooms/reorder', [\App\Http\Controllers\CoreX\RentalInspectionRecordingController::class, 'reorderRooms'])->whereNumber('property')->middleware(['mobile.web-action', 'permission:access_properties', 'permission:rental_inspections.create'])->name('v1.mobile.properties.rental-inspection-rooms.reorder');
        Route::post('/mobile/properties/{property}/rental-inspection-rooms/{room}/missing-standard-items', [\App\Http\Controllers\CoreX\RentalInspectionRoomChecklistController::class, 'topUp'])->whereNumber(['property', 'room'])->middleware(['mobile.web-action', 'permission:access_properties', 'permission:rental_inspections.create'])->name('v1.mobile.properties.rental-inspection-rooms.add-missing-standard-items');
        // MOBILE PARITY (inspection checklist + pairing) END

        // MOBILE PARITY (lists + pickers) BEGIN
        // The web lists that are pages with no JSON twin (they RUN the web action and answer its data, so the query is the web's own), the
        // pickers a form needs, the JSON property pickers the web already has, and the user's own permission keys for the rentals areas.
        Route::get('/mobile/breaches', [\App\Http\Controllers\Api\V1\MobileRentalListsController::class, 'breaches'])->middleware('permission:rental_breaches.view')->name('v1.mobile.breaches.index');
        Route::get('/mobile/rental-notices-sent', [\App\Http\Controllers\Api\V1\MobileRentalListsController::class, 'sentNotices'])->middleware('permission:rental_notices.view')->name('v1.mobile.rental-notices-sent.index');
        Route::get('/mobile/rental-applications', [\App\Http\Controllers\Api\V1\MobileRentalListsController::class, 'applications'])->middleware('permission:rental_applications.view')->name('v1.mobile.rental-applications.index');
        Route::get('/mobile/rental-inspections/{rentalInspection}/deposit-comparison', [\App\Http\Controllers\Api\V1\MobileRentalListsController::class, 'depositComparison'])->whereNumber('rentalInspection')
            ->middleware('permission:rental_inspections.view')->name('v1.mobile.rental-inspections.deposit-comparison');
        Route::get('/mobile/rental-inspectors', [\App\Http\Controllers\Api\V1\MobileRentalListsController::class, 'inspectors'])->middleware('permission:rental_inspections.view')->name('v1.mobile.rental-inspectors.index');
        Route::get('/mobile/leases/{lease}/selectable-agents', [\App\Http\Controllers\Api\V1\MobileRentalListsController::class, 'leaseAgents'])->whereNumber('lease')->middleware('permission:leases.view')->name('v1.mobile.leases.selectable-agents');
        Route::get('/mobile/rental-notice-templates', [\App\Http\Controllers\Api\V1\MobileRentalListsController::class, 'noticeTemplates'])->middleware('permission:rental_notices.view')->name('v1.mobile.rental-notice-templates.index');
        Route::get('/mobile/rental-crews', [\App\Http\Controllers\Api\V1\MobileRentalListsController::class, 'crews'])->middleware('permission:rental_job_cards.view')->name('v1.mobile.rental-crews.index');
        Route::get('/mobile/rentals/permissions', [\App\Http\Controllers\Api\V1\MobileRentalListsController::class, 'permissions'])->name('v1.mobile.rentals.permissions');
        Route::get('/mobile/leases/party-check', [\App\Http\Controllers\CoreX\LeaseController::class, 'partyCheck'])->middleware('permission:leases.view')->name('v1.mobile.leases.party-check');
        Route::get('/mobile/leases/search-properties', [\App\Http\Controllers\CoreX\LeaseController::class, 'searchProperties'])->middleware('permission:leases.view')->name('v1.mobile.leases.search-properties');
        Route::get('/mobile/leases/search-rental-properties', [\App\Http\Controllers\CoreX\LeaseController::class, 'searchRentalProperties'])->middleware(['permission:leases.view', 'permission:leases.create'])->name('v1.mobile.leases.search-rental-properties');
        Route::get('/mobile/rental-fault-reports/search-properties', [\App\Http\Controllers\CoreX\RentalFaultReportController::class, 'searchProperties'])->middleware('permission:rental_fault_reports.view')->name('v1.mobile.rental-fault-reports.search-properties');
        Route::get('/mobile/rental-job-cards/search-properties', [\App\Http\Controllers\CoreX\RentalJobCardController::class, 'searchProperties'])->middleware('permission:rental_job_cards.view')->name('v1.mobile.rental-job-cards.search-properties');
        Route::get('/mobile/rental-work-orders/search-properties', [\App\Http\Controllers\CoreX\RentalWorkOrderController::class, 'searchProperties'])->middleware(['permission:rental_work_orders.view', 'permission:rental_work_orders.create'])->name('v1.mobile.rental-work-orders.search-properties');
        // MOBILE PARITY (lists + pickers) END

        // MOBILE PARITY (maintenance reads) BEGIN
        // The job-card list (the web's own RentalJobCardListQuery) and the contractor list an agent assigns a work order from.
        Route::get('/mobile/rental-job-cards', [\App\Http\Controllers\Api\V1\MobileRentalMaintenanceReadsController::class, 'jobCards'])
            ->middleware('permission:rental_job_cards.view')->name('v1.mobile.rental-job-cards.index');
        Route::get('/mobile/rental-suppliers', [\App\Http\Controllers\Api\V1\MobileRentalMaintenanceReadsController::class, 'suppliers'])
            ->middleware('permission:rental_work_orders.view')->name('v1.mobile.rental-suppliers.index');
        // MOBILE PARITY (maintenance reads) END

        // MOBILE PARITY (inspection reads) BEGIN
        // The web's JSON / file reads for the inspection screens: the tab data (with the photo pairing and compare data), the standard items a room is
        // missing, the printable site form, a scan's file, a signature's file, who attended. Same action, same permission as the web.
        Route::get('/mobile/properties/{property}/rental-inspection-tab', [\App\Http\Controllers\CoreX\RentalInspectionRecordingController::class, 'tabData'])
            ->whereNumber('property')->middleware(['permission:rental_inspections.view'])->name('v1.mobile.properties.rental-inspection-tab.data');
        Route::get('/mobile/properties/{property}/rental-inspection-rooms/{room}/missing-standard-items', [\App\Http\Controllers\CoreX\RentalInspectionRoomChecklistController::class, 'missing'])
            ->whereNumber(['property', 'room'])->middleware(['permission:rental_inspections.view'])->name('v1.mobile.properties.rental-inspection-rooms.missing-standard-items');
        Route::get('/mobile/rental-inspections/{rentalInspection}/attendance', [\App\Http\Controllers\CoreX\RentalInspectionAttendanceController::class, 'show'])
            ->whereNumber('rentalInspection')->middleware(['permission:rental_inspections.view'])->name('v1.mobile.rental-inspections.attendance.show');
        Route::get('/mobile/rental-inspections/{rentalInspection}/form', [\App\Http\Controllers\CoreX\RentalInspectionController::class, 'form'])
            ->whereNumber('rentalInspection')->middleware(['permission:rental_inspections.view'])->name('v1.mobile.rental-inspections.form');
        Route::get('/mobile/rental-inspections/{rentalInspection}/signatures/{signature}/{kind}', [\App\Http\Controllers\CoreX\RentalInspectionController::class, 'signatureFile'])
            ->whereNumber(['rentalInspection', 'signature'])->where('kind', 'signature|wet-ink')->middleware(['permission:rental_inspections.view'])->name('v1.mobile.rental-inspections.signatures.file');
        // MOBILE PARITY (inspection reads) END

        // MOBILE PARITY (rental applications: create + send) BEGIN
        // Generated from the web route table: each route is the web action itself, with the web's own permission keys, wrapped by
        // mobile.web-action so a redirect answer becomes JSON. Spec: .ai/specs/at-508-mobile-parity-addendum.md.
        Route::post('/mobile/rental-applications', [\App\Http\Controllers\CoreX\RentalApplicationController::class, 'store'])->middleware(['mobile.web-action', 'permission:rental_applications.view', 'permission:rental_applications.create'])->name('v1.mobile.rental-applications.store');
        Route::post('/mobile/rental-applications/contacts/quick-create', [\App\Http\Controllers\CoreX\RentalApplicationController::class, 'quickCreateContact'])->middleware(['mobile.web-action', 'permission:rental_applications.view', 'permission:rental_applications.create'])->name('v1.mobile.rental-applications.contacts.quick-create');
        Route::post('/mobile/rental-applications/{rentalApplication}/reissue-link', [\App\Http\Controllers\CoreX\RentalApplicationController::class, 'reissueLink'])->whereNumber('rentalApplication')->middleware(['mobile.web-action', 'permission:rental_applications.view', 'permission:rental_applications.create'])->name('v1.mobile.rental-applications.reissue-link');
        Route::post('/mobile/rental-applications/{rentalApplication}/send', [\App\Http\Controllers\CoreX\RentalApplicationController::class, 'send'])->whereNumber('rentalApplication')->middleware(['mobile.web-action', 'permission:rental_applications.view', 'permission:rental_applications.create'])->name('v1.mobile.rental-applications.send');
        Route::post('/mobile/rental-applications/{rentalApplication}/share-link', [\App\Http\Controllers\Api\V1\MobileRentalLeaseActionsController::class, 'shareApplicationLink'])->whereNumber('rentalApplication')->middleware(['permission:rental_applications.view', 'permission:rental_applications.create'])->name('v1.mobile.rental-applications.share-link');
        // MOBILE PARITY (rental applications: create + send) END

        // MOBILE PARITY (breaches + notices sent + notices received) BEGIN
        // Generated from the web route table: each route is the web action itself, with the web's own permission keys, wrapped by
        // mobile.web-action so a redirect answer becomes JSON. Spec: .ai/specs/at-508-mobile-parity-addendum.md.
        Route::post('/mobile/breaches/bulk/send', [\App\Http\Controllers\Api\V1\MobileRentalLeaseActionsController::class, 'bulkBreachSend'])->middleware(['permission:rental_breaches.manage'])->name('v1.mobile.breaches.bulk.store');
        Route::post('/mobile/breaches/{id}/archive', [\App\Http\Controllers\CoreX\LeaseBreachController::class, 'archive'])->whereNumber('id')->middleware(['mobile.web-action', 'permission:rental_breaches.manage'])->name('v1.mobile.breaches.archive');
        Route::post('/mobile/breaches/{id}/remedy', [\App\Http\Controllers\CoreX\LeaseBreachController::class, 'remedy'])->whereNumber('id')->middleware(['mobile.web-action', 'permission:rental_breaches.manage'])->name('v1.mobile.breaches.remedy');
        Route::post('/mobile/breaches/{id}/restore', [\App\Http\Controllers\CoreX\LeaseBreachController::class, 'restore'])->whereNumber('id')->withTrashed()->middleware(['mobile.web-action', 'permission:rental_breaches.manage'])->name('v1.mobile.breaches.restore');
        Route::post('/mobile/breaches/{id}/vacate', [\App\Http\Controllers\CoreX\LeaseBreachController::class, 'vacate'])->whereNumber('id')->middleware(['mobile.web-action', 'permission:rental_breaches.manage'])->name('v1.mobile.breaches.vacate');
        Route::post('/mobile/rental-notices/{id}/restore', [\App\Http\Controllers\CoreX\RentalNoticeController::class, 'restore'])->whereNumber('id')->withTrashed()->middleware(['mobile.web-action', 'permission:rental_notices.view', 'permission:rental_notices.create'])->name('v1.mobile.rental-notices.restore');
        Route::post('/mobile/rental-notices/{rentalNotice}/archive', [\App\Http\Controllers\CoreX\RentalNoticeController::class, 'archive'])->whereNumber('rentalNotice')->middleware(['mobile.web-action', 'permission:rental_notices.view', 'permission:rental_notices.create'])->name('v1.mobile.rental-notices.archive');
        Route::post('/mobile/rental-notices/{rentalNotice}/resend', [\App\Http\Controllers\CoreX\RentalNoticeController::class, 'resend'])->whereNumber('rentalNotice')->middleware(['mobile.web-action', 'permission:rental_notices.view', 'permission:rental_notices.create'])->name('v1.mobile.rental-notices.resend');
        Route::put('/mobile/lease-notices/{id}', [\App\Http\Controllers\CoreX\LeaseNoticeController::class, 'update'])->whereNumber('id')->middleware(['mobile.web-action', 'permission:rental_notices.review'])->name('v1.mobile.lease-notices.update');
        Route::post('/mobile/lease-notices/{id}/archive', [\App\Http\Controllers\CoreX\LeaseNoticeController::class, 'archive'])->whereNumber('id')->middleware(['mobile.web-action', 'permission:rental_notices.review'])->name('v1.mobile.lease-notices.archive');
        Route::post('/mobile/lease-notices/{id}/restore', [\App\Http\Controllers\CoreX\LeaseNoticeController::class, 'restore'])->whereNumber('id')->withTrashed()->middleware(['mobile.web-action', 'permission:rental_notices.review'])->name('v1.mobile.lease-notices.restore');
        // MOBILE PARITY (breaches + notices sent + notices received) END

        // MOBILE PARITY (leases, the rest) BEGIN
        // Generated from the web route table: each route is the web action itself, with the web's own permission keys, wrapped by
        // mobile.web-action so a redirect answer becomes JSON. Spec: .ai/specs/at-508-mobile-parity-addendum.md.
        Route::post('/mobile/leases/application-co-applicant', [\App\Http\Controllers\CoreX\LeaseController::class, 'coApplicantContact'])->middleware(['mobile.web-action', 'permission:leases.view', 'permission:leases.create'])->name('v1.mobile.leases.application-co-applicant');
        Route::post('/mobile/leases/{lease}/deposit-deductions/reviewed', [\App\Http\Controllers\CoreX\LeaseDepositDeductionsController::class, 'markReviewed'])->whereNumber('lease')->middleware(['mobile.web-action', 'permission:leases.view', 'permission:rental_inspections.review_deposit_comparison'])->name('v1.mobile.leases.deposit-deductions.reviewed');
        Route::post('/mobile/leases/{lease}/portal-access/{contact}/invite', [\App\Http\Controllers\CoreX\LeasePortalAccessController::class, 'invite'])->whereNumber(['lease', 'contact'])->middleware(['mobile.web-action', 'permission:leases.view'])->name('v1.mobile.leases.portal-access.invite');
        Route::post('/mobile/leases/{lease}/portal-access/{contact}/setup', [\App\Http\Controllers\CoreX\LeasePortalAccessController::class, 'setup'])->whereNumber(['lease', 'contact'])->middleware(['mobile.web-action', 'permission:leases.view'])->name('v1.mobile.leases.portal-access.setup');
        Route::post('/mobile/leases/{lease}/portal-access/{contact}/switch', [\App\Http\Controllers\CoreX\LeasePortalAccessController::class, 'switchEmail'])->whereNumber(['lease', 'contact'])->middleware(['mobile.web-action', 'permission:leases.view'])->name('v1.mobile.leases.portal-access.switch');
        // MOBILE PARITY (leases, the rest) END



        // ── Mobile P24 location tree (token-authed) ──────────────────
        Route::prefix('mobile/p24')->group(function () {
            Route::get('/provinces', [\App\Http\Controllers\Api\V1\P24LocationController::class, 'provinces'])->name('v1.mobile.p24.provinces');
            Route::get('/cities',    [\App\Http\Controllers\Api\V1\P24LocationController::class, 'cities'])->name('v1.mobile.p24.cities');
            Route::get('/suburbs',   [\App\Http\Controllers\Api\V1\P24LocationController::class, 'suburbs'])->name('v1.mobile.p24.suburbs');
        });

        // ── Mobile Properties ────────────────────────────────────────
        // AT-267 — the mobile create + image-upload endpoints had NO permission middleware at
        // all, so the app was the widest-open route onto the agency's books. Gated at the group
        // so a new mobile property-write endpoint is covered by default; `update` is on the
        // middleware's allow list, because editing the agent's listing IS the assistant's job.
        Route::prefix('mobile/properties')->middleware('deny_assistant_property_write')->group(function () {
            Route::get('/',         [MobilePropertyController::class, 'index'])->name('v1.mobile.properties.index');
            Route::post('/',        [MobilePropertyController::class, 'store'])->name('v1.mobile.properties.store');

            Route::get('/options',        [MobilePropertyController::class, 'options'])->name('v1.mobile.properties.options');
            Route::get('/spaces/catalog', [MobilePropertyController::class, 'spacesCatalog'])->name('v1.mobile.properties.spaces.catalog');

            Route::get('/{property}',  [MobilePropertyController::class, 'show'])->name('v1.mobile.properties.show');
            Route::put('/{property}',  [MobilePropertyController::class, 'update'])->name('v1.mobile.properties.update');
            Route::post('/{property}/images', [MobilePropertyController::class, 'uploadImage'])->name('v1.mobile.properties.images.upload');

            // AI vision suggestions on uploaded property images
            Route::get('/{property}/ai-suggestions',         [\App\Http\Controllers\Api\PropertyImageAiController::class, 'suggestions'])->name('v1.mobile.properties.ai.suggestions');
            Route::post('/{property}/features/merge-ai',     [\App\Http\Controllers\Api\PropertyImageAiController::class, 'mergeFeatures'])->name('v1.mobile.properties.ai.features.merge');

            Route::get('/{property}/overview', [MobilePropertyController::class, 'overview'])->name('v1.mobile.properties.overview');

            // Public portal links (company website, P24, Private Property, + future portals)
            Route::get('/{property}/portal-links', [MobilePropertyController::class, 'portalLinks'])->name('v1.mobile.properties.portal-links');

            Route::get('/{property}/compliance',                 [MobilePropertyController::class, 'compliance'])->name('v1.mobile.properties.compliance');
            Route::post('/{property}/compliance/send-to-market', [MobilePropertyController::class, 'sendToMarket'])->name('v1.mobile.properties.compliance.send-to-market');

            Route::get('/{property}/contacts',              [MobilePropertyController::class, 'contactsIndex'])->name('v1.mobile.properties.contacts.index');
            Route::post('/{property}/contacts',             [MobilePropertyController::class, 'contactsLink'])->name('v1.mobile.properties.contacts.link');
            Route::delete('/{property}/contacts/{contact}', [MobilePropertyController::class, 'contactsUnlink'])->name('v1.mobile.properties.contacts.unlink');

            // Property Drive, read-only — .ai/specs/mobile-property-drive.md
            Route::get('/{property}/documents',                        [MobilePropertyController::class, 'documentsIndex'])->name('v1.mobile.properties.documents.index');
            Route::get('/{property}/documents/{document}/download',    [MobilePropertyController::class, 'documentsDownload'])->middleware('deny_assistant_download')->name('v1.mobile.properties.documents.download');

            Route::get('/{property}/gallery/tags',          [MobilePropertyController::class, 'galleryTags'])->name('v1.mobile.properties.gallery.tags.index');
            Route::post('/{property}/gallery/tags',         [MobilePropertyController::class, 'addCustomTag'])->name('v1.mobile.properties.gallery.tags.add');
            Route::delete('/{property}/gallery/tags',       [MobilePropertyController::class, 'removeCustomTag'])->name('v1.mobile.properties.gallery.tags.remove');
            // File already-uploaded photos under a room (or back to unsorted).
            // Without this, room_tag could only be set in the upload request
            // itself, so anything that arrived untagged stayed untagged forever
            // on mobile. See MobilePropertyController::assignGalleryTag().
            Route::put('/{property}/gallery/assign',        [MobilePropertyController::class, 'assignGalleryTag'])->name('v1.mobile.properties.gallery.assign');
            // Drag-reorder the photo grid (or one tag's bucket) and the tag list
            // itself. Spec: .ai/specs/mobile-gallery-sort.md
            Route::put('/{property}/gallery/reorder',       [MobilePropertyController::class, 'reorderImages'])->name('v1.mobile.properties.gallery.reorder');
            Route::put('/{property}/gallery/tags/reorder',  [MobilePropertyController::class, 'reorderGalleryTags'])->name('v1.mobile.properties.gallery.tags.reorder');
            // Take a photo back off the listing. Needed once the app enqueues at
            // the shutter and drains without waiting for the camera to close: a
            // photo deleted in review may already be on the server, and until now
            // the app could add photos but never remove them.
            Route::post('/{property}/images/delete',       [MobilePropertyController::class, 'deleteImages'])->name('v1.mobile.properties.images.delete');

            Route::get('/{property}/spaces', [MobilePropertyController::class, 'spacesShow'])->name('v1.mobile.properties.spaces.show');
            Route::put('/{property}/spaces', [MobilePropertyController::class, 'spacesUpdate'])->name('v1.mobile.properties.spaces.update');

            // Rental inspection galleries (in/out/custom) — rental-only + live-only.
            // The mobile app shows the tab when property.rental_inspections_available
            // is true; these endpoints enforce the same gate. Spec: .ai/specs/rental-images.md
            Route::get('/{property}/rental-images',        [\App\Http\Controllers\Api\MobileRentalImagesController::class, 'index'])->name('v1.mobile.properties.rental-images.index');
            Route::post('/{property}/rental-images/upload', [\App\Http\Controllers\Api\MobileRentalImagesController::class, 'upload'])->name('v1.mobile.properties.rental-images.upload');
            Route::post('/{property}/rental-images/save',   [\App\Http\Controllers\Api\MobileRentalImagesController::class, 'save'])->name('v1.mobile.properties.rental-images.save');
            Route::post('/{property}/rental-images/delete', [\App\Http\Controllers\Api\MobileRentalImagesController::class, 'destroyImage'])->name('v1.mobile.properties.rental-images.delete');
        });

        // ── Mobile Ellie Voice ──────────────────────────────────────
        Route::prefix('mobile/ellie')->group(function () {
            Route::post('/voice',                     [\App\Http\Controllers\Api\MobileEllieVoiceController::class, 'process'])->name('v1.mobile.ellie.voice');
            Route::delete('/voice/events/{event}',    [\App\Http\Controllers\Api\MobileEllieVoiceController::class, 'undoEvent'])->name('v1.mobile.ellie.voice.undo');
        });

        // ── Mobile feature flags (advanced AI features) ─────────────
        Route::get('/mobile/features', [\App\Http\Controllers\Api\MobileFeatureFlagController::class, 'index'])
            ->name('v1.mobile.features');

        // Photo upload telemetry — the app reports what it observed happening to
        // each photo (captured / queued / attempted / failed / dropped) so a
        // photo that dies BEFORE the upload queue still leaves a trace. Without
        // it the server only ever sees the survivors, and "40 taken, 28 landed"
        // is unanswerable. Throttled generously: a 40-photo shoot is ~200 events
        // and the client batches, so this should be a handful of calls per shoot.
        // Spec: .ai/specs/mobile-photo-upload-telemetry.md
        Route::post('/mobile/photo-events', [\App\Http\Controllers\Api\MobilePhotoEventController::class, 'store'])
            ->middleware('throttle:60,1')
            ->name('v1.mobile.photo-events.store');

        // ── Mobile calendar (auth-user-only, web-parity filters) ─────
        Route::get('/mobile/calendar', [\App\Http\Controllers\Api\MobileCalendarController::class, 'index'])
            ->name('v1.mobile.calendar.index');

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
            Route::get('/{contact}/drive/{document}/download',   [MobileContactComplianceController::class, 'driveDownload'])->middleware('deny_assistant_download')->name('v1.mobile.contacts.drive.download');
            Route::delete('/{contact}/drive/{document}',         [MobileContactComplianceController::class, 'driveDestroy'])->name('v1.mobile.contacts.drive.destroy');

            Route::get('/{contact}/fica', [MobileContactComplianceController::class, 'ficaIndex'])->name('v1.mobile.contacts.fica.index');

            // Notes & Testimonials — same tables/rules as the web Contact
            // "Notes & Testimonials" tab. Spec: .ai/specs/contact-notes-testimonials.md
            Route::get('/{contact}/notes',           [\App\Http\Controllers\Api\MobileContactNotesController::class, 'notesIndex'])->name('v1.mobile.contacts.notes.index');
            Route::post('/{contact}/notes',          [\App\Http\Controllers\Api\MobileContactNotesController::class, 'notesStore'])->name('v1.mobile.contacts.notes.store');
            Route::put('/{contact}/notes/{note}',    [\App\Http\Controllers\Api\MobileContactNotesController::class, 'notesUpdate'])->name('v1.mobile.contacts.notes.update');
            Route::delete('/{contact}/notes/{note}', [\App\Http\Controllers\Api\MobileContactNotesController::class, 'notesDestroy'])->name('v1.mobile.contacts.notes.destroy');

            Route::get('/{contact}/testimonials',                       [\App\Http\Controllers\Api\MobileContactNotesController::class, 'testimonialsIndex'])->name('v1.mobile.contacts.testimonials.index');
            Route::post('/{contact}/testimonials',                      [\App\Http\Controllers\Api\MobileContactNotesController::class, 'testimonialsStore'])->name('v1.mobile.contacts.testimonials.store');
            Route::put('/{contact}/testimonials/{testimonial}',         [\App\Http\Controllers\Api\MobileContactNotesController::class, 'testimonialsUpdate'])->name('v1.mobile.contacts.testimonials.update');
            Route::delete('/{contact}/testimonials/{testimonial}',      [\App\Http\Controllers\Api\MobileContactNotesController::class, 'testimonialsDestroy'])->name('v1.mobile.contacts.testimonials.destroy');
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

        // ── Mobile Portal Leads (P24 + PP unified) ──────────────────
        // Spec: .ai/specs/portal-leads.md
        Route::prefix('mobile/portal-leads')->group(function () {
            Route::get('/',                          [\App\Http\Controllers\Api\V1\MobilePortalLeadController::class, 'index'])->name('v1.mobile.portal-leads.index');
            Route::get('/dates',                     [\App\Http\Controllers\Api\V1\MobilePortalLeadController::class, 'dates'])->name('v1.mobile.portal-leads.dates');
            Route::get('/{portalLead}',              [\App\Http\Controllers\Api\V1\MobilePortalLeadController::class, 'show'])->name('v1.mobile.portal-leads.show');
            Route::post('/{portalLead}/mark-read',   [\App\Http\Controllers\Api\V1\MobilePortalLeadController::class, 'markRead'])->name('v1.mobile.portal-leads.mark-read');
        });

        // ── Command Center ────────────────────────────────────────────
        Route::prefix('command-center')->group(function () {
            Route::get('/dashboard',       [CommandCenterApiController::class, 'dashboard'])->name('v1.command-center.dashboard');
            Route::get('/today',           [CommandCenterApiController::class, 'today'])->name('v1.command-center.today');
            Route::post('/today/refresh',  [CommandCenterApiController::class, 'todayRefresh'])->name('v1.command-center.today.refresh');

            // ── Event reminders (AT-178) MOVED to routes/web.php's session-authenticated
            // api/v1 group (browser-visible XHR). They are polled by the reminder-toast
            // blade component from the browser session, which this auth:sanctum
            // (token-only, stateful session disabled here) group rejected with 401.
            // See routes/web.php `api.v1.command-center.reminders.*`.

            Route::get('/calendar',                                       [CommandCenterApiController::class, 'calendarIndex'])->name('v1.command-center.calendar.index');
            Route::post('/calendar',                                      [CommandCenterApiController::class, 'calendarStore'])->name('v1.command-center.calendar.store');
            // Static-segment GETs MUST precede the /calendar/{calendarEvent}
            // wildcard below, or "options"/"search"/"properties" would bind as
            // an event id and 404 on model resolution.
            Route::get('/calendar/options',                               [CommandCenterApiController::class, 'calendarOptions'])->name('v1.command-center.calendar.options');
            Route::get('/calendar/conflicts',                             [CommandCenterApiController::class, 'calendarConflicts'])->name('v1.command-center.calendar.conflicts');
            Route::get('/calendar/invitations',                           [CommandCenterApiController::class, 'invitationsIndex'])->name('v1.command-center.calendar.invitations.index');
            Route::post('/calendar/invitations/{invitation}/respond',     [CommandCenterApiController::class, 'invitationRespond'])->name('v1.command-center.calendar.invitations.respond');
            Route::post('/calendar/invitations/{invitation}/acknowledge', [CommandCenterApiController::class, 'invitationAcknowledge'])->name('v1.command-center.calendar.invitations.acknowledge');
            // Create-form data — reuse the web cockpit methods verbatim so the
            // mobile create screen and the cockpit share one implementation.
            Route::get('/calendar/search/attendees',                      [CommandCenterCalendarController::class, 'searchAttendees'])->name('v1.command-center.calendar.search.attendees');
            Route::get('/calendar/properties/{property}/owners',          [CommandCenterCalendarController::class, 'propertyOwners'])->name('v1.command-center.calendar.property-owners');
            Route::post('/calendar/{calendarEvent}/complete',             [CommandCenterApiController::class, 'calendarComplete'])->name('v1.command-center.calendar.complete');
            Route::post('/calendar/{calendarEvent}/dismiss',              [CommandCenterApiController::class, 'calendarDismiss'])->name('v1.command-center.calendar.dismiss');
            Route::get('/calendar/{calendarEvent}',                       [CommandCenterCalendarController::class, 'show'])->name('v1.command-center.calendar.show');
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
            'is_assistant' => (bool) $user->is_assistant,
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
    Route::post('/prospecting/import',      [ProspectingApiController::class, 'import'])->middleware('deny_assistant_property_write')->name('legacy.prospecting.import'); // AT-267 C2
    Route::get('/prospecting/check-search', [ProspectingApiController::class, 'checkSearch'])->name('legacy.prospecting.check-search');

    // LEGACY: remove after 2026-08-21
    Route::post('/properties/pull-from-portal',        [PropertyPullController::class, 'pullFromPortal'])->middleware('deny_assistant_property_write')->name('legacy.properties.pull-from-portal'); // AT-267 C2
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
        Route::post('/',        [MobilePropertyController::class, 'store'])->middleware('deny_assistant_property_write')->name('legacy.mobile.properties.store'); // AT-267 C2
        Route::get('/options',        [MobilePropertyController::class, 'options'])->name('legacy.mobile.properties.options');
        Route::get('/spaces/catalog', [MobilePropertyController::class, 'spacesCatalog'])->name('legacy.mobile.properties.spaces.catalog');
        Route::get('/{property}',  [MobilePropertyController::class, 'show'])->name('legacy.mobile.properties.show');
        Route::put('/{property}',  [MobilePropertyController::class, 'update'])->name('legacy.mobile.properties.update');
        Route::post('/{property}/images', [MobilePropertyController::class, 'uploadImage'])->name('legacy.mobile.properties.images.upload');
        Route::get('/{property}/overview', [MobilePropertyController::class, 'overview'])->name('legacy.mobile.properties.overview');
        Route::get('/{property}/portal-links', [MobilePropertyController::class, 'portalLinks'])->name('legacy.mobile.properties.portal-links');
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
        Route::get('/{contact}/drive/{document}/download',   [MobileContactComplianceController::class, 'driveDownload'])->middleware('deny_assistant_download')->name('mobile.contacts.drive.download');
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
});

