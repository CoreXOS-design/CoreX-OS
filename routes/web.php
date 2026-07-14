<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WorksheetController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\CompanySummaryController;
use App\Http\Controllers\Admin\ListingTargetController;
use App\Http\Controllers\Admin\ViewAsController;
use App\Http\Controllers\Admin\BranchAssignmentController;
use App\Http\Controllers\Admin\DealController;
use App\Http\Controllers\Agent\DealRegisterController;
use App\Http\Controllers\Admin\MonthlyGoalController;

// ════════════════════════════════════════════════════════════════
// Demo Access Control (AT-230) — the gate on demo1.corexos.co.za.
//
// UNAUTHENTICATED by necessity: this IS the front door. A prospect arrives with
// nothing but an emailed email+code, so these routes sit outside auth — they are
// also on EnsureDemoGrant's exempt list, or the gate would redirect to itself
// forever.
//
// On PRIMARY these 404 (DemoGateController::assertDemo). A sign-in gate for a
// demo that does not live here would be a dead end, and a surface to probe.
//
// Spec: .ai/specs/demo-access-control.md §6.2, §6.3, §6.4
// ════════════════════════════════════════════════════════════════
Route::get('/demo/gate',         [\App\Http\Controllers\Demo\DemoGateController::class, 'show'])->name('demo.gate');
Route::post('/demo/gate',        [\App\Http\Controllers\Demo\DemoGateController::class, 'verify'])->name('demo.gate.verify');
Route::post('/demo/gate/logout', [\App\Http\Controllers\Demo\DemoGateController::class, 'logout'])->name('demo.gate.logout');
Route::get('/demo/tnc',          [\App\Http\Controllers\Demo\DemoGateController::class, 'tnc'])->name('demo.tnc');
Route::post('/demo/tnc',         [\App\Http\Controllers\Demo\DemoGateController::class, 'acceptTnc'])->name('demo.tnc.accept');

// Page-view beacon. Always 204 — never blocks, never errors (fails OPEN, §6.4).
Route::post('/demo/telemetry',   [\App\Http\Controllers\Demo\DemoTelemetryController::class, 'store'])->name('demo.telemetry');

// ── Seller Live Link (public, no auth) ──
Route::get('/property/live/demo', [\App\Http\Controllers\SellerLinkController::class, 'demo'])->name('seller-link.demo');
Route::get('/property/live/{token}', [\App\Http\Controllers\SellerLinkController::class, 'show'])->name('seller-link.show');

// ── Buyer Portal (public, no auth) ──
Route::get('/buyer/portal/demo', [\App\Http\Controllers\BuyerPortalController::class, 'demo'])->name('buyer-portal.demo');
Route::get('/buyer/portal/{token}', [\App\Http\Controllers\BuyerPortalController::class, 'show'])->name('buyer-portal.show');
Route::post('/buyer/portal/{token}/respond', [\App\Http\Controllers\BuyerPortalController::class, 'respond'])->name('buyer-portal.respond');

// ── Seller-Outreach Public Landing (no auth) ──
// Spec: .ai/specs/seller-outreach-spec.md S8, 6.4, 6.5.
Route::get('/m/{shortcode}', [\App\Http\Controllers\SellerOutreach\PublicLandingController::class, 'show'])
    ->where('shortcode', '[A-Za-z0-9]{6}')
    ->name('seller-outreach.public.landing');

// ── Seller-Outreach self-service opt-out (no auth) — AT-49 ──
// 48-char unguessable token per send. GET is preview-safe (NO write); only the
// POST records the opt-out. Throttled to blunt token-probing / abuse.
Route::get('/outreach/opt-out/{token}', [\App\Http\Controllers\SellerOutreach\PublicOptOutController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware('throttle:30,1')
    ->name('seller-outreach.public.opt-out.show');
Route::post('/outreach/opt-out/{token}', [\App\Http\Controllers\SellerOutreach\PublicOptOutController::class, 'confirm'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware('throttle:10,1')
    ->name('seller-outreach.public.opt-out.confirm');

// ── Seller-Outreach self-service opt-IN / re-consent (no auth) — AT-49 ──
// Reuses the SAME per-send token as the opt-out link. GET is preview-safe (NO
// write); only the POST re-grants consent through MarketingConsentService.
Route::get('/outreach/opt-in/{token}', [\App\Http\Controllers\SellerOutreach\PublicOptInController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware('throttle:30,1')
    ->name('seller-outreach.public.opt-in.show');
Route::post('/outreach/opt-in/{token}', [\App\Http\Controllers\SellerOutreach\PublicOptInController::class, 'confirm'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware('throttle:10,1')
    ->name('seller-outreach.public.opt-in.confirm');

// ── Agent business-card image (no auth) — AT-83 ──
// Composite JPEG (agent photo + HFC logo + name/title/FFC) used as the WhatsApp
// link-preview og:image on the preference page. Public by design — WhatsApp's
// crawler fetches it sessionless and it shows only public business-card facts.
// Generate-on-miss, then served from the public-disk cache.
//
// Cookie-FREE: the session/cookie middleware is stripped because Facebook/WhatsApp's
// crawler will NOT use an og:image whose response carries a Set-Cookie header
// (that is why the previous through-the-web-group PNG previewed text-only).
Route::get('/outreach/agent-card/{user}.jpg', [\App\Http\Controllers\SellerOutreach\AgentCardController::class, 'show'])
    ->where('user', '[0-9]+')
    ->middleware('throttle:60,1')
    ->withoutMiddleware([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    ])
    ->name('seller-outreach.public.agent-card');

// ── Generic marketing unsubscribe page (no auth) — AT-49 ──
// The agency-id is the only path segment (the email-signature footer embeds it).
// The recipient enters their email or phone; MarketingConsentService resolves a
// contact if one exists and ALWAYS records a suppression row, so a re-import of
// that identifier stays blocked. GET is preview-safe; POST acts. Throttled.
Route::get('/unsubscribe/{agency}', [\App\Http\Controllers\SellerOutreach\UnsubscribeController::class, 'show'])
    ->where('agency', '[0-9]+')
    ->middleware('throttle:30,1')
    ->name('seller-outreach.public.unsubscribe.show');
Route::post('/unsubscribe/{agency}', [\App\Http\Controllers\SellerOutreach\UnsubscribeController::class, 'submit'])
    ->where('agency', '[0-9]+')
    ->middleware('throttle:10,1')
    ->name('seller-outreach.public.unsubscribe.submit');

// ── Presentations V2 Phase 4 — public snapshot share links (no auth).
//    Token is the credential; controller checks revoked_at + expires_at.
//    Track endpoint is rate-limited 60req/min/IP to stop beacon abuse.
Route::get('/p/{token}', [\App\Http\Controllers\Presentation\PublicPresentationController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{40,64}')
    ->middleware('throttle:60,1')
    ->name('presentation.public.show');
Route::post('/p/{token}/track', [\App\Http\Controllers\Presentation\PublicPresentationController::class, 'track'])
    ->where('token', '[A-Za-z0-9]{40,64}')
    ->middleware('throttle:60,1')
    ->name('presentation.public.track');
// Phase 5 — teaser lead capture (POST). Rate-limited 5/min/IP.
Route::post('/p/{token}/capture-lead', [\App\Http\Controllers\Presentation\PublicPresentationController::class, 'captureLead'])
    ->where('token', '[A-Za-z0-9]{40,64}')
    ->middleware('throttle:5,1')
    ->name('presentation.public.capture-lead');
Route::get('/p/{token}/refresh', [\App\Http\Controllers\Presentation\PublicPresentationController::class, 'refreshForm'])
    ->where('token', '[A-Za-z0-9]{40,64}')
    ->middleware('throttle:60,1')
    ->name('presentation.public.refresh-form');
// Build 5 — one-click "request revised presentation" CTA on the public view.
// Delegates to the same RefreshRequestService that backs the longer form.
Route::post('/p/{token}/request-revision', [\App\Http\Controllers\Presentation\PublicPresentationController::class, 'requestRevision'])
    ->middleware('throttle:6,1')
    ->name('presentation.public.request-revision');
Route::post('/p/{token}/refresh', [\App\Http\Controllers\Presentation\PublicPresentationController::class, 'refreshSubmit'])
    ->where('token', '[A-Za-z0-9]{40,64}')
    ->middleware('throttle:5,1')
    ->name('presentation.public.refresh-submit');

// Phase 9c-3 rebuild — public privacy policy by token. Token unique
// across agencies + branches; controller picks the right one.
Route::get('/legal/privacy/{token}', [\App\Http\Controllers\Public\PrivacyPolicyController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{40,64}')
    ->middleware('throttle:60,1')
    ->name('public.privacy-policy');

// CoreX OS platform legal pages (Meta/Facebook App Review). Public, no auth —
// Meta's crawler fetches these without logging in. See LegalController.
Route::get('/privacy', [\App\Http\Controllers\Public\LegalController::class, 'privacy'])
    ->middleware('throttle:60,1')
    ->name('public.platform-privacy');
Route::get('/data-deletion', [\App\Http\Controllers\Public\LegalController::class, 'dataDeletion'])
    ->middleware('throttle:60,1')
    ->name('public.data-deletion');

Route::post('/m/{shortcode}/callback', [\App\Http\Controllers\SellerOutreach\PublicLandingController::class, 'callback'])
    ->where('shortcode', '[A-Za-z0-9]{6}')
    ->middleware('throttle:10,60')
    ->name('seller-outreach.public.callback');

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

// Public shared pages (no auth required)
Route::get('/shared/match/{token}', [\App\Http\Controllers\SharedMatchController::class, 'show'])->name('shared.match');
Route::get('/shared/match/{token}/view/{property}', [\App\Http\Controllers\SharedMatchController::class, 'recordView'])->name('shared.match.view');
Route::post('/shared/match/{token}/feedback/{property}', [\App\Http\Controllers\SharedMatchController::class, 'feedback'])->name('shared.match.feedback');

// Public agency property listings (no auth) — /{slug}/properties
Route::get('/{agencySlug}/properties', [\App\Http\Controllers\PublicAgencyPropertiesController::class, 'index'])
    ->where('agencySlug', '^(?!admin|shared|dashboard|login|register|corex|api|storage|livewire|_ignition|broadcasting|horizon|sanctum|agent|onboarding|compliance|docuperfect|presentation|presentations|settings|profile|nexus|tv|ellie|xgrid|invite|up)[a-z0-9-]+$')
    ->name('public.agency.properties.index');
Route::get('/{agencySlug}/properties/{property}', [\App\Http\Controllers\PublicAgencyPropertiesController::class, 'show'])
    ->where('agencySlug', '^(?!admin|shared|dashboard|login|register|corex|api|storage|livewire|_ignition|broadcasting|horizon|sanctum|agent|onboarding|compliance|docuperfect|presentation|presentations|settings|profile|nexus|tv|ellie|xgrid|invite|up)[a-z0-9-]+$')
    ->name('public.agency.properties.show');

// Public branding lookup by agency slug (no auth — used by mobile app login screen)
Route::get('/api/v1/branding/{slug}', [\App\Http\Controllers\Api\V1\BrandingController::class, 'showBySlug'])
    ->name('api.v1.branding.show-by-slug');

Route::get('/dashboard', function () {
    return redirect()->route('corex.dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {

    // Phase 6 — agent-side WhatsApp click-through tracker → records the
    // click + 302s to the wa.me URL. Agency-scoped inside the controller.
    Route::get('/corex/deliveries/{delivery}/whatsapp-redirect', [\App\Http\Controllers\Presentation\PresentationDeliveryController::class, 'whatsappRedirect'])
        ->name('corex.deliveries.whatsapp-redirect');

    // Phase 8 — outcomes dashboard.
    Route::get('/corex/presentations/outcomes', [\App\Http\Controllers\Presentation\PresentationOutcomesDashboardController::class, 'index'])
        ->middleware('permission:access_presentations')
        ->name('corex.presentations.outcomes.index');
    // Phase 9a — full-funnel analytics dashboard.
    Route::get('/corex/presentations/analytics', [\App\Http\Controllers\Presentation\PresentationAnalyticsController::class, 'index'])
        ->middleware('permission:access_presentations')
        ->name('corex.presentations.analytics.index');

    // Phase 9d — RCR submission flow.
    Route::bind('rcrSubmission', fn ($id) => \App\Models\Compliance\Rcr\RcrSubmission::findOrFail($id));
    Route::bind('rcrAnswer',     fn ($id) => \App\Models\Compliance\Rcr\RcrAnswer::findOrFail($id));
    Route::bind('rcrQuestionnaire', fn ($id) => \App\Models\Compliance\Rcr\RcrQuestionnaire::findOrFail($id));

    Route::prefix('corex/compliance/rcr')
        ->name('corex.compliance.rcr.')
        ->group(function () {
            Route::get('/',                                          [\App\Http\Controllers\Compliance\Rcr\RcrSubmissionController::class, 'index'])->name('index');
            Route::post('/',                                         [\App\Http\Controllers\Compliance\Rcr\RcrSubmissionController::class, 'store'])->name('store');
            Route::get('/{rcrSubmission}',                           [\App\Http\Controllers\Compliance\Rcr\RcrSubmissionController::class, 'show'])->name('show');
            Route::patch('/{rcrSubmission}/answers/{rcrAnswer}',     [\App\Http\Controllers\Compliance\Rcr\RcrSubmissionController::class, 'saveAnswer'])->name('answers.save');
            Route::post('/{rcrSubmission}/answers/{rcrAnswer}/evidence', [\App\Http\Controllers\Compliance\Rcr\RcrSubmissionController::class, 'attachEvidence'])->name('answers.evidence');
            Route::post('/{rcrSubmission}/auto-populate-all',        [\App\Http\Controllers\Compliance\Rcr\RcrSubmissionController::class, 'autoPopulateAll'])->name('auto-populate');
            Route::post('/{rcrSubmission}/send-for-review',          [\App\Http\Controllers\Compliance\Rcr\RcrSubmissionController::class, 'sendForReview'])->name('send-for-review');
            Route::post('/{rcrSubmission}/submit',                   [\App\Http\Controllers\Compliance\Rcr\RcrSubmissionController::class, 'submit'])->name('submit');
            Route::get('/{rcrSubmission}/export/{format}',           [\App\Http\Controllers\Compliance\Rcr\RcrSubmissionController::class, 'export'])->name('export');
            // Phase 9d.1 — per-question deep view + clipboard endpoints.
            Route::get('/{rcrSubmission}/question/{questionCode}',   [\App\Http\Controllers\Compliance\Rcr\RcrSubmissionController::class, 'showQuestion'])
                ->where('questionCode', '[A-Za-z0-9._]+')
                ->name('question.show');
            Route::post('/answer/copied',                            [\App\Http\Controllers\Compliance\Rcr\RcrSubmissionController::class, 'logAnswerCopied'])->name('answer.copied');
            Route::post('/answer/transposed',                        [\App\Http\Controllers\Compliance\Rcr\RcrSubmissionController::class, 'markAnswerTransposed'])->name('answer.transposed');
        });

    Route::prefix('corex/admin/rcr/questionnaires')
        ->name('corex.admin.rcr.questionnaires.')
        ->group(function () {
            Route::get('/',                          [\App\Http\Controllers\Compliance\Rcr\RcrQuestionnaireAdminController::class, 'index'])->name('index');
            Route::get('/{rcrQuestionnaire}',        [\App\Http\Controllers\Compliance\Rcr\RcrQuestionnaireAdminController::class, 'show'])->name('show');
            Route::post('/{rcrQuestionnaire}/import-csv', [\App\Http\Controllers\Compliance\Rcr\RcrQuestionnaireAdminController::class, 'importCsv'])->name('import-csv');
        });

    // Phase 3i — admin deal-link-review queue.
    Route::bind('reviewItem', fn ($id) => \App\Models\DealLinkReviewQueue::findOrFail($id));
    // Phase 3j — SG document binding.
    Route::bind('sgDoc', fn ($id) => \App\Models\PropertySgDocument::findOrFail($id));
    Route::prefix('corex/admin/deal-link-review')
        ->name('corex.admin.deal-link-review.')
        ->group(function () {
            Route::get('/',                        [\App\Http\Controllers\Admin\DealLinkReviewController::class, 'index'])->name('index');
            Route::get('/{reviewItem}',            [\App\Http\Controllers\Admin\DealLinkReviewController::class, 'show'])->name('show');
            Route::post('/{reviewItem}/link',      [\App\Http\Controllers\Admin\DealLinkReviewController::class, 'link'])->name('link');
            Route::post('/{reviewItem}/skip',      [\App\Http\Controllers\Admin\DealLinkReviewController::class, 'skip'])->name('skip');
            Route::post('/{reviewItem}/unlink',    [\App\Http\Controllers\Admin\DealLinkReviewController::class, 'unlink'])->name('unlink');
        });

    // Phase 7 — refresh request inbox + per-row actions.
    Route::prefix('corex/presentations/refresh-requests')
        ->name('corex.presentations.refresh-requests.')
        ->group(function () {
            Route::get('/', [\App\Http\Controllers\Presentation\RefreshRequestController::class, 'index'])
                ->middleware('permission:access_presentations')
                ->name('index');
            Route::post('/{refreshRequest}/acknowledge', [\App\Http\Controllers\Presentation\RefreshRequestController::class, 'acknowledge'])
                ->middleware('permission:access_presentations')
                ->name('acknowledge');
            Route::post('/{refreshRequest}/resolve', [\App\Http\Controllers\Presentation\RefreshRequestController::class, 'resolve'])
                ->middleware('permission:access_presentations')
                ->name('resolve');
            Route::post('/{refreshRequest}/decline', [\App\Http\Controllers\Presentation\RefreshRequestController::class, 'decline'])
                ->middleware('permission:access_presentations')
                ->name('decline');
        });

    // P24 location tree read-API (called from Blade pages over fetch with
    // session cookies — must live in web.php so the `web` middleware group
    // applies, not in routes/api.php where session isn't set up).
    Route::get('/api/v1/p24/provinces', [\App\Http\Controllers\Api\V1\P24LocationController::class, 'provinces'])->name('api.v1.p24.provinces');
    Route::get('/api/v1/p24/cities',    [\App\Http\Controllers\Api\V1\P24LocationController::class, 'cities'])->name('api.v1.p24.cities');
    Route::get('/api/v1/p24/suburbs',   [\App\Http\Controllers\Api\V1\P24LocationController::class, 'suburbs'])->name('api.v1.p24.suburbs');

    // AT-168 Part C — conversation-thread paging + in-thread search (JSON).
    // Under /api/v1 (URI starts with api/) so they appear in the Admin→API
    // catalogue (non-negotiable #7). Called from the thread Blade over fetch with
    // session cookies — same reason as the P24 tree above they live in web.php.
    // Gated by the same archive permission as the thread view.
    Route::middleware(['auth', 'verified', 'permission:access_communication_archive', 'agency.required'])
        ->prefix('api/v1/communications/threads')->name('api.v1.communications.threads.')->group(function () {
            Route::get('/{threadKey}/older', [\App\Http\Controllers\Compliance\CommunicationArchiveController::class, 'threadOlder'])->name('older')->where('threadKey', '.*');
            Route::get('/{threadKey}/search', [\App\Http\Controllers\Compliance\CommunicationArchiveController::class, 'threadSearch'])->name('search')->where('threadKey', '.*');
        });

    // ── Admin: API Catalog (auto-generated from route table) ──
    Route::get('/admin/api', [\App\Http\Controllers\Admin\ApiCatalogController::class, 'index'])
        ->middleware('permission:manage_users')
        ->name('admin.api.catalog');

    // ── Admin: Soft Deletes Register (restore archived records) ──
    // Spec: .ai/specs/soft-deletes-admin.md
    // Soft Deletes register is agency-scoped — an owner with no active agency
    // context is redirected to the agency picker (agency.required) so they see
    // a specific agency's archived records, not a cross-agency mix.
    Route::get('/admin/soft-deletes', [\App\Http\Controllers\Admin\SoftDeleteController::class, 'index'])
        ->middleware(['permission:access_soft_deletes', 'agency.required'])
        ->name('admin.soft-deletes.index');
    Route::get('/admin/soft-deletes/{key}', [\App\Http\Controllers\Admin\SoftDeleteController::class, 'show'])
        ->middleware(['permission:access_soft_deletes', 'agency.required'])
        ->where('key', '[A-Za-z0-9.]+')
        ->name('admin.soft-deletes.show');
    Route::post('/admin/soft-deletes/{key}/{id}/restore', [\App\Http\Controllers\Admin\SoftDeleteController::class, 'restore'])
        ->middleware(['permission:access_soft_deletes', 'agency.required'])
        ->where('key', '[A-Za-z0-9.]+')
        ->where('id', '[0-9]+')
        ->name('admin.soft-deletes.restore');

    // ── Admin: Marketing Suppression register (AT-49) ──
    // Identifier-level "suppressed everywhere" list; lifting a row is an opt-in.
    Route::get('/admin/marketing-suppressions', [\App\Http\Controllers\Admin\MarketingSuppressionController::class, 'index'])
        ->middleware('permission:marketing_suppressions.view')
        ->name('admin.marketing-suppressions.index');
    Route::post('/admin/marketing-suppressions/{suppression}/lift', [\App\Http\Controllers\Admin\MarketingSuppressionController::class, 'lift'])
        ->middleware('permission:marketing_suppressions.manage')
        ->where('suppression', '[0-9]+')
        ->name('admin.marketing-suppressions.lift');

    // ── Admin: Misfiled Documents register (AT-167) ──
    // Contact-only splitter docs with no contact assigned; Refile routes them to
    // the correct person and removes the wrong property anchor (no hard delete).
    Route::get('/admin/misfiled-documents', [\App\Http\Controllers\Admin\MisfiledDocumentsController::class, 'index'])
        ->middleware('permission:access_misfiled_documents')
        ->name('admin.misfiled-documents.index');
    Route::post('/admin/misfiled-documents/{document}/refile', [\App\Http\Controllers\Admin\MisfiledDocumentsController::class, 'refile'])
        ->middleware('permission:misfiled_documents.refile')
        ->where('document', '[0-9]+')
        ->name('admin.misfiled-documents.refile');

    // ── Admin: AI usage / cost dashboard (MIC Phase B2) ──
    Route::get('/admin/ai-usage', [\App\Http\Controllers\Admin\AiUsageController::class, 'index'])
        ->middleware('permission:mic.view_ai_costs')
        ->name('admin.ai-usage.index');
    Route::get('/admin/ai-usage/agencies/{agency}', [\App\Http\Controllers\Admin\AiUsageController::class, 'agency'])
        ->middleware('permission:mic.view_ai_costs')
        ->where('agency', '[0-9]+')
        ->name('admin.ai-usage.agency');
    Route::post('/admin/ai-usage/agencies/{agency}/budget', [\App\Http\Controllers\Admin\AiUsageController::class, 'updateBudget'])
        ->middleware('permission:mic.view_ai_costs')
        ->name('admin.ai-usage.budget.update');

// ── CoreX Global API v1 (session-authenticated, browser-visible XHR) ──
    Route::prefix('api/v1')->name('api.v1.')->group(function () {
        Route::get('/logged-user', [\App\Http\Controllers\Api\V1\MeController::class, 'show'])->name('logged-user');

        // AT-220 — session-armour token refresh. GET (no CSRF needed) returns the
        // current CSRF token so long-lived authoring pages (document/template
        // editor, e-sign wizard) can refresh a stale token in-place AND slide the
        // session, instead of dying with a 419. Auth-gated → a dead session yields
        // 401 JSON, which the client turns into the plain "connection lost" banner.
        Route::get('/csrf-token', function () {
            return response()->json(['token' => csrf_token()]);
        })->name('csrf-token');

        // AT-178 Event-reminder popup toast — polled from EVERY page by the browser
        // session (components/reminder-toast.blade.php). MUST live in this
        // session-authenticated group, NOT under api.php's auth:sanctum (token-only,
        // stateful session disabled) which 401'd every poll. Self-scoped per user.
        Route::get('/command-center/reminders/due',           [\App\Http\Controllers\Api\CommandCenter\ReminderController::class, 'due'])->name('command-center.reminders.due');
        Route::post('/command-center/reminders/{log}/read',   [\App\Http\Controllers\Api\CommandCenter\ReminderController::class, 'read'])->whereNumber('log')->name('command-center.reminders.read');
        Route::post('/command-center/reminders/{log}/snooze', [\App\Http\Controllers\Api\CommandCenter\ReminderController::class, 'snooze'])->whereNumber('log')->name('command-center.reminders.snooze');

        Route::get('/properties',      [\App\Http\Controllers\Api\V1\PropertiesController::class, 'index'])->name('properties.index');
        Route::get('/properties/{property}', [\App\Http\Controllers\Api\V1\PropertiesController::class, 'show'])->name('properties.show');

        // Shared syndication panel for one property — the SAME surface the
        // property page renders inline. Fetched by the Properties index so a
        // card/row opens the real control (toggle · refresh · deactivate ·
        // live preview) instead of a second, divergent copy of it.
        //
        // Registered here, not in routes/api.php: bootstrap/app.php strips
        // Sanctum's EnsureFrontendRequestsAreStateful from the `api` group, so
        // /api/v1/* there is bearer-token only (mobile) and a cookie-authed
        // browser fetch returns "Unauthenticated." The URI still starts with
        // api/, so the endpoint appears in the Admin → API catalogue (NN #7).
        Route::get('/properties/{property}/syndication-panel', \App\Http\Controllers\Api\PropertySyndicationPanelController::class)
            ->middleware('permission:access_properties')
            ->name('properties.syndication-panel');

        Route::get('/contacts',        [\App\Http\Controllers\Api\V1\ContactsController::class, 'index'])->name('contacts.index');
        Route::get('/contacts/{contact}', [\App\Http\Controllers\Api\V1\ContactsController::class, 'show'])->name('contacts.show');

        Route::get('/deals',           [\App\Http\Controllers\Api\V1\DealsController::class, 'index'])->name('deals.index');
        Route::get('/deals/{deal}',    [\App\Http\Controllers\Api\V1\DealsController::class, 'show'])->name('deals.show');

        Route::get('/branding',        [\App\Http\Controllers\Api\V1\BrandingController::class, 'show'])->name('branding.show');

        // ── Agency Access Authorization (cross-agency consent flow) ──
        // See .ai/specs/agency-access-authorization-spec.md
        Route::prefix('agency-access')->name('agency-access.')->group(function () {
            Route::get('/inspect/{agency}',        [\App\Http\Controllers\Api\AgencyAccessRequestController::class, 'inspect'])->name('inspect');
            Route::post('/request',                [\App\Http\Controllers\Api\AgencyAccessRequestController::class, 'store'])->name('store');
            Route::get('/inbox',                   [\App\Http\Controllers\Api\AgencyAccessRequestController::class, 'inbox'])->name('inbox');
            Route::get('/{request}/status',        [\App\Http\Controllers\Api\AgencyAccessRequestController::class, 'status'])->name('status');
            Route::post('/{request}/cancel',       [\App\Http\Controllers\Api\AgencyAccessRequestController::class, 'cancel'])->name('cancel');
            Route::post('/{request}/authorize',    [\App\Http\Controllers\Api\AgencyAccessRequestController::class, 'authorize'])->name('authorize');
            Route::post('/{request}/confirm-switch',[\App\Http\Controllers\Api\AgencyAccessRequestController::class, 'confirmSwitch'])->name('confirm-switch');
        });

        // ── AT-118 Communications Access Gate — Flow A request/authorise ──
        // See .ai/specs/at118-communications-access-gate.md §3.3
        Route::prefix('comms-access')->name('comms-access.')->group(function () {
            Route::post('/request',                       [\App\Http\Controllers\Communications\CommsAccessRequestController::class, 'store'])->name('store');
            Route::post('/thread-settings',               [\App\Http\Controllers\Communications\CommsAccessRequestController::class, 'threadSettings'])->name('thread-settings');
            Route::get('/{commsAccessRequest}/status',    [\App\Http\Controllers\Communications\CommsAccessRequestController::class, 'status'])->name('status');
            Route::post('/{commsAccessRequest}/authorize',[\App\Http\Controllers\Communications\CommsAccessRequestController::class, 'authorize'])->name('authorize');
            Route::post('/{commsAccessRequest}/revoke',   [\App\Http\Controllers\Communications\CommsAccessRequestController::class, 'revoke'])->name('revoke');
        });

        // ── Command Center: Task Notes (threaded) + Checklist ──
        Route::prefix('command-center/tasks/{task}')->name('command-center.tasks.')->group(function () {
            Route::get('/notes',           [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'index'])->name('notes.index');
            Route::post('/notes',          [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'store'])->name('notes.store');
            Route::put('/notes/{note}',    [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'update'])->name('notes.update');
            Route::delete('/notes/{note}', [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'destroy'])->name('notes.destroy');

            Route::get('/checklist',                [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'checklistIndex'])->name('checklist.index');
            Route::post('/checklist',               [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'checklistStore'])->name('checklist.store');
            Route::patch('/checklist/{itemId}',     [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'checklistUpdate'])->name('checklist.update');
            Route::delete('/checklist/{itemId}',    [\App\Http\Controllers\Api\CommandTaskNotesController::class, 'checklistDestroy'])->name('checklist.destroy');
        });

        // ── Interactive help tours — per-user progress (self-scoped) ──
        // Engine: App\Support\Tours\TourRegistry + layouts/partials/tour-engine.blade.php
        Route::prefix('tours')->name('tours.')->group(function () {
            Route::post('/{tourKey}/seen',    [\App\Http\Controllers\TourProgressController::class, 'seen'])->name('seen');
            Route::post('/{tourKey}/dismiss', [\App\Http\Controllers\TourProgressController::class, 'dismiss'])->name('dismiss');
        });
    });

    Route::get('/evaluation', function () {
        return view('evaluation.index');
    })->middleware('permission:access_evaluation')->name('evaluation.index');

    // Profile → redirect to My Portal (consolidated)
    Route::get('/profile', fn () => redirect('/my-portal#profile', 301))->name('profile.edit');
    Route::patch('/profile', [\App\Http\Controllers\Agent\AgentPortalController::class, 'updateProfile'])->name('profile.update');
    Route::put('/profile/theme', [ProfileController::class, 'updateTheme'])->name('profile.theme');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/corex/extension/download', [ProfileController::class, 'downloadExtension'])->name('corex.extension.download');

    // Ellie (AI Assistant)
    Route::get('/ellie', [\App\Http\Controllers\EllieController::class, 'index'])
        ->middleware('permission:access_ellie')->name('ellie.index');

    Route::post('/ellie/send', [\App\Http\Controllers\EllieController::class, 'send'])
        ->middleware('permission:access_ellie')->name('ellie.send');

    // ELLIE_ROUTES_2026
    Route::post('/ellie/rename', [\App\Http\Controllers\EllieController::class, 'rename'])
        ->middleware('permission:access_ellie')->name('ellie.rename');

    Route::post('/ellie/archive', [\App\Http\Controllers\EllieController::class, 'archive'])
        ->middleware('permission:access_ellie')->name('ellie.archive');

    Route::post('/ellie/unarchive', [\App\Http\Controllers\EllieController::class, 'unarchive'])
        ->middleware('permission:access_ellie')->name('ellie.unarchive');

    // Calculators
    Route::get('/calculators', [\App\Http\Controllers\CalculatorController::class, 'index'])->middleware('permission:access_calculators')->name('calculators.index');
    Route::post('/calculators/commission', [\App\Http\Controllers\CalculatorController::class, 'calculateCommission'])->middleware('permission:access_calculators')->name('calculators.commission');
    Route::post('/calculators/bond', [\App\Http\Controllers\CalculatorController::class, 'calculateBond'])->middleware('permission:access_calculators')->name('calculators.bond');
    Route::post('/calculators/transfer-costs', [\App\Http\Controllers\CalculatorController::class, 'calculateTransferCosts'])->middleware('permission:access_calculators')->name('calculators.transferCosts');
    Route::post('/calculators/upload-fee-sheet', [\App\Http\Controllers\CalculatorController::class, 'uploadFeeSheet'])->middleware('permission:access_calculators')->name('calculators.uploadFeeSheet');
    Route::post('/calculators/bond-overpayment', [\App\Http\Controllers\CalculatorController::class, 'calculateBondOverpayment'])->middleware('permission:access_calculators')->name('calculators.bondOverpayment');

    Route::get('/worksheet', [WorksheetController::class, 'index'])->middleware('permission:view_worksheet')->name('worksheet.index');
    Route::post('/worksheet', [WorksheetController::class, 'store'])->middleware('permission:view_worksheet')->name('worksheet.store');

    Route::get('/company-summary', [CompanySummaryController::class, 'index'])->middleware('permission:view_dashboard')->name('company.summary');

    Route::get('/admin/dashboard', [AdminDashboardController::class, 'index'])
        ->middleware('permission:export_reports')->name('admin.dashboard');

    Route::post('/admin/dashboard/expenses', [AdminDashboardController::class, 'saveExpenses'])
        ->middleware('permission:export_reports')->name('admin.expenses.save');

    Route::get('/admin/branch-assignments', [BranchAssignmentController::class, 'index'])
        ->middleware('permission:access_branch_assignments')->name('admin.branch-assignments');

    Route::post('/admin/branch-assignments', [BranchAssignmentController::class, 'update'])
        ->middleware('permission:access_branch_assignments')->name('admin.branch-assignments.update');

    Route::post('/admin/branches', [BranchAssignmentController::class, 'createBranch'])
        ->middleware('permission:access_branch_assignments')
        ->name('admin.branches.store');

    Route::post('/admin/branches/{branch}/delete', [BranchAssignmentController::class, 'deleteBranch'])
        ->middleware('permission:access_branch_assignments')
        ->name('admin.branches.delete');

    Route::post('/admin/branches/{branch}/restore', [BranchAssignmentController::class, 'restoreBranch'])
        ->middleware('permission:access_branch_assignments')
        ->name('admin.branches.restore')->withTrashed();

    Route::post('/admin/branch-settings/{branch}', [BranchAssignmentController::class, 'updateBranchSettings'])
        ->middleware('permission:access_branch_assignments')
        ->name('admin.branch-settings.update');


    /*
     | AT-267 — Assistants (admin surface).
     |
     | An admin creates the assistant and hands them to an agent. From there the AGENT owns
     | what they may do (see agent.assistants.* below) and the admin owns whether they exist.
     |
     | Full CRUD is the floor (BUILD_STANDARD §1): list, view, create, reassign, revoke,
     | restore. Revoke is a soft delete — no hard deletes, ever.
     */
    Route::prefix('admin/assistants')->name('admin.assistants.')->middleware('agency.required')->group(function () {
        Route::get('/',                             [\App\Http\Controllers\Admin\AssistantController::class, 'index'])->name('index');
        Route::get('/create',                       [\App\Http\Controllers\Admin\AssistantController::class, 'create'])->name('create');
        Route::post('/',                            [\App\Http\Controllers\Admin\AssistantController::class, 'store'])->name('store');
        Route::get('/{assignment}',                 [\App\Http\Controllers\Admin\AssistantController::class, 'show'])->name('show');
        Route::post('/{assignment}/reassign',       [\App\Http\Controllers\Admin\AssistantController::class, 'reassign'])->name('reassign');
        Route::post('/{assignment}/revoke',         [\App\Http\Controllers\Admin\AssistantController::class, 'revoke'])->name('revoke');
        Route::post('/{assignment}/restore',        [\App\Http\Controllers\Admin\AssistantController::class, 'restore'])->name('restore')->withTrashed();
        Route::post('/{assignment}/resend-invite',  [\App\Http\Controllers\Admin\AssistantController::class, 'resendInvite'])->name('resend-invite');
    });

    /*
     | AT-267 — the agent's own Assistants page.
     |
     | Gated by OWNERSHIP inside the controller (agent_user_id === auth id), not by a permission
     | key: the right to configure your own assistant derives from being their agent, the same
     | way editing your own profile derives from being that user. A grantable key would allow an
     | agent to have an assistant they cannot configure.
     */
    Route::prefix('my-portal/assistants')->name('agent.assistants.')->middleware('agency.required')->group(function () {
        Route::get('/',                     [\App\Http\Controllers\Agent\AssistantMatrixController::class, 'index'])->name('index');
        Route::get('/{assignment}/matrix',  [\App\Http\Controllers\Agent\AssistantMatrixController::class, 'edit'])->name('matrix');
        Route::post('/{assignment}/matrix', [\App\Http\Controllers\Agent\AssistantMatrixController::class, 'save'])->name('matrix.save');
    });

    Route::get('/admin/users', [App\Http\Controllers\Admin\UserManagementController::class, 'index'])
        ->middleware('permission:manage_users')->name('admin.users');
    Route::get('/admin/users/create', [App\Http\Controllers\Admin\UserManagementController::class, 'create'])
        ->middleware('permission:manage_users')->name('admin.users.create');
    Route::post('/admin/users', [App\Http\Controllers\Admin\UserManagementController::class, 'store'])
        ->middleware('permission:manage_users')->name('admin.users.store');
    Route::get('/admin/users/{user}/edit', [App\Http\Controllers\Admin\UserManagementController::class, 'edit'])
        ->middleware('permission:manage_users')->name('admin.users.edit');
    Route::put('/admin/users/{user}', [App\Http\Controllers\Admin\UserManagementController::class, 'update'])
        ->middleware('permission:manage_users')->name('admin.users.update');

    Route::post('/admin/users/{user}/toggle', [App\Http\Controllers\Admin\UserManagementController::class, 'toggle'])
        ->middleware('permission:manage_users')->name('admin.users.toggle');
    // Agency Public API — quick website-visibility toggle per agent. Spec §2 (layer 3).
    Route::post('/admin/users/{user}/toggle-website', [App\Http\Controllers\Admin\UserManagementController::class, 'toggleWebsite'])
        ->middleware('permission:manage_users')->name('admin.users.toggle-website');
    // Property24 — quick visibility toggle per agent (exclude_from_p24).
    Route::post('/admin/users/{user}/toggle-p24', [App\Http\Controllers\Admin\UserManagementController::class, 'toggleP24'])
        ->middleware('permission:manage_users')->name('admin.users.toggle-p24');

    Route::post('/admin/users/{user}/delete', [App\Http\Controllers\Admin\UserManagementController::class, 'delete'])
        ->middleware('permission:manage_users')->name('admin.users.delete');

    Route::get('/api/v1/admin/users/{user}/delete-preview', [App\Http\Controllers\Admin\UserManagementController::class, 'deletePreview'])
        ->middleware('permission:manage_users')->name('api.v1.admin.users.delete-preview');

    Route::post('/admin/users/{user}/defaults', [App\Http\Controllers\Admin\UserManagementController::class, 'updateDefaults'])
        ->middleware('permission:manage_users')->name('admin.users.defaults.update');
    Route::post('/admin/users/{user}/role', [App\Http\Controllers\Admin\UserManagementController::class, 'updateRole'])->middleware('permission:manage_users')->name('admin.users.role.update');
    Route::post('/admin/users/{user}/resend-invite', [App\Http\Controllers\Admin\UserManagementController::class, 'resendInvite'])->middleware('permission:manage_users')->name('admin.users.resend-invite');
    Route::post('/admin/users/{user}/sync-p24', [App\Http\Controllers\Admin\UserManagementController::class, 'syncP24'])->middleware('permission:manage_users')->name('admin.users.sync-p24');
    Route::post('/admin/users/{user}/remove-file', [App\Http\Controllers\Admin\UserManagementController::class, 'removeAgentFile'])->middleware('permission:manage_users')->name('admin.users.remove-file');
    // PP Agent ownership
    Route::post('/admin/users/{user}/pp/sync', [\App\Http\Controllers\PrivateProperty\AgentPpController::class, 'sync'])->middleware('permission:manage_users')->name('admin.users.pp.sync');
    Route::post('/admin/users/{user}/pp/update-id', [\App\Http\Controllers\PrivateProperty\AgentPpController::class, 'updateId'])->middleware('permission:manage_users')->name('admin.users.pp.update-id');
    Route::post('/admin/users/{user}/pp/update-external-ref', [\App\Http\Controllers\PrivateProperty\AgentPpController::class, 'updateExternalRef'])->middleware('permission:manage_users')->name('admin.users.pp.update-external-ref');
    Route::get('/admin/pp/agent-mapping', [\App\Http\Controllers\PrivateProperty\AgentPpController::class, 'agentMapping'])->middleware('permission:manage_users')->name('admin.pp.agent-mapping');
    Route::get('/admin/pp/agents', [\App\Http\Controllers\PrivateProperty\AgentPpController::class, 'index'])->middleware('permission:manage_users')->name('admin.pp.agents');
    Route::get('/admin/pp/mapping-email', [\App\Http\Controllers\PrivateProperty\AgentPpController::class, 'mappingEmail'])->middleware('permission:manage_users')->name('admin.pp.mapping-email');
    Route::post('/admin/pp/agents/deactivate', [\App\Http\Controllers\PrivateProperty\AgentPpController::class, 'deactivateByEncryptedId'])->middleware('permission:manage_users')->name('admin.pp.agents.deactivate');
    Route::post('/admin/pp/agents/purge-listing/{id}', [\App\Http\Controllers\PrivateProperty\AgentPpController::class, 'purgeListing'])->middleware('permission:manage_users')->name('admin.pp.agents.purge-listing');

    Route::get('/admin/listing-targets', [ListingTargetController::class, 'index'])
        ->middleware('permission:manage_targets')->name('admin.listing-targets');

    Route::post('/admin/listing-targets', [ListingTargetController::class, 'store'])
        ->middleware('permission:manage_targets')->name('admin.listing-targets.store');

    // Deals
    Route::get('/admin/deals', [DealController::class, 'index'])->name('admin.deals')->middleware('permission:create_deals');
    // Agent: My Deals (read-only, remarks via log)
    Route::get('/agent/deals', [DealRegisterController::class, 'index'])->name('agent.deals.index')->middleware('permission:view_deals');
    Route::get('/agent/deals/{deal}/log', [DealRegisterController::class, 'log'])->name('agent.deals.log')->middleware('permission:view_deals');
    Route::post('/agent/deals/{deal}/remark', [DealRegisterController::class, 'addRemark'])->name('agent.deals.remark')->middleware('permission:view_deals');


    Route::get('/admin/deals/create', [DealController::class, 'create'])->name('admin.deals.create')->middleware('permission:create_deals');

    Route::post('/admin/deals', [DealController::class, 'store'])->name('admin.deals.store')->middleware('permission:create_deals');

    Route::get('/admin/deals/{deal}/edit', [DealController::class, 'edit'])->name('admin.deals.edit')->middleware('permission:create_deals');
    Route::get('/admin/deals/{deal}/log', [DealController::class, 'log'])->name('admin.deals.log')->middleware('permission:create_deals');
    Route::post('/admin/deals/{deal}/remark', [DealController::class, 'addRemark'])->name('admin.deals.remark')->middleware('permission:create_deals');

    Route::post('/admin/deals/{deal}', [DealController::class, 'update'])->name('admin.deals.update')->middleware('permission:create_deals');
    Route::post('/admin/deals/{deal}/quick', [DealController::class, 'quickUpdate'])->name('admin.deals.quickUpdate')->middleware('permission:create_deals');

    // Deal Settlement (Per-deal Pay screen)
    Route::get('/admin/deals/{deal}/settle', [DealController::class, 'settle'])
        ->middleware('permission:settle_deals')->name('admin.deals.settle');

    Route::post('/admin/deals/{deal}/settle', [DealController::class, 'saveSettlement'])
        ->middleware('permission:settle_deals')->name('admin.deals.settle.save');

    // Deal Settlement Printing
    Route::get('/admin/deals/{deal}/settle/print', [DealController::class, 'printSettlement'])
        ->middleware('permission:settle_deals')->name('admin.deals.settle.print');

    Route::get('/admin/deals/{deal}/settle/print/{user}', [DealController::class, 'printAgentPayslip'])
        ->middleware('permission:settle_deals')->name('admin.deals.settle.print.agent');

    Route::post('/admin/view-as', [ViewAsController::class, 'update'])->name('admin.viewas.update');
    Route::post('/admin/view-as/reset', [ViewAsController::class, 'clear'])->name('admin.viewas.reset');

});

// ===== P24 IMPORTER (Admin) =====
// P24 Importer — owner-only. The `owner_only` middleware sits alongside
// `permission:access_importer` so even if the permission is mis-granted
// to an agency admin they still 403 here.
Route::prefix('admin/importer')->middleware(['auth', 'owner_only'])->name('admin.importer.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\ImporterController::class, 'index'])->name('index');

    // P24 Locations browser — view the cached Province → City → Suburb tree
    // and re-trigger the sync command from a button.
    Route::get('/p24-locations',           [\App\Http\Controllers\Admin\ImporterController::class, 'p24Locations'])->name('p24-locations');
    Route::post('/p24-locations/refresh',  [\App\Http\Controllers\Admin\ImporterController::class, 'refreshP24Locations'])->name('p24-locations.refresh');
    Route::get('/p24-locations/status',    [\App\Http\Controllers\Admin\ImporterController::class, 'p24LocationsStatus'])->name('p24-locations.status');

    // PP Locations browser — hidden background data; UI shows refresh + counts only.
    Route::get('/pp-locations',           [\App\Http\Controllers\Admin\ImporterController::class, 'ppLocations'])->name('pp-locations');
    Route::post('/pp-locations/refresh',  [\App\Http\Controllers\Admin\ImporterController::class, 'refreshPpLocations'])->name('pp-locations.refresh');
    Route::get('/pp-locations/status',    [\App\Http\Controllers\Admin\ImporterController::class, 'ppLocationsStatus'])->name('pp-locations.status');
    Route::post('/agents/upload', [\App\Http\Controllers\Admin\ImporterController::class, 'uploadAgents'])->name('agents.upload');
    Route::get('/runs/{run}/preview', [\App\Http\Controllers\Admin\ImporterController::class, 'preview'])->name('preview');
    Route::post('/runs/{run}/confirm', [\App\Http\Controllers\Admin\ImporterController::class, 'confirmAgents'])->name('confirm');
    Route::post('/runs/{run}/cancel', [\App\Http\Controllers\Admin\ImporterController::class, 'cancelRun'])->name('cancel');
    Route::get('/runs/{run}', [\App\Http\Controllers\Admin\ImporterController::class, 'show'])->name('show');
    Route::post('/listings/upload', [\App\Http\Controllers\Admin\ImporterController::class, 'uploadListings'])->name('listings.upload');
    Route::get('/review', [\App\Http\Controllers\Admin\ImporterController::class, 'review'])->name('review');
    Route::get('/rows/{row}', [\App\Http\Controllers\Admin\ImporterController::class, 'rowDetails'])->name('row.details');
    Route::post('/rows/{row}/confirm', [\App\Http\Controllers\Admin\ImporterController::class, 'confirmRow'])->name('row.confirm');
    Route::post('/rows/{row}/exclude', [\App\Http\Controllers\Admin\ImporterController::class, 'excludeRow'])->name('row.exclude');
    Route::post('/rows/{row}/resolve-agent', [\App\Http\Controllers\Admin\ImporterController::class, 'resolveAgentRow'])->name('row.resolve-agent');
    Route::post('/rows/bulk/confirm', [\App\Http\Controllers\Admin\ImporterController::class, 'confirmBulk'])->name('rows.bulk-confirm');
    Route::post('/rows/bulk/exclude', [\App\Http\Controllers\Admin\ImporterController::class, 'excludeBulk'])->name('rows.bulk-exclude');
    Route::post('/agents/{user}/invite', [\App\Http\Controllers\Admin\ImporterController::class, 'sendInvite'])->name('agent.invite');
    Route::post('/runs/{run}/invite-all', [\App\Http\Controllers\Admin\ImporterController::class, 'sendAllInvites'])->name('invite.all');

    // Onboarding portals — admin management
    Route::post('/portals', [\App\Http\Controllers\Admin\ImporterController::class, 'createPortal'])->name('portal.create');
    Route::post('/portals/{portal}/revoke', [\App\Http\Controllers\Admin\ImporterController::class, 'revokePortal'])->name('portal.revoke');
    Route::post('/portals/{portal}/extend', [\App\Http\Controllers\Admin\ImporterController::class, 'extendPortal'])->name('portal.extend');
    Route::post('/portals/{portal}/invite', [\App\Http\Controllers\Admin\ImporterController::class, 'invitePortal'])->name('portal.invite');
});

// ===== PUBLIC ONBOARDING PORTAL (token-auth, no login) =====
Route::prefix('onboarding/{token}')->middleware(['onboarding.portal'])->name('onboarding.portal.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Public\OnboardingPortalController::class, 'welcome'])->name('welcome');
    Route::get('/review', [\App\Http\Controllers\Public\OnboardingPortalController::class, 'review'])->name('review');
    Route::get('/status', [\App\Http\Controllers\Public\OnboardingPortalController::class, 'status'])->name('status');
    Route::get('/finish', [\App\Http\Controllers\Public\OnboardingPortalController::class, 'finish'])->name('finish');
    Route::post('/rows/{rowId}/confirm', [\App\Http\Controllers\Public\OnboardingPortalController::class, 'confirmRow'])->name('row.confirm');
    Route::post('/rows/{rowId}/exclude', [\App\Http\Controllers\Public\OnboardingPortalController::class, 'excludeRow'])->name('row.exclude');
    Route::post('/rows/{rowId}/reassign', [\App\Http\Controllers\Public\OnboardingPortalController::class, 'reassignAgent'])->name('row.reassign');
    Route::post('/rows/bulk/confirm', [\App\Http\Controllers\Public\OnboardingPortalController::class, 'bulkConfirm'])->name('rows.bulk-confirm');
    Route::post('/rows/bulk/exclude', [\App\Http\Controllers\Public\OnboardingPortalController::class, 'bulkExclude'])->name('rows.bulk-exclude');
    Route::post('/rows/confirm-all', [\App\Http\Controllers\Public\OnboardingPortalController::class, 'confirmAllFiltered'])->name('rows.confirm-all');
});

// ===== PUBLIC AGENCY-SETUP GATE (token landing + real-login gate) =====
// Distinct prefix from onboarding/{token} (P24) to avoid route shadowing.
// The token authenticates the emailed link and logs the Admin in; the wizard
// itself runs under normal auth (see the corex.agency-setup.* group below).
// Spec: .ai/specs/agency-onboarding-setup.md §3.2–3.3.
Route::prefix('agency-setup/{token}')->middleware(['agency.setup.portal'])->name('agency-setup.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Public\AgencySetupGateController::class, 'show'])->name('show');
    Route::post('/login', [\App\Http\Controllers\Public\AgencySetupGateController::class, 'login'])->name('login');
});

// ===== P24 MARKET INTELLIGENCE =====
// Phase D1 — /admin/p24 root GET redirects to the new Market Pulse tab.
// /listings (admin browse) and /import (POST upload trigger) stay mounted
// for admin use; Phase D6 will fold them into the Market Pulse tab proper.
Route::prefix('admin/p24')->middleware(['auth', 'permission:manage_p24'])->group(function () {
    Route::redirect('/', '/corex/market-intelligence/market-pulse', 301)->name('admin.p24.index');
    Route::get('/listings', [\App\Http\Controllers\Admin\P24Controller::class, 'listings'])->name('admin.p24.listings');
    Route::post('/import', [\App\Http\Controllers\Admin\P24Controller::class, 'runImport'])->name('admin.p24.import');
});

// ===== DEPOSIT INTEREST CALCULATOR =====
Route::prefix('deposit-interest-calculator')->middleware(['auth', 'permission:access_deposit_calculator'])->group(function () {
    Route::get('/', [\App\Http\Controllers\DepositInterestCalculatorController::class, 'index'])->name('deposit-interest-calculator.index');
    Route::get('/calculate', fn () => redirect()->route('deposit-interest-calculator.index'));
    Route::post('/calculate', [\App\Http\Controllers\DepositInterestCalculatorController::class, 'calculate'])->name('deposit-interest-calculator.calculate');
    Route::get('/download-pdf', fn () => redirect()->route('deposit-interest-calculator.index'));
    Route::post('/download-pdf', [\App\Http\Controllers\DepositInterestCalculatorController::class, 'downloadPdf'])->name('deposit-interest-calculator.download-pdf');
    Route::get('/download-tenant-pdf', fn () => redirect()->route('deposit-interest-calculator.index'));
    Route::post('/download-tenant-pdf', [\App\Http\Controllers\DepositInterestCalculatorController::class, 'downloadTenantPdf'])->name('deposit-interest-calculator.download-tenant-pdf');
    Route::get('/save', fn () => redirect()->route('deposit-interest-calculator.index'));
    Route::post('/save', [\App\Http\Controllers\DepositInterestCalculatorController::class, 'save'])->name('deposit-interest-calculator.save');
    Route::get('/history', [\App\Http\Controllers\DepositInterestCalculatorController::class, 'history'])->name('deposit-interest-calculator.history')->middleware('permission:access_deposit_calc_history');
    Route::get('/history/{calculation}', [\App\Http\Controllers\DepositInterestCalculatorController::class, 'show'])->name('deposit-interest-calculator.show')->middleware('permission:access_deposit_calc_history');
    Route::delete('/history/{calculation}', [\App\Http\Controllers\DepositInterestCalculatorController::class, 'destroy'])->name('deposit-interest-calculator.destroy')->middleware('permission:access_deposit_calc_history');
});

// ===== DEAL REGISTER (DR2) — shared shell (AT-215) =====
// DR2 rebuilds DR1 on the SAME `deals` tables (spec deal-register-v2-rebuild-spec.md),
// coexisting with DR1 behind its own nav + permission. Distinct from the abandoned
// deals-v2 module (URI `deals-v2/*`), which sunsets under AT-219. Reuses DR1's deal
// permissions (view_deals / create_deals). AT-217 (cc3) builds the capture into create/store.
Route::prefix('deals-dr2')->middleware('auth')->name('deals-dr2.')->group(function () {
    Route::get('/',              [\App\Http\Controllers\Dr2\DealRegisterController::class, 'index'])->middleware('permission:view_deals')->name('index');
    Route::get('/create',        [\App\Http\Controllers\Dr2\DealRegisterController::class, 'create'])->middleware('permission:create_deals')->name('create');
    Route::post('/',             [\App\Http\Controllers\Dr2\DealRegisterController::class, 'store'])->middleware('permission:create_deals')->name('store');

    // AT-217 §2 capture-enhancement JSON feeds (canonical property picker + linked
    // seller/buyer contacts + attorney supplier directory). Static paths declared
    // BEFORE the {deal} wildcards so they never shadow-capture.
    Route::get('/search/properties',            [\App\Http\Controllers\Dr2\DealRegisterController::class, 'searchProperties'])->middleware('permission:create_deals')->name('search.properties');
    Route::get('/search/property-contacts/{property}', [\App\Http\Controllers\Dr2\DealRegisterController::class, 'propertyContacts'])->middleware('permission:create_deals')->name('search.property-contacts');
    Route::get('/search/contacts',              [\App\Http\Controllers\Dr2\DealRegisterController::class, 'contactSearch'])->middleware('permission:create_deals')->name('search.contacts');
    Route::post('/contact/inline',              [\App\Http\Controllers\Dr2\DealRegisterController::class, 'contactInline'])->middleware('permission:create_deals')->name('contact.inline');
    // §2.4 attorney — reuse the shared supplier directory (agency_service_providers),
    // gated on DR2's own create_deals so DR2 never depends on the sunset deals-v2 perms.
    Route::get('/suppliers/search',             [\App\Http\Controllers\DealV2\SupplierDirectoryController::class, 'search'])->middleware('permission:create_deals')->name('suppliers.search');
    Route::post('/suppliers/inline',            [\App\Http\Controllers\DealV2\SupplierDirectoryController::class, 'createInline'])->middleware('permission:create_deals')->name('suppliers.inline');
    // (Johan DR2-walk fix 2) attorney = FIRM + contact person — DR2-specific feeds.
    Route::get('/attorney/search',              [\App\Http\Controllers\Dr2\DealRegisterController::class, 'attorneySearch'])->middleware('permission:create_deals')->name('attorney.search');
    Route::post('/attorney/inline',             [\App\Http\Controllers\Dr2\DealRegisterController::class, 'attorneyInline'])->middleware('permission:create_deals')->name('attorney.inline');

    Route::get('/{deal}/edit',   [\App\Http\Controllers\Dr2\DealRegisterController::class, 'edit'])->middleware('permission:create_deals')->name('edit');
    // DR1 parity: update is a POST (DR1's form.blade POSTs to it), not PUT.
    Route::post('/{deal}',       [\App\Http\Controllers\Dr2\DealRegisterController::class, 'update'])->middleware('permission:create_deals')->name('update');
    Route::post('/{deal}/quick', [\App\Http\Controllers\Dr2\DealRegisterController::class, 'quickUpdate'])->middleware('permission:create_deals')->name('quickUpdate');

    // Feedback — DR2 doctrine: AGENTS may read the log + add remarks (view_deals),
    // separate from deal setup (create_deals). Pipeline step updates ride m1's routes.
    Route::get('/{deal}/log',    [\App\Http\Controllers\Dr2\DealRegisterController::class, 'log'])->middleware('permission:view_deals')->name('log');
    Route::post('/{deal}/remark', [\App\Http\Controllers\Dr2\DealRegisterController::class, 'addRemark'])->middleware('permission:view_deals')->name('remark');

    // Settlement — admin + BM only (settle_deals). Faithful DR1 copy on the same tables.
    Route::get('/{deal}/settle',              [\App\Http\Controllers\Dr2\DealSettlementController::class, 'settle'])->middleware('permission:settle_deals')->name('settle');
    Route::post('/{deal}/settle',             [\App\Http\Controllers\Dr2\DealSettlementController::class, 'saveSettlement'])->middleware('permission:settle_deals')->name('settle.save');
    Route::get('/{deal}/settle/print',        [\App\Http\Controllers\Dr2\DealSettlementController::class, 'printSettlement'])->middleware('permission:settle_deals')->name('settle.print');
    Route::get('/{deal}/settle/print/{user}', [\App\Http\Controllers\Dr2\DealSettlementController::class, 'printAgentPayslip'])->middleware('permission:settle_deals')->name('settle.print.agent');

    // AT-216 — pipeline tracking overlay on the DR2 register (pure tracking: never mutates
    // the DR1 deal, only its pipeline steps + pointer). view_deals to see, create_deals to act.
    Route::get('/{deal}/pipeline',                        [\App\Http\Controllers\Dr2\PipelineController::class, 'show'])->whereNumber('deal')->middleware('permission:view_deals')->name('pipeline');
    Route::post('/{deal}/pipeline/attach',                [\App\Http\Controllers\Dr2\PipelineController::class, 'attach'])->whereNumber('deal')->middleware('permission:view_deals')->name('pipeline.attach');
    Route::post('/{deal}/pipeline/steps/{step}/complete', [\App\Http\Controllers\Dr2\PipelineController::class, 'completeStep'])->whereNumber(['deal', 'step'])->middleware('permission:view_deals')->name('pipeline.step.complete');
    // V1.1 — per-step operations (all agency-scoped, audited; soft deletes)
    Route::post('/{deal}/pipeline/steps/add',              [\App\Http\Controllers\Dr2\PipelineController::class, 'addStep'])->whereNumber('deal')->middleware('permission:view_deals')->name('pipeline.step.add');
    Route::post('/{deal}/pipeline/steps/{step}/na',        [\App\Http\Controllers\Dr2\PipelineController::class, 'markNa'])->whereNumber(['deal', 'step'])->middleware('permission:view_deals')->name('pipeline.step.na');
    Route::post('/{deal}/pipeline/steps/{step}/remove',    [\App\Http\Controllers\Dr2\PipelineController::class, 'removeStep'])->whereNumber(['deal', 'step'])->middleware('permission:view_deals')->name('pipeline.step.remove');
    Route::post('/{deal}/pipeline/steps/{step}/comment',   [\App\Http\Controllers\Dr2\PipelineController::class, 'addComment'])->whereNumber(['deal', 'step'])->middleware('permission:view_deals')->name('pipeline.step.comment');
    // R2 — due-date edit + restore/reinstate (no permanent stranding)
    Route::post('/{deal}/pipeline/steps/{step}/due',       [\App\Http\Controllers\Dr2\PipelineController::class, 'editDue'])->whereNumber(['deal', 'step'])->middleware('permission:view_deals')->name('pipeline.step.due');
    Route::post('/{deal}/pipeline/steps/restore',          [\App\Http\Controllers\Dr2\PipelineController::class, 'restoreStep'])->whereNumber('deal')->middleware('permission:view_deals')->name('pipeline.step.restore');
    Route::post('/{deal}/pipeline/steps/{step}/reinstate', [\App\Http\Controllers\Dr2\PipelineController::class, 'reinstateStep'])->whereNumber(['deal', 'step'])->middleware('permission:view_deals')->name('pipeline.step.reinstate');

    // DR2 documents (AT-225/226 docs lane) — upload/attach on the deal (files to deal+property+contacts via the twin bridge).
    Route::post('/{deal}/documents',                    [\App\Http\Controllers\Dr2\DealDocumentController::class, 'store'])->whereNumber('deal')->middleware('permission:view_deals')->name('documents.store');
    Route::get('/{deal}/documents/{document}/download', [\App\Http\Controllers\Dr2\DealDocumentController::class, 'download'])->whereNumber(['deal', 'document'])->middleware('permission:view_deals')->name('documents.download');

    // Proforma Invoices (Accounting pillar) — any agent may generate from Granted onward
    // (server-gated); the endpoint re-checks eligibility, never trusts the hidden button.
    Route::post('/{deal}/proforma', [\App\Http\Controllers\Proforma\ProformaController::class, 'generate'])->whereNumber('deal')->middleware('permission:proforma.generate')->name('proforma.generate');

    // AT-228 — party-first document distribution (compose-and-review → send). Matrix does the
    // thinking; the agent authorises. Gated on the deals-v2 distribute permission.
    Route::get('/{deal}/distribute',  [\App\Http\Controllers\Dr2\DealDistributionController::class, 'compose'])->whereNumber('deal')->middleware('permission:deals_v2.distribute_documents')->name('distribute.compose');
    Route::post('/{deal}/distribute', [\App\Http\Controllers\Dr2\DealDistributionController::class, 'send'])->whereNumber('deal')->middleware('permission:deals_v2.distribute_documents')->name('distribute.send');
});

// ===== PROFORMA INVOICES — view/download + ADMIN-ONLY overrides + settings =====
Route::prefix('proforma')->middleware('auth')->name('proforma.')->group(function () {
    Route::get('/{invoice}',          [\App\Http\Controllers\Proforma\ProformaController::class, 'show'])->whereNumber('invoice')->middleware('permission:proforma.generate')->name('show');
    Route::get('/{invoice}/download', [\App\Http\Controllers\Proforma\ProformaController::class, 'download'])->whereNumber('invoice')->middleware('permission:proforma.generate')->name('download');
    // Admin-only (permission re-checked in the controller too).
    Route::post('/{invoice}/lines',            [\App\Http\Controllers\Proforma\ProformaAdminController::class, 'addLine'])->whereNumber('invoice')->middleware('permission:proforma.manage')->name('lines.add');
    Route::delete('/{invoice}/lines/{line}',   [\App\Http\Controllers\Proforma\ProformaAdminController::class, 'removeLine'])->whereNumber(['invoice', 'line'])->middleware('permission:proforma.manage')->name('lines.remove');
    Route::post('/{invoice}/void',             [\App\Http\Controllers\Proforma\ProformaAdminController::class, 'void'])->whereNumber('invoice')->middleware('permission:proforma.manage')->name('void');
    Route::post('/{invoice}/regenerate',       [\App\Http\Controllers\Proforma\ProformaAdminController::class, 'regenerate'])->whereNumber('invoice')->middleware('permission:proforma.manage')->name('regenerate');
});

// Agency "Proforma Invoices" settings section (admin only).
Route::middleware(['auth', 'permission:proforma.manage'])->group(function () {
    Route::get('/admin/proforma-settings',  [\App\Http\Controllers\Admin\ProformaSettingsController::class, 'index'])->name('admin.proforma-settings');
    Route::put('/admin/proforma-settings',  [\App\Http\Controllers\Admin\ProformaSettingsController::class, 'update'])->name('admin.proforma-settings.update');
});

// ===== DEAL REGISTER V2 — PIPELINE SETUP =====
Route::prefix('deals-v2/pipeline-setup')->middleware(['auth', 'permission:deals_v2.manage_pipeline'])->group(function () {
    Route::get('/', [\App\Http\Controllers\DealV2\DealPipelineSetupController::class, 'index'])->name('deals-v2.pipeline.index');
    Route::get('/create', [\App\Http\Controllers\DealV2\DealPipelineSetupController::class, 'create'])->name('deals-v2.pipeline.create');
    Route::post('/', [\App\Http\Controllers\DealV2\DealPipelineSetupController::class, 'store'])->name('deals-v2.pipeline.store');
    // AT-158 WS-R1 — one-click "Load standard templates" (idempotent, own agency)
    Route::post('/load-defaults', [\App\Http\Controllers\DealV2\DealPipelineSetupController::class, 'loadDefaults'])->name('deals-v2.pipeline.load-defaults');
    Route::get('/{template}/edit', [\App\Http\Controllers\DealV2\DealPipelineSetupController::class, 'edit'])->name('deals-v2.pipeline.edit');
    Route::put('/{template}', [\App\Http\Controllers\DealV2\DealPipelineSetupController::class, 'update'])->name('deals-v2.pipeline.update');
    Route::delete('/{template}', [\App\Http\Controllers\DealV2\DealPipelineSetupController::class, 'destroy'])->name('deals-v2.pipeline.destroy');
    Route::post('/{template}/duplicate', [\App\Http\Controllers\DealV2\DealPipelineSetupController::class, 'duplicate'])->name('deals-v2.pipeline.duplicate');

    // Step AJAX endpoints
    Route::post('/{template}/steps', [\App\Http\Controllers\DealV2\DealPipelineStepController::class, 'store'])->name('deals-v2.pipeline.steps.store');
    Route::put('/steps/{step}', [\App\Http\Controllers\DealV2\DealPipelineStepController::class, 'update'])->name('deals-v2.pipeline.steps.update');
    Route::delete('/steps/{step}', [\App\Http\Controllers\DealV2\DealPipelineStepController::class, 'destroy'])->name('deals-v2.pipeline.steps.destroy');
    Route::post('/{template}/steps/reorder', [\App\Http\Controllers\DealV2\DealPipelineStepController::class, 'reorder'])->name('deals-v2.pipeline.steps.reorder');
});

// ===== DEAL REGISTER V2 — SUPPLIER DIRECTORY (WS2 / D2) =====
Route::prefix('deals-v2/suppliers')->middleware(['auth'])->group(function () {
    Route::get('/', [\App\Http\Controllers\DealV2\SupplierDirectoryController::class, 'index'])->name('deals-v2.suppliers.index')->middleware('permission:deals_v2.manage_suppliers');
    Route::post('/', [\App\Http\Controllers\DealV2\SupplierDirectoryController::class, 'store'])->name('deals-v2.suppliers.store')->middleware('permission:deals_v2.manage_suppliers');
    Route::put('/{provider}', [\App\Http\Controllers\DealV2\SupplierDirectoryController::class, 'update'])->name('deals-v2.suppliers.update')->middleware('permission:deals_v2.manage_suppliers');
    Route::post('/{provider}/preferred', [\App\Http\Controllers\DealV2\SupplierDirectoryController::class, 'markPreferred'])->name('deals-v2.suppliers.preferred')->middleware('permission:deals_v2.manage_suppliers');
    Route::post('/{provider}/deactivate', [\App\Http\Controllers\DealV2\SupplierDirectoryController::class, 'deactivate'])->name('deals-v2.suppliers.deactivate')->middleware('permission:deals_v2.manage_suppliers');
    // (DR2 respec) firm → contact persons management.
    Route::post('/{provider}/contacts', [\App\Http\Controllers\DealV2\SupplierDirectoryController::class, 'storeContact'])->name('deals-v2.suppliers.contacts.store')->middleware('permission:deals_v2.manage_suppliers');
    Route::post('/contacts/{contact}/deactivate', [\App\Http\Controllers\DealV2\SupplierDirectoryController::class, 'deactivateContact'])->name('deals-v2.suppliers.contacts.deactivate')->middleware('permission:deals_v2.manage_suppliers');
    // Inline pick-or-create (used by the deal form) — gated to agents working a deal.
    Route::get('/search', [\App\Http\Controllers\DealV2\SupplierDirectoryController::class, 'search'])->name('deals-v2.suppliers.search')->middleware('permission:deals_v2.edit');
    Route::post('/inline', [\App\Http\Controllers\DealV2\SupplierDirectoryController::class, 'createInline'])->name('deals-v2.suppliers.inline')->middleware('permission:deals_v2.edit');
});

// ===== DEAL REGISTER V2 =====
// WS4 (§8.2a) — PUBLIC secure-document recipient flow (tokened + OTP-gated).
// No auth: the unguessable 40-char token is the credential; identity is proven
// by an OTP to the recipient's own email before the document ever streams.
Route::prefix('deals-v2/secure-doc')->group(function () {
    // AT-264 — PACK flow: ONE link + ONE OTP unlocks the whole group. Registered
    // BEFORE the /{token} routes (literal 'pack' prefix → zero ambiguity).
    Route::get('/pack/{groupKey}', [\App\Http\Controllers\DealV2\SecureDocumentController::class, 'packShow'])->name('deals-v2.secure-doc.pack');
    Route::post('/pack/{groupKey}/otp', [\App\Http\Controllers\DealV2\SecureDocumentController::class, 'packRequestOtp'])->name('deals-v2.secure-doc.pack.otp');
    Route::post('/pack/{groupKey}/verify', [\App\Http\Controllers\DealV2\SecureDocumentController::class, 'packVerifyOtp'])->name('deals-v2.secure-doc.pack.verify');
    Route::get('/pack/{groupKey}/download/{distribution}', [\App\Http\Controllers\DealV2\SecureDocumentController::class, 'packDownload'])->name('deals-v2.secure-doc.pack.download');

    // Per-document flow (unchanged — keeps already-sent links working).
    Route::get('/{token}', [\App\Http\Controllers\DealV2\SecureDocumentController::class, 'show'])->name('deals-v2.secure-doc.show');
    Route::post('/{token}/otp', [\App\Http\Controllers\DealV2\SecureDocumentController::class, 'requestOtp'])->name('deals-v2.secure-doc.otp');
    Route::post('/{token}/verify', [\App\Http\Controllers\DealV2\SecureDocumentController::class, 'verifyOtp'])->name('deals-v2.secure-doc.verify');
    Route::get('/{token}/download', [\App\Http\Controllers\DealV2\SecureDocumentController::class, 'download'])->name('deals-v2.secure-doc.download');
});

// WS8 (§12) — PUBLIC per-user iCal deal feed (no auth: calendar apps poll the raw
// tokenised URL). Read-only; the token resolves the user + their permitted scope.
Route::get('/deals-v2/ical/{token}.ics', [\App\Http\Controllers\DealV2\DealIcalController::class, 'feed'])->name('deals-v2.ical');

Route::prefix('deals-v2')->middleware(['auth'])->group(function () {
    Route::get('/', [\App\Http\Controllers\DealV2\DealV2Controller::class, 'index'])->name('deals-v2.index')->middleware('permission:access_deal_register_v2');
    // WS8 — manage the personal iCal feed token (rotate / disable).
    Route::post('/ical/regenerate', [\App\Http\Controllers\DealV2\DealIcalController::class, 'regenerate'])->name('deals-v2.ical.regenerate')->middleware('permission:access_deal_register_v2');
    Route::post('/ical/disable', [\App\Http\Controllers\DealV2\DealIcalController::class, 'disable'])->name('deals-v2.ical.disable')->middleware('permission:access_deal_register_v2');
    // WS8 (§12) — pipeline overview (KPI cards + milestone board), branch_manager
    // + admin only; and CSV export of the filtered register. Static paths BEFORE
    // the /{deal} wildcard so they are not captured as a deal id.
    Route::get('/overview', [\App\Http\Controllers\DealV2\DealV2Controller::class, 'overview'])->name('deals-v2.overview')->middleware('permission:deals_v2.view_overview');
    Route::get('/export', [\App\Http\Controllers\DealV2\DealV2Controller::class, 'exportCsv'])->name('deals-v2.export')->middleware('permission:access_deal_register_v2');
    // WS2 — attach a directory provider to a deal under a provider role.
    Route::post('/{deal}/providers', [\App\Http\Controllers\DealV2\SupplierDirectoryController::class, 'attach'])->name('deals-v2.providers.attach')->middleware('permission:deals_v2.edit');
    // WS3 (D4) — upload a document directly onto a deal + gated download.
    Route::post('/{deal}/documents', [\App\Http\Controllers\DealV2\DealV2Controller::class, 'storeDocument'])->name('deals-v2.documents.store')->middleware('permission:deals_v2.edit');
    Route::get('/{deal}/documents/{document}/download', [\App\Http\Controllers\DealV2\DealV2Controller::class, 'downloadDocument'])->name('deals-v2.documents.download')->middleware('permission:access_deal_register_v2');
    // WS4 (§8.3) — distribute documents (matrix-resolved) + revoke a secure link.
    Route::get('/{deal}/distribute', [\App\Http\Controllers\DealV2\DealDistributionController::class, 'plan'])->name('deals-v2.distribute.plan')->middleware('permission:deals_v2.distribute_documents');
    Route::post('/{deal}/distribute', [\App\Http\Controllers\DealV2\DealDistributionController::class, 'send'])->name('deals-v2.distribute.send')->middleware('permission:deals_v2.distribute_documents');
    Route::post('/distributions/{distribution}/revoke', [\App\Http\Controllers\DealV2\DealDistributionController::class, 'revoke'])->name('deals-v2.distributions.revoke')->middleware('permission:deals_v2.distribute_documents');
    Route::get('/create', [\App\Http\Controllers\DealV2\DealV2Controller::class, 'create'])->name('deals-v2.create')->middleware('permission:deals_v2.create,deals_v2.capture_own');
    // AT-158 WS-R2 — optional step wizard (same shared store() write-path)
    Route::get('/create-wizard', [\App\Http\Controllers\DealV2\DealV2Controller::class, 'createWizard'])->name('deals-v2.create-wizard')->middleware('permission:deals_v2.create,deals_v2.capture_own');
    Route::post('/', [\App\Http\Controllers\DealV2\DealV2Controller::class, 'store'])->name('deals-v2.store')->middleware('permission:deals_v2.create,deals_v2.capture_own');
    Route::get('/search/properties', [\App\Http\Controllers\DealV2\DealV2Controller::class, 'searchProperties'])->name('deals-v2.search.properties');
    Route::get('/search/contacts', [\App\Http\Controllers\DealV2\DealV2Controller::class, 'searchContacts'])->name('deals-v2.search.contacts');
    Route::get('/search/deals', [\App\Http\Controllers\DealV2\DealV2Controller::class, 'searchDeals'])->name('deals-v2.search.deals');
    Route::get('/search/property-contacts/{property}', [\App\Http\Controllers\DealV2\DealV2Controller::class, 'getPropertyContacts'])->name('deals-v2.search.property-contacts');
    Route::get('/{deal}/edit', [\App\Http\Controllers\DealV2\DealV2Controller::class, 'edit'])->name('deals-v2.edit')->middleware('permission:deals_v2.edit');
    Route::put('/{deal}', [\App\Http\Controllers\DealV2\DealV2Controller::class, 'update'])->name('deals-v2.update')->middleware('permission:deals_v2.edit');
    Route::delete('/{deal}', [\App\Http\Controllers\DealV2\DealV2Controller::class, 'destroy'])->name('deals-v2.destroy')->middleware('permission:deals_v2.archive');
    Route::get('/{deal}', [\App\Http\Controllers\DealV2\DealV2Controller::class, 'show'])->name('deals-v2.show')->middleware('permission:access_deal_register_v2');

    // Step actions
    Route::post('/steps/{step}/complete', [\App\Http\Controllers\DealV2\DealStepController::class, 'complete'])->name('deals-v2.steps.complete')->middleware('permission:deals_v2.edit');
    Route::post('/steps/{step}/approve', [\App\Http\Controllers\DealV2\DealStepController::class, 'approve'])->name('deals-v2.steps.approve');
    Route::post('/steps/{step}/reject', [\App\Http\Controllers\DealV2\DealStepController::class, 'reject'])->name('deals-v2.steps.reject');
    Route::post('/steps/{step}/upload', [\App\Http\Controllers\DealV2\DealStepController::class, 'uploadDocument'])->name('deals-v2.steps.upload')->middleware('permission:deals_v2.edit');
    Route::post('/steps/{step}/override-date', [\App\Http\Controllers\DealV2\DealStepController::class, 'overrideDueDate'])->name('deals-v2.steps.override-date')->middleware('permission:deals_v2.override_dates');

    // Deal remarks (WS-V6) — free-form feedback thread, scope-gated, soft-delete only
    Route::post('/{deal}/remarks', [\App\Http\Controllers\DealV2\DealRemarkController::class, 'store'])->name('deals-v2.remarks.store')->middleware('permission:access_deal_register_v2');
    Route::delete('/remarks/{remark}', [\App\Http\Controllers\DealV2\DealRemarkController::class, 'destroy'])->name('deals-v2.remarks.destroy')->middleware('permission:access_deal_register_v2');

    // Stage gate (WS-V2) — confirm a prompt-mode move, undo an applied move, dismiss a prompt
    Route::post('/stage-moves/{move}/confirm', [\App\Http\Controllers\DealV2\DealStageController::class, 'confirm'])->name('deals-v2.stage.confirm')->middleware('permission:deals_v2.edit');
    Route::post('/stage-moves/{move}/undo', [\App\Http\Controllers\DealV2\DealStageController::class, 'undo'])->name('deals-v2.stage.undo')->middleware('permission:deals_v2.edit');
    Route::post('/stage-moves/{move}/dismiss', [\App\Http\Controllers\DealV2\DealStageController::class, 'dismiss'])->name('deals-v2.stage.dismiss')->middleware('permission:deals_v2.edit');

    // Settlement
    Route::get('/{deal}/settlement', [\App\Http\Controllers\DealV2\DealV2SettlementController::class, 'settle'])->name('deals-v2.settlement.index')->middleware('permission:deals_v2.edit');
    Route::post('/{deal}/settlement', [\App\Http\Controllers\DealV2\DealV2SettlementController::class, 'saveSettlement'])->name('deals-v2.settlement.save')->middleware('permission:deals_v2.edit');
    Route::get('/{deal}/settlement/print', [\App\Http\Controllers\DealV2\DealV2SettlementController::class, 'printSettlement'])->name('deals-v2.settlement.print')->middleware('permission:access_deal_register_v2');
    Route::get('/{deal}/settlement/payslip/{user}', [\App\Http\Controllers\DealV2\DealV2SettlementController::class, 'printAgentPayslip'])->name('deals-v2.settlement.payslip')->middleware('permission:access_deal_register_v2');
});

// ===== DEPOSIT TRUST INTEREST =====
Route::prefix('admin/deposit-trust-interest')->middleware(['auth', 'permission:access_trust_interest'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\DepositTrustInterestController::class, 'index'])->name('admin.deposit-trust-interest.index');
    Route::post('/', [\App\Http\Controllers\Admin\DepositTrustInterestController::class, 'store'])->name('admin.deposit-trust-interest.store');
    Route::put('/{record}', [\App\Http\Controllers\Admin\DepositTrustInterestController::class, 'update'])->name('admin.deposit-trust-interest.update');
    Route::delete('/{record}', [\App\Http\Controllers\Admin\DepositTrustInterestController::class, 'destroy'])->name('admin.deposit-trust-interest.destroy');
});

// ===== KNOWLEDGE BASE =====
Route::prefix('admin/knowledge')->middleware(['auth', 'permission:access_knowledge_base'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\KnowledgeController::class, 'index'])->name('admin.knowledge.index');
    Route::get('/category/{id}', [\App\Http\Controllers\Admin\KnowledgeController::class, 'show'])->name('admin.knowledge.category');
    Route::post('/upload', [\App\Http\Controllers\Admin\KnowledgeController::class, 'upload'])->name('admin.knowledge.upload');
    Route::post('/{id}/toggle-active', [\App\Http\Controllers\Admin\KnowledgeController::class, 'toggleActive'])->name('admin.knowledge.toggleActive');
    Route::post('/{id}/toggle-ellie', [\App\Http\Controllers\Admin\KnowledgeController::class, 'toggleEllie'])->name('admin.knowledge.toggleEllie');
    Route::post('/{id}/reprocess', [\App\Http\Controllers\Admin\KnowledgeController::class, 'reprocess'])->name('admin.knowledge.reprocess');
    Route::delete('/{id}', [\App\Http\Controllers\Admin\KnowledgeController::class, 'destroy'])->name('admin.knowledge.destroy');
    Route::get('/{id}/preview', [\App\Http\Controllers\Admin\KnowledgeController::class, 'preview'])->name('admin.knowledge.preview');

    // Category CRUD
    Route::post('/categories', [\App\Http\Controllers\Admin\KnowledgeController::class, 'storeCategory'])->name('admin.knowledge.storeCategory');
    Route::put('/categories/{id}', [\App\Http\Controllers\Admin\KnowledgeController::class, 'updateCategory'])->name('admin.knowledge.updateCategory');
    Route::delete('/categories/{id}', [\App\Http\Controllers\Admin\KnowledgeController::class, 'deleteCategory'])->name('admin.knowledge.deleteCategory');
    Route::post('/categories/reorder', [\App\Http\Controllers\Admin\KnowledgeController::class, 'reorderCategories'])->name('admin.knowledge.reorderCategories');
});

// ===== PUBLIC PROPERTY PREVIEW (shareable, no auth required) =====
Route::get('/corex/properties/{property}/preview/{slug?}', [\App\Http\Controllers\CoreX\PropertyController::class, 'livePreview'])
    ->name('corex.properties.preview');

// ===== PUBLIC AGENT PROFILE (QR-code target, shareable, no auth required) =====
// /corex/agents/{name-slug}/{qr_code_slug}. The 10-char slug constraint keeps
// these from shadowing the auth-gated internal preview routes
// (/corex/agents/{user}/preview/…) registered later. Spec: agent-qr-onboarding.
Route::get('/corex/agents/{nameSlug}/{tag}/articles/{article}', [\App\Http\Controllers\CoreX\AgentPreviewController::class, 'publicArticle'])
    ->where('tag', '[a-z0-9]{10}')->name('corex.agents.public.article');
Route::get('/corex/agents/{nameSlug}/{tag}', [\App\Http\Controllers\CoreX\AgentPreviewController::class, 'publicShow'])
    ->where('tag', '[a-z0-9]{10}')->name('corex.agents.public');

// Backwards-compat: original QR URL (/r/a/{slug}) printed on cards in the wild.
Route::get('/r/a/{slug}', [\App\Http\Controllers\CoreX\AgentPreviewController::class, 'legacyQrRedirect'])
    ->where('slug', '[a-z0-9]{6,16}')->name('agent.qr.legacy');

// ===== FAULT REPORTS =====
Route::middleware(['auth'])->group(function () {
    Route::post('/admin/fault-reports/manual', [\App\Http\Controllers\FaultReportController::class, 'manualReport'])
        ->name('admin.fault-reports.manual');
    Route::get('/admin/fault-reports/{id}', [\App\Http\Controllers\FaultReportController::class, 'show'])
        ->name('admin.fault-reports.show');
    Route::post('/admin/fault-reports/{id}/status', [\App\Http\Controllers\FaultReportController::class, 'updateStatus'])
        ->name('admin.fault-reports.update-status');
    Route::post('/admin/fault-reports/bulk', [\App\Http\Controllers\FaultReportController::class, 'bulkAction'])
        ->name('admin.fault-reports.bulk');
    Route::post('/admin/fault-reports/clear-all', [\App\Http\Controllers\FaultReportController::class, 'clearAll'])
        ->name('admin.fault-reports.clear-all');
    Route::post('/admin/fault-reports/scan', [\App\Http\Controllers\FaultReportController::class, 'scan'])
        ->name('admin.fault-reports.scan');
    Route::get('/admin/fault-reports', [\App\Http\Controllers\FaultReportController::class, 'index'])
        ->name('admin.fault-reports');
});

// ===== LISTING IMPORT =====
Route::middleware(['auth','permission:import_listings'])->group(function () {
    Route::get('/admin/listings/import', [\App\Http\Controllers\Admin\ListingImportController::class, 'index'])
        ->name('admin.listings.import');

    Route::post('/admin/listings/import', [\App\Http\Controllers\Admin\ListingImportController::class, 'store'])
        ->name('admin.listings.import.store');
});


// ===== LISTING STOCK =====
Route::middleware(['auth','permission:view_listings'])->group(function () {
    Route::get('/admin/listings/agents', [\App\Http\Controllers\Admin\ListingStockController::class, 'agents'])
        ->name('admin.listings.agents');

    Route::get('/admin/listings/agents/{user}', [\App\Http\Controllers\Admin\ListingStockController::class, 'agentShow'])
        ->name('admin.listings.agents.show');

    Route::get('/admin/listings/stock', [\App\Http\Controllers\Admin\CompanyListingStockController::class, 'index'])
        ->name('admin.listings.stock');


    // Admin: Fix listing agent assignment (primary + multi agents)
    Route::get('/admin/listings/stock/{listing}/agents', [\App\Http\Controllers\Admin\ListingStockController::class, 'editAgents'])
        ->name('admin.listings.stock.agents.edit');

    Route::post('/admin/listings/stock/{listing}/agents', [\App\Http\Controllers\Admin\ListingStockController::class, 'updateAgents'])
        ->name('admin.listings.stock.agents.update');
});



// Admin impersonation
Route::middleware(['auth'])->group(function () {

    Route::post('/admin/impersonate/stop', [\App\Http\Controllers\Admin\ImpersonateController::class, 'stop'])
        ->name('impersonate.stop');

    Route::post('/admin/impersonate/{user}', [\App\Http\Controllers\Admin\ImpersonateController::class, 'start'])
        ->middleware('permission:impersonate_users')->name('impersonate.start');
    // Allow click-through (GET) stop for sidebar UX (session-gated)
});

require __DIR__.'/auth.php';

// ===== TARGETS_MODULE_2026 =====
use App\Http\Controllers\Admin\TargetController;
use App\Http\Controllers\ToolsController;
use App\Http\Controllers\Tools\PdfSplitterController;
use App\Http\Controllers\Tools\PdfSuiteController;
use App\Http\Controllers\Tools\ImageConverterController;

Route::middleware(['auth'])->group(function () {


    // Tools
    Route::get('/tools/commission', [ToolsController::class, 'commission'])->middleware('permission:access_calculators')->name('tools.commission');
    Route::get('/tools/cma', [ToolsController::class, 'cma'])->middleware('permission:access_calculators')->name('tools.cma');

    // Ad Manager (bulk) — spec .ai/specs/ad-manager.md §10b
    Route::get('/tools/ad-manager', [\App\Http\Controllers\Tools\AdManagerController::class, 'index'])->middleware(['permission:access_ad_manager', 'agency.required'])->name('tools.ad-manager');
    Route::post('/tools/ad-manager/previews', [\App\Http\Controllers\Tools\AdManagerController::class, 'previews'])->middleware(['permission:access_ad_manager', 'agency.required'])->name('tools.ad-manager.previews');
    Route::post('/tools/ad-manager/generate', [\App\Http\Controllers\Tools\AdManagerController::class, 'generate'])->middleware(['permission:access_ad_manager', 'agency.required'])->name('tools.ad-manager.generate');

    // Tools History (backend)
    Route::get('/tools/history', [ToolsController::class, 'historyIndex'])->middleware('permission:access_calculators')->name('tools.history.index');
    Route::post('/tools/history', [ToolsController::class, 'historyStore'])->middleware('permission:access_calculators')->name('tools.history.store');
    Route::get('/tools/history/{id}', [ToolsController::class, 'historyShow'])->middleware('permission:access_calculators')->name('tools.history.show');
    Route::delete('/tools/history/{id}', [ToolsController::class, 'historyDestroy'])->middleware('permission:access_calculators')->name('tools.history.destroy');

    // PDF Pack Splitter
    Route::get('/tools/pdf-splitter', [PdfSplitterController::class, 'index'])->middleware('permission:access_pdf_splitter')->name('tools.pdf_splitter.index');
    Route::post('/tools/pdf-splitter/run', [PdfSplitterController::class, 'run'])->middleware('permission:access_pdf_splitter')->name('tools.pdf_splitter.run');
    Route::get('/tools/pdf-splitter/review', [PdfSplitterController::class, 'review'])->middleware('permission:access_pdf_splitter')->name('tools.pdf_splitter.review');
    Route::post('/tools/pdf-splitter/confirm', [PdfSplitterController::class, 'confirm'])->middleware('permission:access_pdf_splitter')->name('tools.pdf_splitter.confirm');
    Route::get('/tools/pdf-splitter/thumb/{page}', [PdfSplitterController::class, 'serveThumb'])->middleware('permission:access_pdf_splitter')->name('tools.pdf_splitter.thumb')->where('page', '[0-9]+');
    Route::get('/tools/pdf-splitter/download', [PdfSplitterController::class, 'downloadLastZip'])->middleware('permission:access_pdf_splitter')->name('tools.pdf_splitter.download');
    Route::get('/tools/pdf-splitter/properties/search', [PdfSplitterController::class, 'searchProperties'])->middleware('permission:access_pdf_splitter')->name('tools.pdf_splitter.properties.search');
    Route::get('/tools/pdf-splitter/properties/{property}/contacts', [PdfSplitterController::class, 'propertyContacts'])->middleware('permission:access_pdf_splitter')->name('tools.pdf_splitter.properties.contacts')->where('property', '[0-9]+');
    // AT-105 enh — per-page "Link to CoreX" (file + multi-FICA) is a distinct
    // action from the ZIP download. Both submit the per-page assignments.
    Route::post('/tools/pdf-splitter/link', [PdfSplitterController::class, 'link'])->middleware('permission:access_pdf_splitter')->name('tools.pdf_splitter.link');

    // PDF Suite — hub + 7 sibling tools (Splitter is reachable from the hub)
    Route::middleware('permission:access_pdf_suite')->prefix('tools/pdf-suite')->name('tools.pdf_suite.')->group(function () {
        Route::get('/',              [PdfSuiteController::class, 'hub'])->name('hub');

        Route::get('/compress',      [PdfSuiteController::class, 'compress'])->name('compress');
        Route::post('/compress',     [PdfSuiteController::class, 'compressRun'])->name('compress.run');

        Route::get('/merge',         [PdfSuiteController::class, 'merge'])->name('merge');
        Route::post('/merge',        [PdfSuiteController::class, 'mergeRun'])->name('merge.run');

        Route::get('/image-to-pdf',  [PdfSuiteController::class, 'imageToPdf'])->name('image-to-pdf');
        Route::post('/image-to-pdf', [PdfSuiteController::class, 'imageToPdfRun'])->name('image-to-pdf.run');

        Route::get('/rotate',        [PdfSuiteController::class, 'rotate'])->name('rotate');
        Route::post('/rotate',       [PdfSuiteController::class, 'rotateRun'])->name('rotate.run');

        Route::get('/reorder',       [PdfSuiteController::class, 'reorder'])->name('reorder');
        Route::post('/reorder',      [PdfSuiteController::class, 'reorderRun'])->name('reorder.run');

        Route::get('/protect',       [PdfSuiteController::class, 'protect'])->name('protect');
        Route::post('/protect',      [PdfSuiteController::class, 'protectRun'])->name('protect.run');

        Route::get('/redact',        [PdfSuiteController::class, 'redact'])->name('redact');
        Route::post('/redact',       [PdfSuiteController::class, 'redactRun'])->name('redact.run');

        Route::get('/enhance',       [PdfSuiteController::class, 'enhance'])->name('enhance');
        Route::post('/enhance',      [PdfSuiteController::class, 'enhanceRun'])->name('enhance.run');
    });

    // Image Converter — HEIC / JPG / PNG / WEBP / BMP / TIFF / GIF → PNG / JPG / WEBP
    Route::middleware('permission:access_image_converter')->prefix('tools/image-converter')->name('tools.image_converter.')->group(function () {
        Route::get('/',  [ImageConverterController::class, 'index'])->name('index');
        Route::post('/', [ImageConverterController::class, 'run'])->name('run');
    });

    // Splitter Doc Type Admin (legacy routes — kept so PDF Splitter links still work)
    Route::get('/admin/splitter/doc-types', [\App\Http\Controllers\Admin\SplitterDocTypeController::class, 'index'])->middleware('permission:access_pdf_splitter')->name('admin.splitter.doc-types.index');
    Route::post('/admin/splitter/doc-types', [\App\Http\Controllers\Admin\SplitterDocTypeController::class, 'store'])->middleware('permission:access_pdf_splitter')->name('admin.splitter.doc-types.store');
    Route::put('/admin/splitter/doc-types/{doc_type}', [\App\Http\Controllers\Admin\SplitterDocTypeController::class, 'update'])->middleware('permission:access_pdf_splitter')->name('admin.splitter.doc-types.update');
    Route::delete('/admin/splitter/doc-types/{doc_type}', [\App\Http\Controllers\Admin\SplitterDocTypeController::class, 'destroy'])->middleware('permission:access_pdf_splitter')->name('admin.splitter.doc-types.destroy');
    Route::post('/admin/splitter/doc-types/bulk-save', [\App\Http\Controllers\Admin\SplitterDocTypeController::class, 'bulkSave'])->middleware('permission:access_pdf_splitter')->name('admin.splitter.doc-types.bulk-save');

    // Document Types Settings (unified — same controller, new URL)
    Route::get('/admin/settings/document-types', [\App\Http\Controllers\Admin\SplitterDocTypeController::class, 'index'])->middleware('permission:access_settings')->name('admin.settings.document-types.index');
    Route::post('/admin/settings/document-types', [\App\Http\Controllers\Admin\SplitterDocTypeController::class, 'store'])->middleware('permission:access_settings')->name('admin.settings.document-types.store');
    Route::post('/admin/settings/document-types/bulk-save', [\App\Http\Controllers\Admin\SplitterDocTypeController::class, 'bulkSave'])->middleware('permission:access_settings')->name('admin.settings.document-types.bulk-save');

    // WS4 (§8.1) — Deal document distribution matrix (stage × doc-type × party role).
    Route::get('/admin/settings/deal-distribution-rules', [\App\Http\Controllers\Admin\DealDistributionRuleController::class, 'index'])->middleware('permission:deals_v2.manage_distribution_rules')->name('admin.settings.deal-distribution-rules.index');
    Route::post('/admin/settings/deal-distribution-rules', [\App\Http\Controllers\Admin\DealDistributionRuleController::class, 'store'])->middleware('permission:deals_v2.manage_distribution_rules')->name('admin.settings.deal-distribution-rules.store');
    Route::delete('/admin/settings/deal-distribution-rules/{rule}', [\App\Http\Controllers\Admin\DealDistributionRuleController::class, 'destroy'])->middleware('permission:deals_v2.manage_distribution_rules')->name('admin.settings.deal-distribution-rules.destroy');

    // DR2 Wave 2 — Deal → Property → Portal status sync settings (agency-configurable).
    Route::get('/admin/settings/deal-property-sync', [\App\Http\Controllers\Admin\DealPropertySyncSettingsController::class, 'index'])->middleware('permission:access_settings')->name('admin.settings.deal-property-sync.index');
    Route::put('/admin/settings/deal-property-sync', [\App\Http\Controllers\Admin\DealPropertySyncSettingsController::class, 'update'])->middleware('permission:access_settings')->name('admin.settings.deal-property-sync.update');
    // AT-227 — Document-type distribution matrix (TYPE-level, null-stage): per type → party roles.
    // The single source of truth AT-228 send-buttons + m6 e-sign completion both consume.
    Route::get('/admin/settings/document-distribution',  [\App\Http\Controllers\Admin\DocumentDistributionMatrixController::class, 'index'])->middleware('permission:deals_v2.manage_distribution_rules')->name('admin.settings.document-distribution');
    Route::post('/admin/settings/document-distribution', [\App\Http\Controllers\Admin\DocumentDistributionMatrixController::class, 'save'])->middleware('permission:deals_v2.manage_distribution_rules')->name('admin.settings.document-distribution.save');

      // BM: My Agent Dashboard (BM's own numbers)
      Route::get('/bm/my-dashboard', [\App\Http\Controllers\BM\MyDashboardController::class, 'index'])->middleware('permission:view_performance')->name('bm.my.dashboard');


    // Agent Dashboard (agent-only)
    Route::get('/agent/dashboard', [\App\Http\Controllers\Agent\DashboardController::class, 'index'])->middleware('permission:view_dashboard')->name('agent.dashboard');

    // Agent: My Listings (from imported listing stock)
    Route::get('/agent/listings', [\App\Http\Controllers\Agent\ListingStockController::class, 'index'])->middleware('permission:view_listings')->name('agent.listings');
    Route::post('/agent/listings/{listing}/cma', [\App\Http\Controllers\Agent\ListingStockController::class, 'saveCma'])->middleware('permission:view_listings')->name('agent.listings.cma');


    Route::get('/admin/targets', [TargetController::class, 'index'])->middleware('permission:manage_targets')->name('admin.targets');
    Route::post('/admin/targets', [TargetController::class, 'save'])->middleware('permission:manage_targets')->name('admin.targets.save');
    // Monthly Goals (Company + Branch)
    Route::get('/admin/monthly-goals', [MonthlyGoalController::class, 'index'])
        ->middleware('permission:manage_targets')->name('admin.monthly-goals');

    Route::post('/admin/monthly-goals', [MonthlyGoalController::class, 'save'])
        ->middleware('permission:manage_targets')->name('admin.monthly-goals.save');


    Route::post('/admin/targets/daily', [TargetController::class, 'saveDaily'])->middleware('permission:manage_targets')->name('admin.targets.daily.save');

    // Carry forward targets from previous month (manual trigger)
    Route::post('/admin/targets/carry-forward', function () {
        \Illuminate\Support\Facades\Artisan::call('targets:carry-forward');
        $output = \Illuminate\Support\Facades\Artisan::output();
        return back()->with('status', 'Targets carried forward from previous month. ' . strip_tags(trim($output)));
    })->middleware('permission:manage_targets')->name('admin.targets.carry-forward');

    Route::get('/admin/performance', [\App\Http\Controllers\Admin\PerformanceController::class, 'index'])->middleware('permission:view_performance')->name('admin.performance');
    Route::get('/admin/branch/{branchId}/performance', [\App\Http\Controllers\Admin\BranchPerformanceController::class, 'index'])->middleware('permission:view_performance')->name('admin.branch.performance');
          Route::get('/bm/worksheet-market', [\App\Http\Controllers\BM\WorksheetMarketController::class, 'index'])
          ->middleware('permission:access_worksheet_market')->name('bm.worksheet.market');
      Route::post('/bm/worksheet-market', [\App\Http\Controllers\BM\WorksheetMarketController::class, 'save'])
          ->middleware('permission:access_worksheet_market')->name('bm.worksheet.market.save');

Route::get('/bm/performance', [\App\Http\Controllers\BM\PerformanceController::class, 'index'])->middleware('permission:view_performance')->name('bm.performance');

Route::get('/bm/listings', [\App\Http\Controllers\BM\ListingStockController::class, 'index'])->middleware('permission:access_listing_stock')->name('bm.listings');

    // ===== TV MESSAGES (Admin + BM) =====
    Route::middleware(['permission:manage_tv_messages'])->group(function () {
        Route::get('/admin/tv-messages', [\App\Http\Controllers\TvMessageController::class, 'adminIndex'])->name('admin.tv-messages');
        Route::post('/admin/tv-messages', [\App\Http\Controllers\TvMessageController::class, 'adminStore'])->name('admin.tv-messages.store');
        Route::post('/admin/tv-messages/{tvMessage}', [\App\Http\Controllers\TvMessageController::class, 'adminUpdate'])->name('admin.tv-messages.update');
        Route::post('/admin/tv-messages/{tvMessage}/delete', [\App\Http\Controllers\TvMessageController::class, 'adminDelete'])->name('admin.tv-messages.delete');
        Route::post('/admin/tv-messages/{tvMessage}/restore', [\App\Http\Controllers\TvMessageController::class, 'adminRestore'])->name('admin.tv-messages.restore')->withTrashed();

        // Admin: TV Code Management (all branches)
        Route::post('/admin/tv-code/generate', [\App\Http\Controllers\Admin\TvCodeController::class, 'generate'])->name('admin.tv-code.generate');
        Route::post('/admin/tv-code/revoke', [\App\Http\Controllers\Admin\TvCodeController::class, 'revoke'])->name('admin.tv-code.revoke');
        Route::post('/admin/tv-code/generate-company', [\App\Http\Controllers\Admin\TvCodeController::class, 'generateCompany'])->name('admin.tv-code.generate-company');
        Route::post('/admin/tv-code/revoke-company', [\App\Http\Controllers\Admin\TvCodeController::class, 'revokeCompany'])->name('admin.tv-code.revoke-company');

        // Agency switcher (super admin)
        Route::post('/agency/switch/clear', [\App\Http\Controllers\Admin\AgencySwitcherController::class, 'clear'])->middleware('owner_only')->name('agency.switch.clear');
        Route::post('/agency/switch/{agency}', [\App\Http\Controllers\Admin\AgencySwitcherController::class, 'switch'])->middleware('owner_only')->name('agency.switch');

        // Branch switcher (Split Branches Phase 2) — gated by branches.switch permission
        Route::post('/branch/switch/clear', [\App\Http\Controllers\Admin\BranchSwitcherController::class, 'clear'])->name('branch.switch.clear');
        Route::post('/branch/switch/{branch}', [\App\Http\Controllers\Admin\BranchSwitcherController::class, 'switch'])->name('branch.switch');

        // Acting-as branch manager (Admin Multi-Branch Manager — identity only,
        // does NOT change data scope). Gated by branches.self_assign_managed +
        // the controller's isManagerOfBranch() check.
        Route::post('/branch/acting/clear', [\App\Http\Controllers\Admin\ActingBranchManagerController::class, 'clear'])->name('branch.acting.clear');
        Route::post('/branch/acting/{branch}', [\App\Http\Controllers\Admin\ActingBranchManagerController::class, 'actAs'])->name('branch.acting');

        // Cross-branch deal attach/detach (Split Branches Phase 2 — spec §11)
        Route::post('/admin/deals/{deal}/branches/attach', [\App\Http\Controllers\Admin\DealBranchController::class, 'attach'])->name('admin.deals.branches.attach');
        Route::delete('/admin/deals/{deal}/branches/{branch}', [\App\Http\Controllers\Admin\DealBranchController::class, 'detach'])->name('admin.deals.branches.detach');

        // Agency select interstitial (no agency.required — that would cause a loop)
        Route::get('/agency/select', [\App\Http\Controllers\Admin\AgencySwitcherController::class, 'selectPage'])->name('agency.select');
        Route::post('/agency/select/{agency}', [\App\Http\Controllers\Admin\AgencySwitcherController::class, 'selectAndRedirect'])->name('agency.select.submit');
    });

    Route::middleware(['permission:manage_tv_messages'])->group(function () {
        Route::get('/bm/tv-messages', [\App\Http\Controllers\TvMessageController::class, 'bmIndex'])->name('bm.tv-messages');
        Route::post('/bm/tv-messages', [\App\Http\Controllers\TvMessageController::class, 'bmStore'])->name('bm.tv-messages.store');
        Route::post('/bm/tv-messages/{tvMessage}', [\App\Http\Controllers\TvMessageController::class, 'bmUpdate'])->name('bm.tv-messages.update');
        Route::post('/bm/tv-messages/{tvMessage}/delete', [\App\Http\Controllers\TvMessageController::class, 'bmDelete'])->name('bm.tv-messages.delete');
    });


    Route::post('/bm/performance', [\App\Http\Controllers\BM\PerformanceController::class, 'save'])->middleware('permission:manage_targets')->name('bm.performance.save');

    Route::get('/bm/agent/{userId}/performance', [\App\Http\Controllers\BM\AgentPerformanceController::class, 'show'])->middleware('permission:view_performance')->name('bm.agent.performance');
    Route::get('/admin/agent/{userId}/performance', [\App\Http\Controllers\Admin\AgentPerformanceController::class, 'show'])->middleware('permission:view_performance')->name('admin.agent.performance');

    // Agent Daily Activity (agent menu link)
      // Agent Daily Activity (locked to agents only)
      Route::get('/agent/daily', [\App\Http\Controllers\Agent\DailyActivityController::class, 'index'])->middleware('permission:access_daily_activity')->name('agent.daily');
Route::get('/agent/daily/summary', [\App\Http\Controllers\Agent\DailyActivitySummaryController::class, 'index'])->middleware('permission:view_daily_activity')->name('agent.daily.summary');
Route::get('/agent/daily/summary/activity/{definition}', [\App\Http\Controllers\Agent\DailyActivitySummaryController::class, 'activity'])->middleware('permission:view_daily_activity')->name('agent.daily.summary.activity');


Route::get('/bm/daily/summary', [\App\Http\Controllers\BM\DailyActivitySummaryController::class, 'index'])->middleware('permission:view_daily_activity')->name('bm.daily.summary');
Route::get('/bm/daily/summary/activity/{definition}', [\App\Http\Controllers\BM\DailyActivitySummaryController::class, 'activity'])->middleware('permission:view_daily_activity')->name('bm.daily.summary.activity');
Route::get('/bm/daily/summary/activity/{definition}/agent/{user}', [\App\Http\Controllers\BM\DailyActivitySummaryController::class, 'agent'])->middleware('permission:view_daily_activity')->name('bm.daily.summary.activity.agent');

Route::get('/admin/daily/summary', [\App\Http\Controllers\Admin\DailyActivitySummaryController::class, 'index'])->middleware('permission:view_daily_activity')->name('admin.daily.summary');
Route::get('/admin/daily/summary/activity/{definition}', [\App\Http\Controllers\Admin\DailyActivitySummaryController::class, 'activity'])->middleware('permission:view_daily_activity')->name('admin.daily.summary.activity');
Route::get('/admin/daily/summary/activity/{definition}/branch/{branch}', [\App\Http\Controllers\Admin\DailyActivitySummaryController::class, 'branch'])->middleware('permission:view_daily_activity')->name('admin.daily.summary.activity.branch');
Route::get('/admin/daily/summary/activity/{definition}/branch/{branch}/agent/{user}', [\App\Http\Controllers\Admin\DailyActivitySummaryController::class, 'agent'])->middleware('permission:view_daily_activity')->name('admin.daily.summary.activity.branch.agent');


Route::get('/agent/daily/print', [\App\Http\Controllers\Agent\DailyActivityController::class, 'printSheet'])
    ->middleware('permission:access_daily_activity')->name('agent.daily.print');
        Route::post('/agent/daily', [\App\Http\Controllers\Agent\DailyActivityController::class, 'store'])->middleware('permission:access_daily_activity');
Route::get('/admin/targets/activity-setup', function () {
    return redirect()->route('admin.targets.activity.definitions');
})->name('admin.targets.activity.setup')->middleware('permission:manage_targets');
    Route::post('/admin/targets/activity-setup', [TargetController::class, 'activitySetupSave'])->name('admin.targets.activity.setup.save')->middleware('permission:manage_targets');
Route::get('/admin/targets/activity-definitions', [TargetController::class, 'activityDefinitions'])->name('admin.targets.activity.definitions')->middleware('permission:manage_targets');
    Route::post('/admin/targets/activity-definitions', [TargetController::class, 'activityDefinitionsSave'])->name('admin.targets.activity.definitions.save')->middleware('permission:manage_targets');


      Route::post('/admin/targets/activity-columns', [TargetController::class, 'activityColumnCreate'])->name('admin.targets.activity.columns.create')->middleware('permission:manage_targets');
});


Route::post('bm/performance/set-agent-targets', [\App\Http\Controllers\BM\PerformanceController::class, 'setAgentTargets'])
    ->middleware(['auth', 'permission:manage_targets'])->name('bm.performance.setAgentTargets');

// --- BM: TV Code Management ---
Route::post('/bm/tv-code/generate', [\App\Http\Controllers\BM\TvCodeController::class, 'generate'])
    ->middleware(['auth', 'permission:manage_tv_messages'])->name('bm.tv-code.generate');
Route::post('/bm/tv-code/revoke', [\App\Http\Controllers\BM\TvCodeController::class, 'revoke'])
    ->middleware(['auth', 'permission:manage_tv_messages'])->name('bm.tv-code.revoke');

Route::post('bm/performance/align-agent-to-company', [\App\Http\Controllers\BM\PerformanceController::class, 'alignAgentToCompany'])
    ->middleware(['auth', 'permission:manage_targets'])->name('bm.performance.alignAgentToCompany');

Route::post('bm/performance/align-targets', [\App\Http\Controllers\BM\PerformanceController::class, 'alignTargets'])->middleware(['auth', 'permission:manage_targets'])->name('bm.performance.align');

// --- TV (no login, token-protected — legacy) ---
Route::get('/tv/branch/{branchId}', [\App\Http\Controllers\TV\BranchTvController::class, 'show'])
    ->middleware('tv')
    ->name('tv.branch');

// --- TV (code-based auth — new) ---
Route::get('/tv', [\App\Http\Controllers\TV\TvController::class, 'index'])->name('tv.index');
Route::post('/tv/verify', [\App\Http\Controllers\TV\TvController::class, 'verify'])->name('tv.verify');
Route::get('/tv/display/{code}', [\App\Http\Controllers\TV\TvController::class, 'display'])->name('tv.display');


Route::post('/worksheet/align-company-target', [\App\Http\Controllers\WorksheetController::class, 'alignToCompany'])
    ->name('worksheet.align');

Route::post('/worksheet/apply-branch-default', [\App\Http\Controllers\WorksheetController::class, 'applyBranchDefault'])->name('worksheet.applyBranchDefault');



// Admin: Performance Settings
Route::middleware(['auth', 'permission:manage_performance_settings'])->group(function () {
    Route::get('/admin/performance-settings', [\App\Http\Controllers\Admin\PerformanceSettingsController::class, 'edit'])
        ->name('admin.performance-settings.edit');

    Route::post('/admin/performance-settings', [\App\Http\Controllers\Admin\PerformanceSettingsController::class, 'update'])
        ->name('admin.performance-settings.update');
});

// Admin: P24 Suburb Mappings
Route::middleware(['auth', 'permission:manage_p24'])->group(function () {
    Route::get('/settings/p24-suburbs', [\App\Http\Controllers\Admin\P24SuburbController::class, 'index'])
        ->name('admin.p24-suburbs.index');
    Route::post('/settings/p24-suburbs', [\App\Http\Controllers\Admin\P24SuburbController::class, 'store'])
        ->name('admin.p24-suburbs.store');
    Route::put('/settings/p24-suburbs/{p24Suburb}', [\App\Http\Controllers\Admin\P24SuburbController::class, 'update'])
        ->whereNumber('p24Suburb')->name('admin.p24-suburbs.update');
    Route::delete('/settings/p24-suburbs/{p24Suburb}', [\App\Http\Controllers\Admin\P24SuburbController::class, 'destroy'])
        ->whereNumber('p24Suburb')->name('admin.p24-suburbs.destroy');
    // AT-246 — town-level region assignment (applies to all a town's suburbs) + region display alias.
    Route::put('/settings/p24-suburbs/town/{townId}/region', [\App\Http\Controllers\Admin\P24SuburbController::class, 'saveTownRegion'])
        ->whereNumber('townId')->name('admin.p24-suburbs.town-region');
    // AT-246 — assign region by P24 CITY (find-or-create the agency town) so EVERY
    // suburb is assignable even before an agency towns row exists — no dead-end rows.
    Route::put('/settings/p24-suburbs/city/{cityId}/region', [\App\Http\Controllers\Admin\P24SuburbController::class, 'saveCityRegion'])
        ->whereNumber('cityId')->name('admin.p24-suburbs.city-region');
    Route::put('/settings/p24-suburbs/alias/{municipality}', [\App\Http\Controllers\Admin\P24SuburbController::class, 'saveAlias'])
        ->where('municipality', '.*')->name('admin.p24-suburbs.alias');
});




// Admin: Designations (dropdown list management)
Route::middleware(['auth','verified','permission:manage_designations'])->group(function () {
    Route::get('/admin/designations', [\App\Http\Controllers\Admin\DesignationController::class, 'index'])
        ->name('admin.designations.index');
    Route::post('/admin/designations', [\App\Http\Controllers\Admin\DesignationController::class, 'store'])
        ->name('admin.designations.store');
    Route::post('/admin/designations/{designation}', [\App\Http\Controllers\Admin\DesignationController::class, 'update'])
        ->name('admin.designations.update');
    Route::post('/admin/designations/{designation}/delete', [\App\Http\Controllers\Admin\DesignationController::class, 'delete'])
        ->name('admin.designations.delete');
});

// ===== FINANCE ENGINE & AUDIT (Admin only) =====
Route::middleware(['auth','verified','permission:access_finance_engine'])->group(function () {
    Route::get('/admin/finance/definitions', [\App\Http\Controllers\Admin\FinanceAuditController::class, 'definitions'])
        ->name('admin.finance.definitions');
    Route::get('/admin/finance/audit', [\App\Http\Controllers\Admin\FinanceAuditController::class, 'index'])
        ->name('admin.finance.audit.index');
    Route::get('/admin/finance/audit/runs/{run}', [\App\Http\Controllers\Admin\FinanceAuditController::class, 'run'])
        ->name('admin.finance.audit.run');
    Route::get('/admin/finance/audit/deals/{deal}', [\App\Http\Controllers\Admin\FinanceAuditController::class, 'deal'])
        ->name('admin.finance.audit.deal');
    Route::post('/admin/finance/recalculate', [\App\Http\Controllers\Admin\FinanceAuditController::class, 'recalculate'])
        ->name('admin.finance.recalculate');
});


    // ---- Admin: Worksheet Market (per-branch / per-agent market inputs) ----
    Route::get('/admin/worksheet-market', [\App\Http\Controllers\Admin\WorksheetMarketController::class, 'index'])
        ->middleware(['auth','verified','permission:edit_worksheet'])->name('admin.worksheet-market');
    Route::post('/admin/worksheet-market', [\App\Http\Controllers\Admin\WorksheetMarketController::class, 'store'])
        ->middleware(['auth','verified','permission:edit_worksheet'])->name('admin.worksheet-market.store');

/*
|--------------------------------------------------------------------------
| Rentals
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'permission:view_rentals'])->group(function () {

    Route::get('/rentals', [\App\Http\Controllers\RentalsController::class, 'index'])
        ->name('rentals.index');

    Route::get('/rentals/create', [\App\Http\Controllers\RentalsController::class, 'create'])
        ->name('rentals.create');

    Route::get('/rentals/{id}/edit', [\App\Http\Controllers\RentalsController::class, 'edit'])
        ->name('rentals.edit');

    Route::post('/rentals', [\App\Http\Controllers\RentalsController::class, 'store'])
        ->name('rentals.store');

    Route::post('/rentals/{id}', [\App\Http\Controllers\RentalsController::class, 'update'])
        ->whereNumber('id')
        ->name('rentals.update');


});

/*
|--------------------------------------------------------------------------
| Rental Permissions
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'permission:manage_rentals'])->group(function () {

    Route::get('/rentals/permissions', [\App\Http\Controllers\RentalPermissionsController::class, 'index'])
        ->name('rentals.permissions');

    Route::post('/rentals/permissions', [\App\Http\Controllers\RentalPermissionsController::class, 'update'])
        ->name('rentals.permissions.update');

});

// Internal: Nginx auth_request gate for /ai (returns 200 or 401, no redirects)
Route::get('/internal/ai-auth-check', [\App\Http\Controllers\Internal\AiAuthController::class, 'check'])
    ->name('internal.ai-auth-check');

Route::post('/internal/ai-chat-proxy', [\App\Http\Controllers\Internal\AiChatProxyController::class, 'chat'])
    ->middleware('auth')
    ->name('internal.ai-chat-proxy');

Route::get('/ai-buddy', fn() => redirect()->route('ellie.index'))->middleware('auth')->name('ai.buddy');

// ===== DOCUMENT FILING REGISTER =====
Route::middleware(['auth', 'permission:access_filing_register'])->group(function () {
    Route::get('/filing-register', [\App\Http\Controllers\DocumentFilingController::class, 'index'])->name('filing-register.index');
    Route::post('/filing-register', [\App\Http\Controllers\DocumentFilingController::class, 'store'])->name('filing-register.store');
    Route::put('/filing-register/{id}', [\App\Http\Controllers\DocumentFilingController::class, 'update'])->name('filing-register.update');
    Route::delete('/filing-register/{id}', [\App\Http\Controllers\DocumentFilingController::class, 'destroy'])->name('filing-register.destroy');
    Route::post('/filing-register/{filing}/restore', [\App\Http\Controllers\DocumentFilingController::class, 'restore'])->name('filing-register.restore')->withTrashed();

    // AT-238 — the filing register's OWN pickers. Deliberately NOT the DR2 endpoints:
    // those are gated on `create_deals`, so a filing clerk 403s, and widening that
    // permission to serve a filing screen would hand deal-capture rights to anyone who
    // files paper. Same canonical primitives underneath, own gate.
    Route::get('/filing-register/search/properties', [\App\Http\Controllers\DocumentFilingController::class, 'searchProperties'])->name('filing-register.search.properties');
    Route::get('/filing-register/search/property/{property}/suggestions', [\App\Http\Controllers\DocumentFilingController::class, 'propertySuggestions'])->name('filing-register.search.property-suggestions');
});

// ===== NEXUS OS ROUTES =====
use App\Http\Controllers\CoreX\PlaceholderController as CoreXPlaceholderController;
use App\Http\Controllers\CommandCenter\DashboardController as CommandCenterDashboardController;
use App\Http\Controllers\CommandCenter\CalendarController as CommandCenterCalendarController;
use App\Http\Controllers\CommandCenter\TaskController as CommandCenterTaskController;
use App\Http\Controllers\CommandCenter\SettingsController as CommandCenterSettingsController;
use App\Http\Controllers\CommandCenter\ContactGovernanceController as CommandCenterContactGovernanceController;
use App\Http\Controllers\CommandCenter\UserSettingsController as CommandCenterUserSettingsController;
use App\Http\Controllers\CoreX\SettingsController as CoreXSettingsController;
use App\Http\Controllers\CoreX\RoleManagerController as CoreXRoleManagerController;

Route::middleware(['auth', 'verified'])->prefix('corex')->group(function () {
    Route::get('/', [CommandCenterDashboardController::class, 'today'])->name('corex.dashboard');
    Route::get('/command-center/Today', [CommandCenterDashboardController::class, 'today'])->name('command-center.today');
    Route::get('/command-center/Today/cards', [CommandCenterDashboardController::class, 'todayCards'])->name('command-center.today.cards');
    Route::get('/legacy-dashboard', [CommandCenterDashboardController::class, 'index'])->middleware('permission:view_dashboard')->name('corex.dashboard.legacy');

    // ── Overdue & Unresolved (Today card drill-down) ──
    Route::get('/overdue', [CommandCenterDashboardController::class, 'overdue'])->name('command-center.overdue');

    // ── Notifications ──
    Route::get('/notifications', function () {
        $notifications = \Illuminate\Support\Facades\DB::table('notifications')
            ->where('notifiable_type', 'App\\Models\\User')
            ->where('notifiable_id', auth()->id())
            ->orderByDesc('created_at')
            ->paginate(30);
        return view('command-center.notifications', ['notifications' => $notifications]);
    })->name('command-center.notifications');
    Route::post('/notifications/mark-all-read', function () {
        \Illuminate\Support\Facades\DB::table('notifications')
            ->where('notifiable_type', 'App\\Models\\User')
            ->where('notifiable_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        return back()->with('success', 'All notifications marked as read.');
    })->name('command-center.notifications.mark-all-read');
    Route::post('/notifications/{id}/mark-read', function (string $id) {
        $updated = \Illuminate\Support\Facades\DB::table('notifications')
            ->where('id', $id)
            ->where('notifiable_type', 'App\\Models\\User')
            ->where('notifiable_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        if (!$updated) abort(403);
        if (request()->wantsJson()) {
            return response()->json(['ok' => true]);
        }
        return back()->with('success', 'Notification marked as read.');
    })->name('command-center.notifications.mark-read');

    // ── Manager Oversight ──
    Route::middleware('permission:dashboard.oversight.view')->group(function () {
        Route::get('/dashboard/oversight', [\App\Http\Controllers\CoreX\Dashboard\OversightController::class, 'index'])->name('corex.dashboard.oversight');
        Route::get('/settings/user/oversight', [\App\Http\Controllers\CoreX\Dashboard\OversightController::class, 'settings'])->name('corex.settings.user.oversight');
        Route::post('/settings/user/oversight', [\App\Http\Controllers\CoreX\Dashboard\OversightController::class, 'saveSettings'])->name('corex.settings.user.oversight.save');
    });
    Route::middleware('permission:dashboard.oversight.manage')->group(function () {
        Route::post('/dashboard/oversight/nudge', [\App\Http\Controllers\CoreX\Dashboard\OversightController::class, 'nudge'])->name('corex.dashboard.oversight.nudge');
    });

    // ── Command Center ──
    // Lightweight contact lookup for calendar prefill (no agency.required middleware)
    Route::get('/api/contact-lookup/{id}', function (int $id) {
        $contact = \App\Models\Contact::withoutGlobalScopes()->find($id);
        if (!$contact) return response()->json(['error' => 'Not found'], 404);
        return response()->json([
            'id' => $contact->id,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'phone' => $contact->phone,
            'email' => $contact->email,
            'is_buyer' => $contact->is_buyer,
        ]);
    })->name('corex.api.contact-lookup');

    Route::prefix('command-center')->group(function () {
        Route::get('/calendar', [CommandCenterCalendarController::class, 'index'])->middleware('permission:command_center.calendar.view')->name('command-center.calendar');
        Route::get('/calendar/events', [CommandCenterCalendarController::class, 'events'])->middleware('permission:command_center.calendar.view')->name('command-center.calendar.events');

        // Calendar Invitations — MUST be before /calendar/{calendarEvent} wildcard
        Route::get('/calendar/invitations', function () {
            $userId = auth()->id();
            $invitations = \App\Models\CommandCenter\CalendarEventInvitation::forUser($userId)
                ->with([
                    'event' => fn($q) => $q->withoutGlobalScopes(),
                    'inviter',
                ])
                ->whereIn('status', ['pending', 'tentative'])
                ->orderByDesc('created_at')->paginate(20);

            // Live conflict check for each invitation
            $conflictSvc = app(\App\Services\CommandCenter\Calendar\ConflictDetectionService::class);
            foreach ($invitations as $inv) {
                $inv->live_conflicts = [];
                if ($inv->event && $inv->event->event_date && $inv->event->end_date) {
                    try {
                        $inv->live_conflicts = $conflictSvc->checkUserConflicts(
                            $userId,
                            $inv->event->event_date->toIso8601String(),
                            $inv->event->end_date->toIso8601String(),
                            $inv->event_id // exclude this invitation's own event
                        );
                    } catch (\Throwable $e) {}
                }
            }

            return view('command-center.calendar.invitations', ['invitations' => $invitations]);
        })->name('command-center.calendar.invitations');
        Route::post('/calendar/invitations/{invitation}/respond', function (\Illuminate\Http\Request $request, \App\Models\CommandCenter\CalendarEventInvitation $invitation) {
            if ((int) $invitation->invitee_user_id !== auth()->id()) abort(403);
            $data = $request->validate(['action' => 'required|in:accepted,tentative,declined', 'notes' => 'nullable|string|max:500']);
            $invitation->update(['status' => $data['action'], 'response_at' => now(), 'response_notes' => $data['notes'] ?? null]);
            \Illuminate\Support\Facades\DB::table('notifications')->insert([
                'id' => \Illuminate\Support\Str::uuid(), 'type' => 'invitation_response', 'notifiable_type' => 'App\\Models\\User',
                'notifiable_id' => $invitation->inviter_user_id,
                'data' => json_encode(['message' => auth()->user()->name . ' ' . $data['action'] . ': ' . ($invitation->event?->title ?? 'Event'), 'event_id' => $invitation->event_id]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return back()->with('success', 'Response recorded.');
        })->name('command-center.calendar.invitations.respond');

        Route::post('/calendar/invitations/{invitation}/acknowledge', function (\Illuminate\Http\Request $request, \App\Models\CommandCenter\CalendarEventInvitation $invitation) {
            $event = $invitation->event;
            if (!$event) abort(404);
            $user = auth()->user();
            // Only organizer or super_admin can acknowledge
            if ((int) $event->user_id !== (int) $user->id && !in_array($user->role, ['super_admin', 'owner'])) {
                abort(403);
            }
            $invitation->update(['acknowledged_at' => now()]);
            return response()->json(['ok' => true, 'invitation_id' => $invitation->id, 'acknowledged_at' => $invitation->fresh()->acknowledged_at->toIso8601String()]);
        })->name('command-center.calendar.invitations.acknowledge');

        // AT-164 Gate 5 — continuous-scroll month window (HTML block) + JSON range endpoint
        Route::get('/calendar/month-block', [CommandCenterCalendarController::class, 'monthBlock'])->middleware('permission:command_center.calendar.view')->name('command-center.calendar.month-block');
        Route::get('/calendar/week-rows', [CommandCenterCalendarController::class, 'weekRows'])->middleware('permission:command_center.calendar.view')->name('command-center.calendar.week-rows'); // AT-164 single week-stream
        Route::get('/calendar/grid-range', [CommandCenterCalendarController::class, 'gridRange'])->middleware('permission:command_center.calendar.view')->name('command-center.calendar.grid-range');
        Route::get('/calendar/day-columns', [CommandCenterCalendarController::class, 'dayColumns'])->middleware('permission:command_center.calendar.view')->name('command-center.calendar.day-columns');

        // AT-164 Gate 4 — Tile Deck (JSON) — MUST be before /calendar/{calendarEvent} wildcard
        Route::get('/calendar/deck', [CommandCenterCalendarController::class, 'deck'])->middleware('permission:command_center.calendar.view')->name('command-center.calendar.deck');
        Route::post('/calendar/deck', [CommandCenterCalendarController::class, 'saveDeck'])->middleware('permission:command_center.calendar.view')->name('command-center.calendar.deck.save');
        Route::post('/calendar/deck/reset', [CommandCenterCalendarController::class, 'resetDeck'])->middleware('permission:command_center.calendar.view')->name('command-center.calendar.deck.reset');
        // AT-164 explicit-save — build ONE tile without persisting (in-session deck add)
        Route::get('/calendar/tile/{tileId}', [CommandCenterCalendarController::class, 'tile'])->middleware('permission:command_center.calendar.view')->where('tileId', '[A-Za-z0-9_\-]+')->name('command-center.calendar.tile');

        // AT-164 Gate 6 — persist the user's active layer toggles
        Route::post('/calendar/layers', [CommandCenterCalendarController::class, 'saveLayers'])->middleware('permission:command_center.calendar.view')->name('command-center.calendar.layers.save');

        // AT-164 cockpit v2 — persist / reset the per-user cockpit arrangement
        Route::post('/calendar/cockpit', [CommandCenterCalendarController::class, 'saveCockpit'])->middleware('permission:command_center.calendar.view')->name('command-center.calendar.cockpit.save');
        Route::post('/calendar/cockpit/reset', [CommandCenterCalendarController::class, 'resetCockpit'])->middleware('permission:command_center.calendar.view')->name('command-center.calendar.cockpit.reset');

        // Conflict check — MUST be before /calendar/{calendarEvent} wildcard
        Route::get('/calendar/check-conflicts', function (\Illuminate\Http\Request $request) {
            $svc = app(\App\Services\CommandCenter\Calendar\ConflictDetectionService::class);
            $conflicts = $svc->checkUserConflicts(
                (int) $request->get('user_id'),
                $request->get('start'),
                $request->get('end'),
                $request->get('exclude_event_id'),
            );
            // Shape { has_conflict, conflicts } — BOTH the attendee check and the
            // organizer self-check read `data.has_conflict` / `data.conflicts`.
            // The endpoint previously returned the raw array, so `has_conflict`
            // was always undefined and the attendee ⚠ never fired.
            return response()->json(['has_conflict' => !empty($conflicts), 'conflicts' => $conflicts]);
        })->name('command-center.calendar.check-conflicts');

        Route::get('/calendar/{calendarEvent}', [CommandCenterCalendarController::class, 'show'])->name('command-center.calendar.show');
        Route::post('/calendar', [CommandCenterCalendarController::class, 'store'])->name('command-center.calendar.store');
        Route::put('/calendar/{calendarEvent}', [CommandCenterCalendarController::class, 'update'])->name('command-center.calendar.update');
        Route::delete('/calendar/{calendarEvent}', [CommandCenterCalendarController::class, 'destroy'])->name('command-center.calendar.destroy');
        Route::post('/calendar/{calendarEvent}/complete', [CommandCenterCalendarController::class, 'complete'])->name('command-center.calendar.complete');
        Route::post('/calendar/{calendarEvent}/dismiss', [CommandCenterCalendarController::class, 'dismiss'])->name('command-center.calendar.dismiss');
        Route::patch('/calendar/{calendarEvent}/reschedule', [CommandCenterCalendarController::class, 'reschedule'])->name('command-center.calendar.reschedule');
        Route::get('/calendar/{calendarEvent}/feedback', [CommandCenterCalendarController::class, 'showFeedback'])->name('command-center.calendar.feedback.show');
        Route::post('/calendar/{calendarEvent}/feedback', [CommandCenterCalendarController::class, 'storeFeedback'])->name('command-center.calendar.feedback.store');
        Route::get('/calendar/search/attendees', [CommandCenterCalendarController::class, 'searchAttendees'])->name('command-center.calendar.search.attendees');
        Route::get('/calendar/properties/{property}/owners', [CommandCenterCalendarController::class, 'propertyOwners'])->name('command-center.calendar.property-owners');

        Route::post('/resolve-task/{task}', [CommandCenterDashboardController::class, 'resolveTask'])->name('command-center.resolve-task');
        Route::post('/resolve-event/{calendarEvent}', [CommandCenterDashboardController::class, 'resolveEvent'])->name('command-center.resolve-event');

        Route::get('/performance', [CommandCenterDashboardController::class, 'performance'])->middleware('permission:view_dashboard')->name('command-center.performance');

        Route::get('/tasks', [CommandCenterTaskController::class, 'index'])->middleware('permission:command_center.tasks.view')->name('command-center.tasks');
        Route::get('/tasks/archived', [CommandCenterTaskController::class, 'archived'])->middleware('permission:command_center.tasks.view')->name('command-center.tasks.archived');
        Route::post('/tasks/archive-done', [CommandCenterTaskController::class, 'archiveDone'])->name('command-center.tasks.archive-done');
        Route::post('/tasks/{taskId}/restore', [CommandCenterTaskController::class, 'restore'])->name('command-center.tasks.restore');
        Route::post('/tasks', [CommandCenterTaskController::class, 'store'])->name('command-center.tasks.store');
        Route::put('/tasks/{task}', [CommandCenterTaskController::class, 'update'])->name('command-center.tasks.update');
        Route::delete('/tasks/{task}', [CommandCenterTaskController::class, 'destroy'])->name('command-center.tasks.destroy');
        Route::post('/tasks/{task}/complete', [CommandCenterTaskController::class, 'complete'])->name('command-center.tasks.complete');
        Route::patch('/tasks/{task}/status', [CommandCenterTaskController::class, 'updateStatus'])->name('command-center.tasks.update-status');

        // Buyer Portal Links — agent management
        Route::post('/buyers/portal-links/generate', function (\Illuminate\Http\Request $request) {
            $request->validate(['contact_id' => 'required|integer']);
            // Resolve the contact through its global scopes (agency + branch), so a
            // contact outside the caller's agency/branch 404s here instead of
            // minting a public portal link that leaks another tenant's buyer.
            // findOrFail is what enforces isolation — do NOT use withoutGlobalScopes.
            $contact  = \App\Models\Contact::findOrFail($request->integer('contact_id'));
            $agencyId = $contact->agency_id;
            // Revoke existing active links (scoped to this contact, now proven ours).
            \Illuminate\Support\Facades\DB::table('buyer_portal_links')->where('contact_id', $contact->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_by_user_id' => auth()->id()]);
            $token = bin2hex(random_bytes(32));
            \Illuminate\Support\Facades\DB::table('buyer_portal_links')->insert([
                'contact_id' => $contact->id, 'agency_id' => $agencyId, 'token' => $token,
                'generated_by_user_id' => auth()->id(), 'generated_at' => now(),
                'access_count' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return back()->with('success', 'Buyer portal link generated.')->with('buyer_portal_url', url('/buyer/portal/' . $token));
        })->name('command-center.buyers.portal-links.generate');

        Route::post('/buyers/portal-links/{id}/revoke', function (int $id) {
            // Scope the revoke to the caller's agency — buyer_portal_links has no
            // model/global scope, so a raw id would otherwise let one agency revoke
            // another's links. abort 404 when the id isn't ours.
            $agencyId = auth()->user()?->effectiveAgencyId();
            abort_unless($agencyId, 403);
            $updated = \Illuminate\Support\Facades\DB::table('buyer_portal_links')
                ->where('id', $id)->where('agency_id', $agencyId)
                ->update(['revoked_at' => now(), 'revoked_by_user_id' => auth()->id()]);
            abort_if($updated === 0, 404);
            return back()->with('success', 'Buyer portal link revoked.');
        })->name('command-center.buyers.portal-links.revoke');

        // Feedback Reports
        Route::post('/feedback', [\App\Http\Controllers\FeedbackReportController::class, 'store'])->name('command-center.feedback.store');
        Route::get('/feedback-reports', [\App\Http\Controllers\FeedbackReportController::class, 'index'])->middleware('permission:command_center.settings')->name('command-center.feedback-reports');
        Route::get('/feedback-reports/export', [\App\Http\Controllers\FeedbackReportController::class, 'export'])->middleware('permission:command_center.settings')->name('command-center.feedback-reports.export');
        Route::get('/feedback-reports/{id}', [\App\Http\Controllers\FeedbackReportController::class, 'show'])->middleware('permission:command_center.settings')->name('command-center.feedback-reports.show');
        Route::post('/feedback-reports/{id}/status', [\App\Http\Controllers\FeedbackReportController::class, 'updateStatus'])->middleware('permission:command_center.settings')->name('command-center.feedback-reports.update-status');

        Route::get('/reporting/agent', [\App\Http\Controllers\CommandCenter\ReportingController::class, 'agentDashboard'])->name('command-center.reporting.agent');
        Route::get('/reporting/branch', [\App\Http\Controllers\CommandCenter\ReportingController::class, 'branchDashboard'])->middleware('permission:dashboard.oversight.view')->name('command-center.reporting.branch');
        Route::get('/reporting/agency', [\App\Http\Controllers\CommandCenter\ReportingController::class, 'agencyDashboard'])->name('command-center.reporting.agency');

        Route::get('/buyers/pipeline', [\App\Http\Controllers\CommandCenter\BuyerPipelineController::class, 'index'])->name('command-center.buyers.pipeline');
        Route::get('/buyers/{contact}', [\App\Http\Controllers\CommandCenter\BuyerDetailController::class, 'show'])->name('command-center.buyers.show');
        // Backward-compat alias for the legacy preferences POST. Prompt 11 added
        // explicit add/update endpoints below; the new Wishlists tab uses those.
        Route::post('/buyers/{contact}/preferences', [\App\Http\Controllers\CommandCenter\BuyerDetailController::class, 'saveWishlist'])->name('command-center.buyers.preferences');
        // Prompt 11 — Wishlists tab CRUD.
        Route::post('/buyers/{contact}/wishlists', [\App\Http\Controllers\CommandCenter\BuyerDetailController::class, 'addWishlist'])->name('command-center.buyers.wishlists.add');
        Route::put('/buyers/{contact}/wishlists/{match}', [\App\Http\Controllers\CommandCenter\BuyerDetailController::class, 'updateWishlist'])->name('command-center.buyers.wishlists.update');
        Route::post('/buyers/{contact}/wishlists/{match}/primary', [\App\Http\Controllers\CommandCenter\BuyerDetailController::class, 'setWishlistPrimary'])->name('command-center.buyers.wishlists.primary');
        Route::post('/buyers/{contact}/wishlists/{match}/archive', [\App\Http\Controllers\CommandCenter\BuyerDetailController::class, 'archiveWishlist'])->name('command-center.buyers.wishlists.archive');

        Route::post('/buyers/{contact}/playbook-action', [\App\Http\Controllers\CommandCenter\BuyerDetailController::class, 'markPlaybookAction'])->name('command-center.buyers.playbook-action');
        Route::post('/buyers/{contact}/mark-lost', [\App\Http\Controllers\CommandCenter\BuyerDetailController::class, 'markLost'])->name('command-center.buyers.mark-lost');
        Route::post('/buyers/{contact}/reengage', [\App\Http\Controllers\CommandCenter\BuyerDetailController::class, 'reengage'])->name('command-center.buyers.reengage');

        Route::get('/lost-deals', function (\Illuminate\Http\Request $request) {
            $agencyId = auth()->user()->effectiveAgencyId() ?? 1;
            $days = (int) $request->get('days', 90);
            $analytics = app(\App\Services\LostDealAnalyticsService::class);
            return view('command-center.lost-deals', [
                'days' => $days,
                'distribution' => $analytics->getReasonDistribution($agencyId, $days),
                'valueData' => $analytics->getValueAtLoss($agencyId, $days),
            ]);
        })->middleware('permission:command_center.settings')->name('command-center.lost-deals');
        Route::patch('/buyers/{contact}/state', [\App\Http\Controllers\CommandCenter\BuyerPipelineController::class, 'updateState'])->name('command-center.buyers.update-state');

        Route::get('/admin/duplicate-cleanup', [\App\Http\Controllers\CommandCenter\DuplicateCleanupController::class, 'index'])->middleware('permission:command_center.settings')->name('command-center.admin.duplicate-cleanup');
        Route::post('/admin/duplicate-cleanup/{clusterId}/dismiss', [\App\Http\Controllers\CommandCenter\DuplicateCleanupController::class, 'dismiss'])->middleware('permission:command_center.settings')->name('command-center.admin.duplicate-cleanup.dismiss');

        Route::get('/settings', [CommandCenterSettingsController::class, 'index'])->name('command-center.settings');
        Route::get('/settings/contact-governance', [CommandCenterContactGovernanceController::class, 'contactGovernance'])->middleware('permission:command_center.settings')->name('command-center.settings.contact-governance');
        Route::put('/settings/contact-governance', [CommandCenterContactGovernanceController::class, 'updateContactGovernance'])->middleware('permission:command_center.settings')->name('command-center.settings.contact-governance.update');
        Route::put('/settings/leave-visibility', [CommandCenterContactGovernanceController::class, 'updateLeaveVisibility'])->middleware('permission:command_center.settings')->name('command-center.settings.leave-visibility.update');
        Route::patch('/settings/rules/{rule}/toggle', [CommandCenterSettingsController::class, 'toggleRule'])->name('command-center.settings.toggle-rule');
        Route::post('/settings/expectations', [CommandCenterSettingsController::class, 'storeExpectation'])->name('command-center.settings.store-expectation');
        Route::delete('/settings/expectations/{expectation}', [CommandCenterSettingsController::class, 'destroyExpectation'])->name('command-center.settings.destroy-expectation');
        Route::get('/settings/event-classes', [CommandCenterSettingsController::class, 'eventClasses'])->name('command-center.settings.event-classes');
        Route::put('/settings/event-classes/{eventClass}', [CommandCenterSettingsController::class, 'updateEventClass'])->name('command-center.settings.event-classes.update');
        Route::delete('/settings/event-classes/{eventClass}', [CommandCenterSettingsController::class, 'resetEventClass'])->name('command-center.settings.event-classes.reset');

        Route::get('/user-settings', [CommandCenterUserSettingsController::class, 'index'])->name('command-center.user-settings');
        Route::put('/user-settings', [CommandCenterUserSettingsController::class, 'update'])->name('command-center.user-settings.update');
    });

    // ── Viewing Packs (AT-XX) — buyer-facing pack CRUD. Tenancy via AgencyScope
    //    on the model; {viewingPack} 404s across agencies. Archive = soft delete. ──
    Route::prefix('viewing-packs')->name('corex.viewing-packs.')->group(function () {
        Route::get('/', [\App\Http\Controllers\CommandCenter\ViewingPackController::class, 'index'])->name('index');
        Route::post('/', [\App\Http\Controllers\CommandCenter\ViewingPackController::class, 'store'])->name('store');
        Route::get('/{viewingPack}', [\App\Http\Controllers\CommandCenter\ViewingPackController::class, 'show'])->name('show');
        Route::put('/{viewingPack}', [\App\Http\Controllers\CommandCenter\ViewingPackController::class, 'update'])->name('update');
        Route::delete('/{viewingPack}', [\App\Http\Controllers\CommandCenter\ViewingPackController::class, 'destroy'])->name('destroy');
        Route::post('/{viewingPack}/restore', [\App\Http\Controllers\CommandCenter\ViewingPackController::class, 'restore'])->name('restore')->withTrashed();
        // AT-XX — scheduling reuses the calendar prefill handoff (link built in
        // show.blade), not a pack-side scheduler. The old POST schedule route +
        // ViewingPackCalendarService were removed (no parallel scheduling logic).
        // Step 6 — the single buyer-facing PDF (cover + per-property + comparison).
        Route::get('/{viewingPack}/buyer-pack', [\App\Http\Controllers\CommandCenter\ViewingPackController::class, 'downloadBuyerPack'])->name('buyer-pack');
        // Step 7 — the SEPARATE agent sheet PDF (eyes-only; never merged with the buyer pack).
        Route::get('/{viewingPack}/agent-sheet', [\App\Http\Controllers\CommandCenter\ViewingPackController::class, 'downloadAgentSheet'])->name('agent-sheet');

        // Step 3 — property selection (Core Match + ad-hoc) + ad-hoc typeahead.
        Route::post('/{viewingPack}/properties', [\App\Http\Controllers\CommandCenter\ViewingPackController::class, 'addProperty'])->name('properties.add');
        Route::delete('/{viewingPack}/properties/{viewingPackProperty}', [\App\Http\Controllers\CommandCenter\ViewingPackController::class, 'removeProperty'])->name('properties.remove');
        Route::get('/{viewingPack}/search-properties', [\App\Http\Controllers\CommandCenter\ViewingPackController::class, 'searchProperties'])->name('properties.search');
        // Step 4 — manual drag order (no auto-routing). Body: { order: [rowId, …] }.
        Route::post('/{viewingPack}/properties/reorder', [\App\Http\Controllers\CommandCenter\ViewingPackController::class, 'reorderProperties'])->name('properties.reorder');
        // Step 5a — per-property buyer-pack document selection (eligible types only).
        Route::post('/{viewingPack}/properties/{viewingPackProperty}/documents', [\App\Http\Controllers\CommandCenter\ViewingPackController::class, 'addDocument'])->name('properties.documents.add');
        Route::delete('/{viewingPack}/properties/{viewingPackProperty}/documents/{viewingPackDocument}', [\App\Http\Controllers\CommandCenter\ViewingPackController::class, 'removeDocument'])->name('properties.documents.remove');
        // Step 5b — on-screen redaction → flattened image-only artifact.
        Route::get('/{viewingPack}/properties/{viewingPackProperty}/documents/{viewingPackDocument}/redaction-data', [\App\Http\Controllers\CommandCenter\ViewingPackController::class, 'redactionData'])->name('properties.documents.redaction-data');
        Route::post('/{viewingPack}/properties/{viewingPackProperty}/documents/{viewingPackDocument}/redact', [\App\Http\Controllers\CommandCenter\ViewingPackController::class, 'redactDocument'])->name('properties.documents.redact');
        Route::get('/{viewingPack}/properties/{viewingPackProperty}/documents/{viewingPackDocument}/redacted-file', [\App\Http\Controllers\CommandCenter\ViewingPackController::class, 'redactedFile'])->name('properties.documents.redacted-file');
    });

    // ── Agent Portal ──
    Route::get('/my-portal', [\App\Http\Controllers\Agent\AgentPortalController::class, 'index'])
        ->middleware(['permission:access_my_portal', 'agency.required'])->name('agent.portal');

    // ── My Portal → Communication Capture (AT-39) — email self-service. A user
    //    manages their own mailbox credentials (set_by=user). No reveal here. ──
    Route::middleware(['permission:access_communication', 'agency.required'])->prefix('my-portal/communication-capture')->name('my-portal.comm-capture.')->group(function () {
        Route::get('/', [\App\Http\Controllers\MyPortal\CommunicationCaptureController::class, 'index'])->name('index');
        Route::post('/', [\App\Http\Controllers\MyPortal\CommunicationCaptureController::class, 'store'])->name('store');
        Route::put('/{mailbox}', [\App\Http\Controllers\MyPortal\CommunicationCaptureController::class, 'update'])->name('update');
        Route::delete('/{mailbox}', [\App\Http\Controllers\MyPortal\CommunicationCaptureController::class, 'destroy'])->name('destroy');
    });
    Route::post('/my-portal/upload', [\App\Http\Controllers\Agent\AgentPortalController::class, 'uploadDocument'])
        ->middleware('permission:upload_own_documents')->name('agent.portal.upload');
    Route::patch('/my-portal/profile', [\App\Http\Controllers\Agent\AgentPortalController::class, 'updateProfile'])
        ->middleware('permission:edit_own_profile')->name('agent.portal.profile.update');

    // Admin Multi-Branch Manager — admin self-assigns which branches they
    // manage (+ a default) from their own profile. Gated by the dedicated
    // permission (admins/owners only; branch managers already have one branch).
    Route::patch('/my-portal/managed-branches', [\App\Http\Controllers\Agent\AgentPortalController::class, 'updateManagedBranches'])
        ->middleware('permission:branches.self_assign_managed')->name('agent.portal.managed-branches.update');

    // Live preview of an agent's public website page (self, or any agent in the
    // agency for managers/owner). Authorization handled in the controller.
    // Spec: .ai/specs/testimonials.md (agent linkage).
    Route::get('/agents/{user}/preview/{slug?}', [\App\Http\Controllers\CoreX\AgentPreviewController::class, 'show'])
        ->middleware('permission:access_my_portal')->name('corex.agents.preview');
    Route::get('/agents/{user}/articles/{article}/preview/{slug?}', [\App\Http\Controllers\CoreX\AgentPreviewController::class, 'article'])
        ->middleware('permission:access_my_portal')->name('corex.agents.article.preview');

    // Agent articles (self-service, My Portal → Profile).
    Route::middleware('permission:edit_own_profile')->group(function () {
        Route::post('/my-portal/articles',                          [\App\Http\Controllers\Agent\AgentArticleController::class, 'store'])->name('agent.portal.articles.store');
        Route::put('/my-portal/articles/{article}',                 [\App\Http\Controllers\Agent\AgentArticleController::class, 'update'])->name('agent.portal.articles.update');
        Route::patch('/my-portal/articles/{article}/publish',       [\App\Http\Controllers\Agent\AgentArticleController::class, 'togglePublish'])->name('agent.portal.articles.publish');
        Route::delete('/my-portal/articles/{article}',              [\App\Http\Controllers\Agent\AgentArticleController::class, 'destroy'])->name('agent.portal.articles.destroy');
    });

    // ── My Payslips (self-service) ──
    Route::get('/my-portal/payslips', [\App\Http\Controllers\Agent\AgentPortalController::class, 'myPayslips'])
        ->middleware(['permission:view_own_payslips', 'agency.required'])->name('my-portal.payslips');
    Route::get('/my-portal/payslips/{payslip}', [\App\Http\Controllers\Agent\AgentPortalController::class, 'myPayslipShow'])
        ->middleware(['permission:view_own_payslips', 'agency.required'])->name('my-portal.payslips.show');
    Route::get('/my-portal/payslips/{payslip}/pdf', [\App\Http\Controllers\Agent\AgentPortalController::class, 'myPayslipPdf'])
        ->middleware(['permission:view_own_payslips', 'agency.required'])->name('my-portal.payslips.pdf');

    // ── My Leave (agent self-service) ──
    Route::middleware(['permission:apply_for_leave', 'agency.required'])
        ->prefix('my-portal/leave')
        ->name('my-portal.leave.')
        ->group(function () {
            Route::get('/', [\App\Http\Controllers\MyPortal\MyPortalLeaveController::class, 'index'])->name('index');
            Route::get('apply', [\App\Http\Controllers\MyPortal\MyPortalLeaveController::class, 'create'])->name('apply');
            Route::post('apply', [\App\Http\Controllers\MyPortal\MyPortalLeaveController::class, 'store'])->name('store');
            Route::get('{application}', [\App\Http\Controllers\MyPortal\MyPortalLeaveController::class, 'show'])->name('show');
            Route::post('{application}/cancel', [\App\Http\Controllers\MyPortal\MyPortalLeaveController::class, 'cancel'])->name('cancel');
            Route::post('calculate-days', [\App\Http\Controllers\MyPortal\MyPortalLeaveController::class, 'calculateDays'])->name('calculate-days');
        });

    // ── Agency Documents (staff read-only view) ──
    Route::middleware(['permission:view_agency_documents', 'agency.required'])->group(function () {
        Route::get('/my-portal/agency-documents', [\App\Http\Controllers\Compliance\AgencyDocumentsViewerController::class, 'index'])->name('my-portal.agency-documents');
        Route::get('/my-portal/agency-documents/download/{provision}', [\App\Http\Controllers\Compliance\AgencyDocumentsViewerController::class, 'download'])->name('my-portal.agency-documents.download');
    });

    // ── RMCP Acknowledgement Flow ──
    Route::middleware(['permission:access_rmcp', 'agency.required'])->group(function () {
        Route::post('/my-portal/rmcp/acknowledge/start', [\App\Http\Controllers\Compliance\RmcpAcknowledgementController::class, 'start'])
            ->name('rmcp.ack.start');
        Route::get('/my-portal/rmcp/acknowledge/step/{order}', [\App\Http\Controllers\Compliance\RmcpAcknowledgementController::class, 'step'])
            ->name('rmcp.ack.step')->where('order', '[0-9]+');
        Route::post('/my-portal/rmcp/acknowledge/confirm/{order}', [\App\Http\Controllers\Compliance\RmcpAcknowledgementController::class, 'confirmSection'])
            ->name('rmcp.ack.confirm')->where('order', '[0-9]+');
        Route::get('/my-portal/rmcp/acknowledge/sign', [\App\Http\Controllers\Compliance\RmcpAcknowledgementController::class, 'sign'])
            ->name('rmcp.ack.sign');
        Route::post('/my-portal/rmcp/acknowledge/submit', [\App\Http\Controllers\Compliance\RmcpAcknowledgementController::class, 'submit'])
            ->name('rmcp.ack.submit');
        Route::get('/my-portal/rmcp/acknowledge/receipt/{ack}', [\App\Http\Controllers\Compliance\RmcpAcknowledgementController::class, 'receipt'])
            ->name('rmcp.ack.receipt');
        Route::get('/my-portal/rmcp/acknowledge/receipt/{ack}/pdf', [\App\Http\Controllers\Compliance\RmcpAcknowledgementController::class, 'downloadReceipt'])
            ->name('rmcp.ack.receipt.pdf');
        Route::get('/my-portal/rmcp/my-acknowledgements', [\App\Http\Controllers\Compliance\RmcpAcknowledgementController::class, 'index'])
            ->name('rmcp.ack.index');
    });

    // ── RMCP Compliance Dashboard ──
    Route::middleware(['permission:access_compliance_dashboard', 'agency.required'])->prefix('compliance/rmcp-dashboard')->name('compliance.rmcp.dashboard.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Compliance\RmcpDashboardController::class, 'index'])->name('index');
        Route::post('/reminder', [\App\Http\Controllers\Compliance\RmcpDashboardController::class, 'sendReminder'])->name('reminder');
        Route::get('/report.pdf', [\App\Http\Controllers\Compliance\RmcpDashboardController::class, 'report'])->name('report');
    });

    // ── Employee Screening ──
    Route::middleware(['permission:manage_employee_screenings', 'agency.required'])
        ->prefix('compliance/screenings')
        ->name('compliance.screenings.')
        ->group(function () {
            Route::get('/', [\App\Http\Controllers\Compliance\EmployeeScreeningController::class, 'index'])->name('index');
            Route::get('/overdue', [\App\Http\Controllers\Compliance\EmployeeScreeningController::class, 'overdueReport'])->name('overdue');
            Route::get('/create/{user?}', [\App\Http\Controllers\Compliance\EmployeeScreeningController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\Compliance\EmployeeScreeningController::class, 'store'])->name('store');
            Route::get('/{screening}', [\App\Http\Controllers\Compliance\EmployeeScreeningController::class, 'show'])->name('show');
            Route::patch('/check/{check}', [\App\Http\Controllers\Compliance\EmployeeScreeningController::class, 'updateCheck'])->name('check.update');
            Route::post('/check/{check}/document', [\App\Http\Controllers\Compliance\EmployeeScreeningController::class, 'uploadCheckDocument'])->name('check.document');
            Route::post('/{screening}/complete', [\App\Http\Controllers\Compliance\EmployeeScreeningController::class, 'complete'])->name('complete');
            Route::post('/{screening}/flag', [\App\Http\Controllers\Compliance\EmployeeScreeningController::class, 'flag'])->name('flag');
    });

    Route::middleware(['permission:access_compliance_dashboard', 'agency.required'])
        ->prefix('compliance/screening-dashboard')
        ->name('compliance.screening.dashboard.')
        ->group(function () {
            Route::get('/', [\App\Http\Controllers\Compliance\EmployeeScreeningDashboardController::class, 'index'])->name('index');
    });

    // User-facing: view own screening history
    Route::get('/my-portal/my-screenings', [\App\Http\Controllers\Compliance\EmployeeScreeningController::class, 'myScreenings'])
        ->middleware(['permission:view_own_screening', 'agency.required'])->name('compliance.screenings.my');

    // ── Commission Engine ──
    Route::get('/my-earnings', [\App\Http\Controllers\Commission\CommissionController::class, 'dashboard'])
        ->name('commission.dashboard');
    Route::get('/commission', [\App\Http\Controllers\Commission\CommissionController::class, 'index'])
        ->name('commission.index');
    Route::get('/commission/principal', [\App\Http\Controllers\Commission\CommissionController::class, 'principalDashboard'])
        ->name('commission.principal');
    Route::post('/commission/{entry}/confirm', [\App\Http\Controllers\Commission\CommissionController::class, 'confirm'])
        ->name('commission.confirm');
    Route::post('/commission/{entry}/pay', [\App\Http\Controllers\Commission\CommissionController::class, 'pay'])
        ->name('commission.pay');
    Route::get('/revenue-share/calculator', [\App\Http\Controllers\Commission\RevenueShareController::class, 'calculator'])
        ->name('revenue-share.calculator');

    // ── Training (LMS) ──
    Route::get('/training', [\App\Http\Controllers\Training\TrainingController::class, 'index'])->name('training.index');
    Route::get('/training/manage', [\App\Http\Controllers\Training\TrainingController::class, 'manage'])->name('training.manage');
    Route::get('/training/manage/create', [\App\Http\Controllers\Training\TrainingController::class, 'createCourse'])->name('training.create-course');
    Route::post('/training/manage', [\App\Http\Controllers\Training\TrainingController::class, 'storeCourse'])->name('training.store-course');
    Route::get('/training/manage/{course}/edit', [\App\Http\Controllers\Training\TrainingController::class, 'editCourse'])->name('training.edit-course');
    Route::put('/training/manage/{course}', [\App\Http\Controllers\Training\TrainingController::class, 'updateCourse'])->name('training.update-course');
    Route::get('/training/manage/{course}/lessons/create', [\App\Http\Controllers\Training\TrainingController::class, 'createLesson'])->name('training.create-lesson');
    Route::post('/training/manage/{course}/lessons', [\App\Http\Controllers\Training\TrainingController::class, 'storeLesson'])->name('training.store-lesson');
    Route::get('/training/manage/lessons/{lesson}/edit', [\App\Http\Controllers\Training\TrainingController::class, 'editLesson'])->name('training.edit-lesson');
    Route::put('/training/manage/lessons/{lesson}', [\App\Http\Controllers\Training\TrainingController::class, 'updateLesson'])->name('training.update-lesson');
    Route::get('/training/{course}', [\App\Http\Controllers\Training\TrainingController::class, 'show'])->name('training.show');
    Route::post('/training/lesson/{lesson}/start', [\App\Http\Controllers\Training\TrainingController::class, 'startLesson'])->name('training.start-lesson');
    Route::post('/training/lesson/{lesson}/complete', [\App\Http\Controllers\Training\TrainingController::class, 'completeLesson'])->name('training.complete-lesson');
    Route::post('/training/{course}/acknowledge', [\App\Http\Controllers\Training\TrainingController::class, 'acknowledgeCourse'])->name('training.acknowledge');

    // ── Training Help (in-app training docs) ──
    Route::prefix('training-help')->name('training-help.')->group(function () {
        Route::get('/',                              [\App\Http\Controllers\Training\TrainingHelpController::class, 'index'])->name('index');
        Route::get('/search',                        [\App\Http\Controllers\Training\TrainingHelpController::class, 'search'])->name('search');
        Route::get('/api/progress',                  [\App\Http\Controllers\Training\TrainingHelpController::class, 'progress'])->name('progress');
        Route::get('/{slug}',                        [\App\Http\Controllers\Training\TrainingHelpController::class, 'show'])->name('show');
        Route::get('/{slug}/pdf',                    [\App\Http\Controllers\Training\TrainingHelpController::class, 'pdf'])->name('pdf');
        Route::post('/{slug}/read',                  [\App\Http\Controllers\Training\TrainingHelpController::class, 'markRead'])->name('read');
        Route::post('/{slug}/rereviewed',            [\App\Http\Controllers\Training\TrainingHelpController::class, 'markRereviewed'])->name('rereviewed');
        Route::post('/{slug}/bookmark',              [\App\Http\Controllers\Training\TrainingHelpController::class, 'addBookmark'])->name('bookmark');
        Route::delete('/bookmarks/{id}',             [\App\Http\Controllers\Training\TrainingHelpController::class, 'removeBookmark'])->name('bookmark.remove');
    });

    // ── Agent Onboarding ──
    Route::prefix('onboarding')->group(function () {
        Route::get('/', [\App\Http\Controllers\Onboarding\OnboardingController::class, 'index'])->name('onboarding.index');
        Route::get('/create', [\App\Http\Controllers\Onboarding\OnboardingController::class, 'create'])->name('onboarding.create');
        Route::post('/', [\App\Http\Controllers\Onboarding\OnboardingController::class, 'store'])->name('onboarding.store');
        Route::get('/{application}', [\App\Http\Controllers\Onboarding\OnboardingController::class, 'show'])->name('onboarding.show');
        Route::post('/{application}/status', [\App\Http\Controllers\Onboarding\OnboardingController::class, 'updateStatus'])->name('onboarding.status');
        Route::post('/{application}/upload', [\App\Http\Controllers\Onboarding\OnboardingController::class, 'uploadDocument'])->name('onboarding.upload');
        Route::post('/document/{doc}/verify', [\App\Http\Controllers\Onboarding\OnboardingController::class, 'verifyDocument'])->name('onboarding.verify-document');
        Route::post('/checklist/{item}/toggle', [\App\Http\Controllers\Onboarding\OnboardingController::class, 'toggleChecklist'])->name('onboarding.toggle-checklist');
        Route::post('/{application}/activate', [\App\Http\Controllers\Onboarding\OnboardingController::class, 'activate'])->name('onboarding.activate');
    });

    Route::get('/documents', [CoreXPlaceholderController::class, 'show'])->defaults('section', 'documents')->middleware('permission:access_docuperfect')->name('corex.documents');
    // ── Compliance / FICA ──
    Route::get('/compliance/agents', [\App\Http\Controllers\Compliance\AgentComplianceController::class, 'dashboard'])
        ->name('compliance.agents');
    // Old RMCP route — redirect to new structured RMCP
    Route::get('/compliance/rmcp', function () {
        return redirect()->route('compliance.rmcp.index');
    })->middleware('permission:access_compliance')->name('compliance.rmcp');

    // ── RMCP (structured) ──
    Route::middleware('agency.required')->prefix('compliance/rmcp-manager')->name('compliance.rmcp.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Compliance\RmcpController::class, 'index'])
            ->name('index')->middleware('permission:access_rmcp');
        Route::get('/variables', [\App\Http\Controllers\Compliance\RmcpController::class, 'variables'])
            ->name('variables')->middleware('permission:edit_rmcp');
        Route::patch('/variables/{variable}', [\App\Http\Controllers\Compliance\RmcpController::class, 'updateVariable'])
            ->name('variables.update')->middleware('permission:edit_rmcp');
        Route::get('/create', [\App\Http\Controllers\Compliance\RmcpController::class, 'create'])
            ->name('create')->middleware('permission:edit_rmcp');
        Route::get('/{version}', [\App\Http\Controllers\Compliance\RmcpController::class, 'show'])
            ->name('show')->middleware('permission:access_rmcp');
        Route::get('/{version}/edit', [\App\Http\Controllers\Compliance\RmcpController::class, 'edit'])
            ->name('edit')->middleware('permission:edit_rmcp');
        Route::patch('/{version}', [\App\Http\Controllers\Compliance\RmcpController::class, 'update'])
            ->name('update')->middleware('permission:edit_rmcp');
        Route::get('/{version}/approve', [\App\Http\Controllers\Compliance\RmcpController::class, 'approveForm'])
            ->name('approve.form')->middleware('permission:approve_rmcp');
        Route::post('/{version}/approve', [\App\Http\Controllers\Compliance\RmcpController::class, 'approve'])
            ->name('approve')->middleware('permission:approve_rmcp');
        Route::get('/{version}/pdf', [\App\Http\Controllers\Compliance\RmcpController::class, 'downloadPdf'])
            ->name('pdf')->middleware('permission:access_rmcp');
    });

    // ── Policy Acknowledgement Framework (AT-29) ──

    // Staff sign-off wizard — scoped by {policy} (policy_key). {policy} is a string, not model-bound.
    Route::middleware(['permission:access_policy', 'agency.required'])
        ->prefix('my-portal/policy/{policy}/acknowledge')
        ->name('policy.ack.')
        ->group(function () {
            Route::post('/start', [\App\Http\Controllers\Compliance\PolicyAcknowledgementController::class, 'start'])->name('start');
            Route::get('/step/{order}', [\App\Http\Controllers\Compliance\PolicyAcknowledgementController::class, 'step'])->name('step')->where('order', '[0-9]+');
            Route::post('/confirm/{order}', [\App\Http\Controllers\Compliance\PolicyAcknowledgementController::class, 'confirmSection'])->name('confirm')->where('order', '[0-9]+');
            Route::get('/sign', [\App\Http\Controllers\Compliance\PolicyAcknowledgementController::class, 'sign'])->name('sign');
            Route::post('/submit', [\App\Http\Controllers\Compliance\PolicyAcknowledgementController::class, 'submit'])->name('submit');
            Route::get('/receipt/{ack}', [\App\Http\Controllers\Compliance\PolicyAcknowledgementController::class, 'receipt'])->name('receipt');
            Route::get('/receipt/{ack}/pdf', [\App\Http\Controllers\Compliance\PolicyAcknowledgementController::class, 'downloadReceipt'])->name('receipt.pdf');
            Route::get('/my-acknowledgements', [\App\Http\Controllers\Compliance\PolicyAcknowledgementController::class, 'index'])->name('index');
        });

    // Compliance-officer register (with policy selector via ?policy=)
    Route::middleware(['permission:access_compliance_dashboard', 'agency.required'])
        ->prefix('compliance/policy-dashboard')
        ->name('compliance.policy.dashboard.')
        ->group(function () {
            Route::get('/', [\App\Http\Controllers\Compliance\PolicyDashboardController::class, 'index'])->name('index');
            Route::post('/reminder', [\App\Http\Controllers\Compliance\PolicyDashboardController::class, 'sendReminder'])->name('reminder');
            Route::get('/report.pdf', [\App\Http\Controllers\Compliance\PolicyDashboardController::class, 'report'])->name('report');
        });

    // Authoring / versioning
    Route::middleware('agency.required')->prefix('compliance/policy-manager')->name('compliance.policy.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Compliance\PolicyController::class, 'index'])
            ->name('index')->middleware('permission:access_policy');
        Route::get('/create', [\App\Http\Controllers\Compliance\PolicyController::class, 'createPolicy'])
            ->name('create')->middleware('permission:edit_policy');
        Route::post('/', [\App\Http\Controllers\Compliance\PolicyController::class, 'storePolicy'])
            ->name('store')->middleware('permission:edit_policy');
        Route::get('/policies/{policy}/versions/create', [\App\Http\Controllers\Compliance\PolicyController::class, 'createVersion'])
            ->name('version.create')->middleware('permission:edit_policy');
        Route::get('/{version}', [\App\Http\Controllers\Compliance\PolicyController::class, 'show'])
            ->name('show')->middleware('permission:access_policy');
        Route::get('/{version}/edit', [\App\Http\Controllers\Compliance\PolicyController::class, 'edit'])
            ->name('edit')->middleware('permission:edit_policy');
        Route::patch('/{version}', [\App\Http\Controllers\Compliance\PolicyController::class, 'update'])
            ->name('update')->middleware('permission:edit_policy');
        Route::post('/{version}/sections', [\App\Http\Controllers\Compliance\PolicyController::class, 'addSection'])
            ->name('section.add')->middleware('permission:edit_policy');
        Route::delete('/{version}/sections/{section}', [\App\Http\Controllers\Compliance\PolicyController::class, 'deleteSection'])
            ->name('section.delete')->middleware('permission:edit_policy');
        Route::get('/{version}/approve', [\App\Http\Controllers\Compliance\PolicyController::class, 'approveForm'])
            ->name('approve.form')->middleware('permission:approve_policy');
        Route::post('/{version}/approve', [\App\Http\Controllers\Compliance\PolicyController::class, 'approve'])
            ->name('approve')->middleware('permission:approve_policy');
        Route::get('/{version}/pdf', [\App\Http\Controllers\Compliance\PolicyController::class, 'downloadPdf'])
            ->name('pdf')->middleware('permission:access_policy');
    });

    // ── RMCP Compliance Officer (retired — redirects to settings) ──
    Route::get('/compliance/officer', function () {
        return redirect('/corex/settings?tab=user');
    })->name('compliance.officer.index')->middleware('permission:manage_compliance_officer');

    Route::middleware(['permission:access_compliance', 'agency.required'])->prefix('compliance/fica')->name('compliance.fica.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Compliance\FicaController::class, 'index'])->name('index');
        Route::get('/create', [\App\Http\Controllers\Compliance\FicaController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\Compliance\FicaController::class, 'store'])->name('store');
        Route::get('/wet-ink/create', [\App\Http\Controllers\Compliance\FicaController::class, 'createWetInk'])->name('wet-ink.create');
        Route::post('/wet-ink', [\App\Http\Controllers\Compliance\FicaController::class, 'storeWetInk'])->name('wet-ink.store');
        Route::get('/{submission}', [\App\Http\Controllers\Compliance\FicaController::class, 'show'])->name('show');
        Route::get('/{submission}/pdf', [\App\Http\Controllers\Compliance\FicaController::class, 'downloadPdf'])->name('pdf');
        Route::post('/{submission}/agent-approve', [\App\Http\Controllers\Compliance\FicaController::class, 'agentApprove'])->name('agent-approve');
        Route::get('/{submission}/compliance-review', [\App\Http\Controllers\Compliance\FicaController::class, 'complianceReview'])->name('compliance-review');
        Route::post('/{submission}/compliance-approve', [\App\Http\Controllers\Compliance\FicaController::class, 'complianceApprove'])->name('compliance-approve');
        Route::post('/{submission}/compliance-reject', [\App\Http\Controllers\Compliance\FicaController::class, 'complianceReject'])->name('compliance-reject');
        Route::post('/{submission}/refer-to-co', [\App\Http\Controllers\Compliance\FicaController::class, 'referToCo'])->name('refer-to-co');
        Route::post('/{submission}/return-to-referrer', [\App\Http\Controllers\Compliance\FicaController::class, 'returnToReferrer'])->name('return-to-referrer');
        Route::post('/{submission}/reject', [\App\Http\Controllers\Compliance\FicaController::class, 'reject'])->name('reject');
        Route::post('/{submission}/request-corrections', [\App\Http\Controllers\Compliance\FicaController::class, 'requestCorrections'])->name('request-corrections');
        Route::post('/{submission}/resend', [\App\Http\Controllers\Compliance\FicaController::class, 'resend'])->name('resend');
        Route::post('/{submission}/cancel', [\App\Http\Controllers\Compliance\FicaController::class, 'cancel'])->name('cancel');
        Route::post('/{submission}/resubmit-corrections', [\App\Http\Controllers\Compliance\FicaController::class, 'resubmitCorrections'])->name('resubmit-corrections');
        Route::post('/{submission}/reopen', [\App\Http\Controllers\Compliance\FicaController::class, 'reopenRejected'])->name('reopen');
        Route::post('/{submission}/agent-upload', [\App\Http\Controllers\Compliance\FicaController::class, 'agentUpload'])->name('agent-upload');
        Route::post('/{submission}/documents/{document}/remove', [\App\Http\Controllers\Compliance\FicaController::class, 'removeDocument'])->name('documents.remove');
    });

    // ── Whistleblower Compliance Reporting ──
    Route::middleware(['agency.required'])->prefix('compliance/whistleblow')->name('compliance.whistleblow.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Compliance\WhistleblowController::class, 'index'])->name('index')->middleware('permission:compliance.whistleblow.view');
        Route::post('/', [\App\Http\Controllers\Compliance\WhistleblowController::class, 'store'])->name('store')->middleware('permission:compliance.whistleblow.create');
        Route::get('/new', [\App\Http\Controllers\Compliance\WhistleblowController::class, 'create'])->name('create')->middleware('permission:compliance.whistleblow.create');
        Route::get('/lawyer-review-pack', [\App\Http\Controllers\Compliance\WhistleblowController::class, 'lawyerReviewPack'])->name('lawyer-pack')->middleware('permission:compliance.whistleblow.configure');
        Route::get('/{complaint}', [\App\Http\Controllers\Compliance\WhistleblowController::class, 'show'])->name('show')->middleware('permission:compliance.whistleblow.view');
        Route::post('/{complaint}/approve', [\App\Http\Controllers\Compliance\WhistleblowController::class, 'approve'])->name('approve')->middleware('permission:compliance.whistleblow.approve');
        Route::post('/{complaint}/reject', [\App\Http\Controllers\Compliance\WhistleblowController::class, 'reject'])->name('reject')->middleware('permission:compliance.whistleblow.approve');
        Route::post('/{complaint}/request-changes', [\App\Http\Controllers\Compliance\WhistleblowController::class, 'requestChanges'])->name('request-changes')->middleware('permission:compliance.whistleblow.approve');
    });

    // ── Seller Information Pack ──
    // AT-161 — gate fix: this is a SEND action (the legal-info email for sellers who
    // won't sign), not a whistleblow surface. Repointed off the borrowed
    // `compliance.whistleblow.view` onto a proper own gate (outreach compose). Stays
    // filed under Compliance per the re-cut IA.
    Route::middleware(['permission:outreach.compose', 'agency.required'])->prefix('compliance/seller-info')->name('compliance.seller-info.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Compliance\SellerInfoController::class, 'index'])->name('index');
        Route::post('/preview', [\App\Http\Controllers\Compliance\SellerInfoController::class, 'preview'])->name('preview');
        Route::post('/send', [\App\Http\Controllers\Compliance\SellerInfoController::class, 'send'])->name('send');
        Route::post('/whatsapp-link', [\App\Http\Controllers\Compliance\SellerInfoController::class, 'generateWhatsappLink'])->name('whatsapp-link');
    });

    // ── Compliance Communications Log ──
    // AT-161 — gate fix: a comms audit/log view, repointed off the borrowed
    // `compliance.whistleblow.view` onto the proper comms gate
    // (`access_communication_archive`). Stays filed under Compliance per the re-cut IA.
    Route::middleware(['permission:access_communication_archive', 'agency.required'])->prefix('compliance/communications')->name('compliance.communications.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Compliance\CommunicationsLogController::class, 'index'])->name('index');
    });

    // ── Communication Archive (AT-33) — email/WhatsApp evidence archive viewer.
    // Gated by the dedicated, role-grantable access_communication_archive
    // permission so each agency controls archive visibility per role/user.
    Route::middleware(['permission:access_communication_archive', 'agency.required'])->prefix('compliance/communication-archive')->name('compliance.comm-archive.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Compliance\CommunicationArchiveController::class, 'index'])->name('index');
        Route::get('/thread/{threadKey}', [\App\Http\Controllers\Compliance\CommunicationArchiveController::class, 'thread'])->name('thread')->where('threadKey', '.*');
        Route::get('/message/{communication}', [\App\Http\Controllers\Compliance\CommunicationArchiveController::class, 'show'])->name('show');
        // AT-148 — authenticated media serve (WhatsApp voice notes). Streamed from
        // the mounted volume through Laravel; per-thread gated in the controller.
        Route::get('/attachment/{attachment}', [\App\Http\Controllers\Compliance\CommunicationArchiveController::class, 'attachment'])->name('attachment');
        // AT-148 — manual retry for a pending/failed media download.
        Route::post('/attachment/{attachment}/retry', [\App\Http\Controllers\Compliance\CommunicationArchiveController::class, 'retryMedia'])->name('attachment.retry');
        // AT-163 — on-demand voice-note transcription (gated + consent-checked in the controller).
        Route::post('/message/{communication}/transcribe', [\App\Http\Controllers\Compliance\CommunicationArchiveController::class, 'transcribeNote'])->name('transcribe');
    });


    // ── Communication Archive — mailbox config (AT-33) — tighter: editing IMAP
    // credentials is admin/compliance-level, separate from viewing the archive.
    Route::middleware(['permission:manage_communication_mailboxes', 'agency.required'])->prefix('compliance/communication-mailboxes')->name('compliance.comm-mailboxes.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Compliance\CommunicationMailboxController::class, 'index'])->name('index');
        Route::get('/create', [\App\Http\Controllers\Compliance\CommunicationMailboxController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\Compliance\CommunicationMailboxController::class, 'store'])->name('store');
        Route::get('/{mailbox}/edit', [\App\Http\Controllers\Compliance\CommunicationMailboxController::class, 'edit'])->name('edit');
        Route::put('/{mailbox}', [\App\Http\Controllers\Compliance\CommunicationMailboxController::class, 'update'])->name('update');
        Route::delete('/{mailbox}', [\App\Http\Controllers\Compliance\CommunicationMailboxController::class, 'destroy'])->name('destroy');
    });

    // ── WhatsApp capture device registration (AT-34) — agent self-service. ──
    Route::middleware(['permission:access_communication', 'agency.required'])->prefix('communications/wa-devices')->name('communications.wa-devices.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Communications\WaDeviceController::class, 'index'])->name('index');
        Route::post('/', [\App\Http\Controllers\Communications\WaDeviceController::class, 'store'])->name('store');
        Route::post('/backfill-toggle', [\App\Http\Controllers\Communications\WaDeviceController::class, 'toggleBackfill'])->name('backfill-toggle'); // AT-135
        Route::post('/embargo-retention', [\App\Http\Controllers\Communications\WaDeviceController::class, 'updateEmbargoRetention'])->name('embargo-retention'); // AT-168
        Route::post('/transcription-language', [\App\Http\Controllers\Communications\WaDeviceController::class, 'updateTranscriptionLanguage'])->name('transcription-language'); // AT-194
        Route::delete('/{waDevice}', [\App\Http\Controllers\Communications\WaDeviceController::class, 'destroy'])->name('destroy');
    });

    // ── AT-156 — WhatsApp Capture Linking (My Portal → Tools). In-app QR
    //    pairing; server proxies WAHA, key stays server-side. ──
    Route::middleware(['permission:access_communication', 'agency.required'])->prefix('communications/wa-link')->name('communications.wa-link.')->group(function () {
        Route::get('/status', [\App\Http\Controllers\Communications\WhatsAppLinkController::class, 'status'])->name('status');
        Route::get('/qr', [\App\Http\Controllers\Communications\WhatsAppLinkController::class, 'qr'])->name('qr');
        Route::post('/link', [\App\Http\Controllers\Communications\WhatsAppLinkController::class, 'link'])->name('link');
        Route::post('/restart', [\App\Http\Controllers\Communications\WhatsAppLinkController::class, 'restart'])->name('restart');
        Route::post('/unlink', [\App\Http\Controllers\Communications\WhatsAppLinkController::class, 'unlink'])->name('unlink');
    });

    // ── AT-136 — per-agent WhatsApp capture consent (controls body INGESTION;
    //    SEPARATE from the AT-125 contact marketing opt-out). ──
    Route::middleware(['permission:access_communication', 'agency.required'])->prefix('communications/capture')->name('communications.capture.')->group(function () {
        Route::get('/my', [\App\Http\Controllers\Communications\AgentCaptureConsentController::class, 'myCapture'])->name('my');
        Route::post('/decide', [\App\Http\Controllers\Communications\AgentCaptureConsentController::class, 'decide'])->name('decide');
        // Admin/CO review — capability-checked inside (communications.capture_review).
        Route::get('/review', [\App\Http\Controllers\Communications\AgentCaptureConsentController::class, 'review'])->name('review');
        Route::post('/{consent}/flag', [\App\Http\Controllers\Communications\AgentCaptureConsentController::class, 'flag'])->name('flag');
    });

    // ── Communication Archive — pending triage (AT-36, staff-facing) ──
    Route::middleware(['permission:triage_communications', 'agency.required'])->prefix('communications/triage')->name('communications.triage.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Communications\CommunicationTriageController::class, 'index'])->name('index');
        Route::post('/add-contact', [\App\Http\Controllers\Communications\CommunicationTriageController::class, 'addContact'])->name('add-contact');
        Route::post('/not-real-estate', [\App\Http\Controllers\Communications\CommunicationTriageController::class, 'notRealEstate'])->name('not-real-estate');
    });

    // ── Communication Archive — BM flag register (AT-36, audit; no message content) ──
    Route::middleware(['permission:view_communication_flag_register', 'agency.required'])->prefix('compliance/communication-flags')->name('compliance.comm-flags.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Communications\CommunicationFlagRegisterController::class, 'index'])->name('index');
    });

    // ── Communication Capture Setup — Settings → Email Setup (AT-37) ──
    // Per-user IMAP capture management. Write-only credentials; the reveal route
    // below is separately gated by the principal-only reveal_mailbox_credential.
    Route::middleware(['permission:manage_communication_mailboxes', 'agency.required'])->prefix('settings/email-setup')->name('settings.email-setup.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Settings\EmailSetupController::class, 'index'])->name('index');
        Route::post('/users/{user}/mailbox', [\App\Http\Controllers\Settings\EmailSetupController::class, 'store'])->name('store');
        Route::put('/mailbox/{mailbox}', [\App\Http\Controllers\Settings\EmailSetupController::class, 'update'])->name('update');
        Route::delete('/mailbox/{mailbox}', [\App\Http\Controllers\Settings\EmailSetupController::class, 'destroy'])->name('destroy');
    });
    // The audited reveal — principal-only, separate permission from management.
    Route::middleware(['permission:reveal_mailbox_credential', 'agency.required'])
        ->post('settings/email-setup/mailbox/{mailbox}/reveal', [\App\Http\Controllers\Settings\EmailSetupController::class, 'reveal'])
        ->name('settings.email-setup.reveal');

    // ── Document Verification Queue ──
    Route::middleware(['permission:verify_user_documents', 'agency.required'])->prefix('compliance/verification-queue')->name('compliance.verification.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Compliance\DocumentVerificationController::class, 'index'])->name('index');
        Route::get('/{userDocument}', [\App\Http\Controllers\Compliance\DocumentVerificationController::class, 'show'])->name('show');
        Route::post('/{userDocument}/verify', [\App\Http\Controllers\Compliance\DocumentVerificationController::class, 'verify'])->name('verify');
        Route::post('/{userDocument}/reject', [\App\Http\Controllers\Compliance\DocumentVerificationController::class, 'reject'])->name('reject');
        Route::post('/{userDocument}/expire', [\App\Http\Controllers\Compliance\DocumentVerificationController::class, 'markExpired'])->name('expire');
    });

    // ── Agency Document Type Configuration ──
    Route::middleware(['permission:manage_agency_compliance', 'agency.required'])->prefix('compliance/document-types')->name('compliance.document-types.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Compliance\AgencyDocumentTypeConfigController::class, 'index'])->name('index');
        Route::get('/create', [\App\Http\Controllers\Compliance\AgencyDocumentTypeConfigController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\Compliance\AgencyDocumentTypeConfigController::class, 'store'])->name('store');
        Route::get('/{slug}/edit', [\App\Http\Controllers\Compliance\AgencyDocumentTypeConfigController::class, 'edit'])->name('edit');
        Route::put('/{slug}', [\App\Http\Controllers\Compliance\AgencyDocumentTypeConfigController::class, 'update'])->name('update');
        Route::post('/{slug}/archive', [\App\Http\Controllers\Compliance\AgencyDocumentTypeConfigController::class, 'archive'])->name('archive');
        Route::post('/{slug}/restore', [\App\Http\Controllers\Compliance\AgencyDocumentTypeConfigController::class, 'restore'])->name('restore');
    });

    // ── Agency Compliance Provisions (Settings) ──
    Route::middleware(['permission:manage_agency_compliance', 'agency.required'])->prefix('compliance/agency-settings')->name('compliance.agency-settings.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Compliance\AgencyComplianceSettingsController::class, 'index'])->name('index');
        Route::post('/', [\App\Http\Controllers\Compliance\AgencyComplianceSettingsController::class, 'store'])->name('store');
        Route::get('/{provision}/edit', [\App\Http\Controllers\Compliance\AgencyComplianceSettingsController::class, 'edit'])->name('edit');
        Route::patch('/{provision}', [\App\Http\Controllers\Compliance\AgencyComplianceSettingsController::class, 'update'])->name('update');
        Route::delete('/{provision}', [\App\Http\Controllers\Compliance\AgencyComplianceSettingsController::class, 'destroy'])->name('destroy');
    });

    // ── Admin Upload on Behalf + Per-User Compliance Overrides ──
    Route::middleware(['permission:manage_user_compliance', 'agency.required'])->prefix('admin/users/{user}')->group(function () {
        Route::get('/documents/upload', [\App\Http\Controllers\Compliance\UserDocumentAdminController::class, 'uploadForUser'])->name('admin.user.documents.upload');
        Route::post('/documents', [\App\Http\Controllers\Compliance\UserDocumentAdminController::class, 'storeForUser'])->name('admin.user.documents.store');
        Route::post('/compliance-overrides', [\App\Http\Controllers\Compliance\UserComplianceOverrideController::class, 'store'])->name('admin.user.overrides.store');
    });
    Route::middleware(['permission:manage_user_compliance', 'agency.required'])
        ->post('admin/compliance-overrides/{override}/revoke', [\App\Http\Controllers\Compliance\UserComplianceOverrideController::class, 'revoke'])
        ->name('admin.user.overrides.revoke');

    // ── Payroll ──
    Route::middleware(['permission:manage_payroll', 'agency.required'])
        ->prefix('payroll')
        ->name('payroll.')
        ->group(function () {
            Route::resource('earning-types', \App\Http\Controllers\Payroll\PayrollEarningTypeController::class)
                ->except(['show']);
            Route::resource('deduction-types', \App\Http\Controllers\Payroll\PayrollDeductionTypeController::class)
                ->except(['show']);

            Route::resource('employees', \App\Http\Controllers\Payroll\PayrollEmployeeController::class);
            Route::post('employees/{employee}/deactivate', [\App\Http\Controllers\Payroll\PayrollEmployeeController::class, 'deactivate'])
                ->name('employees.deactivate');
            Route::post('employees/{employee}/reactivate', [\App\Http\Controllers\Payroll\PayrollEmployeeController::class, 'reactivate'])
                ->name('employees.reactivate');

            Route::post('employees/{employee}/earnings', [\App\Http\Controllers\Payroll\PayrollEmployeeController::class, 'storeEarning'])
                ->name('employees.earnings.store');
            Route::patch('employees/{employee}/earnings/{earning}', [\App\Http\Controllers\Payroll\PayrollEmployeeController::class, 'updateEarning'])
                ->name('employees.earnings.update');
            Route::delete('employees/{employee}/earnings/{earning}', [\App\Http\Controllers\Payroll\PayrollEmployeeController::class, 'destroyEarning'])
                ->name('employees.earnings.destroy');

            Route::post('employees/{employee}/deductions', [\App\Http\Controllers\Payroll\PayrollEmployeeController::class, 'storeDeduction'])
                ->name('employees.deductions.store');
            Route::patch('employees/{employee}/deductions/{deduction}', [\App\Http\Controllers\Payroll\PayrollEmployeeController::class, 'updateDeduction'])
                ->name('employees.deductions.update');
            Route::delete('employees/{employee}/deductions/{deduction}', [\App\Http\Controllers\Payroll\PayrollEmployeeController::class, 'destroyDeduction'])
                ->name('employees.deductions.destroy');

            Route::post('employees/{employee}/banking', [\App\Http\Controllers\Payroll\PayrollEmployeeController::class, 'storeBanking'])
                ->name('employees.banking.store');
            Route::patch('employees/{employee}/banking', [\App\Http\Controllers\Payroll\PayrollEmployeeController::class, 'updateBanking'])
                ->name('employees.banking.update');

            // ── Payroll Runs ──
            Route::resource('runs', \App\Http\Controllers\Payroll\PayrollRunController::class)
                ->only(['index', 'create', 'store', 'show'])
                ->middleware('permission:run_payroll');
            Route::post('runs/{run}/cancel', [\App\Http\Controllers\Payroll\PayrollRunController::class, 'cancel'])
                ->name('runs.cancel')
                ->middleware('permission:run_payroll');
            Route::get('runs/{run}/payslips/{payslip}', [\App\Http\Controllers\Payroll\PayrollRunController::class, 'payslipShow'])
                ->name('runs.payslips.show')
                ->middleware('permission:run_payroll');
            Route::get('runs/{run}/payslips/{payslip}/edit', [\App\Http\Controllers\Payroll\PayrollRunController::class, 'payslipEdit'])
                ->name('runs.payslips.edit')
                ->middleware('permission:run_payroll');
            Route::post('runs/{run}/payslips/{payslip}/lines', [\App\Http\Controllers\Payroll\PayrollRunController::class, 'storePayslipLine'])
                ->name('runs.payslips.lines.store')
                ->middleware('permission:run_payroll');
            Route::patch('runs/{run}/payslips/{payslip}/lines/{line}', [\App\Http\Controllers\Payroll\PayrollRunController::class, 'updatePayslipLine'])
                ->name('runs.payslips.lines.update')
                ->middleware('permission:run_payroll');
            Route::delete('runs/{run}/payslips/{payslip}/lines/{line}', [\App\Http\Controllers\Payroll\PayrollRunController::class, 'destroyPayslipLine'])
                ->name('runs.payslips.lines.destroy')
                ->middleware('permission:run_payroll');
            Route::post('runs/{run}/payslips/{payslip}/recalculate', [\App\Http\Controllers\Payroll\PayrollRunController::class, 'recalculatePayslip'])
                ->name('runs.payslips.recalculate')
                ->middleware('permission:run_payroll');
            Route::patch('runs/{run}/payslips/{payslip}/notes', [\App\Http\Controllers\Payroll\PayrollRunController::class, 'updatePayslipNotes'])
                ->name('runs.payslips.notes')
                ->middleware('permission:run_payroll');
            Route::get('runs/{run}/payslips/{payslip}/pdf-preview', [\App\Http\Controllers\Payroll\PayrollRunController::class, 'payslipPdfPreview'])
                ->name('runs.payslips.pdf-preview')
                ->middleware('permission:run_payroll');
            Route::post('runs/{run}/finalise', [\App\Http\Controllers\Payroll\PayrollRunController::class, 'finalise'])
                ->name('runs.finalise')
                ->middleware('permission:run_payroll');
            Route::get('runs/{run}/payslips/{payslip}/pdf-download', [\App\Http\Controllers\Payroll\PayrollRunController::class, 'payslipPdfDownload'])
                ->name('runs.payslips.pdf-download')
                ->middleware('permission:run_payroll');
            Route::get('runs/{run}/bundle', [\App\Http\Controllers\Payroll\PayrollRunController::class, 'bundlePdf'])
                ->name('runs.bundle')
                ->middleware('permission:run_payroll');
            Route::get('runs/{run}/report', [\App\Http\Controllers\Payroll\PayrollRunController::class, 'runReport'])
                ->name('runs.report')
                ->middleware('permission:view_payroll_reports');
        });

    // ── Leave Admin ──
    Route::middleware(['auth', 'agency.required'])
        ->prefix('payroll/leave')
        ->name('payroll.leave.')
        ->group(function () {
            Route::resource('types', \App\Http\Controllers\Leave\LeaveTypeController::class)
                ->except(['show'])
                ->middleware('permission:manage_leave_types');

            Route::get('dashboard', [\App\Http\Controllers\Leave\LeaveDashboardController::class, 'index'])
                ->name('dashboard')
                ->middleware('permission:manage_leave');

            Route::get('balances', [\App\Http\Controllers\Leave\LeaveBalanceController::class, 'index'])
                ->name('balances.index')
                ->middleware('permission:manage_leave');
            Route::get('balances/{employee}', [\App\Http\Controllers\Leave\LeaveBalanceController::class, 'show'])
                ->name('balances.show')
                ->middleware('permission:manage_leave');
            Route::post('balances/{employee}/adjust', [\App\Http\Controllers\Leave\LeaveBalanceController::class, 'adjust'])
                ->name('balances.adjust')
                ->middleware('permission:adjust_leave_balances');
            Route::post('balances/{employee}/recalculate', [\App\Http\Controllers\Leave\LeaveBalanceController::class, 'recalculate'])
                ->name('balances.recalculate')
                ->middleware('permission:manage_leave');

            Route::resource('public-holidays', \App\Http\Controllers\Leave\PublicHolidayController::class)
                ->except(['show'])
                ->middleware('permission:manage_leave_types');

            // Leave Applications (BM + admin)
            Route::get('applications', [\App\Http\Controllers\Leave\LeaveApplicationController::class, 'index'])
                ->name('applications.index')
                ->middleware('permission:approve_leave');
            Route::get('applications/{application}', [\App\Http\Controllers\Leave\LeaveApplicationController::class, 'show'])
                ->name('applications.show')
                ->middleware('permission:approve_leave');
            Route::post('applications/{application}/approve', [\App\Http\Controllers\Leave\LeaveApplicationController::class, 'approve'])
                ->name('applications.approve')
                ->middleware('permission:approve_leave');
            Route::post('applications/{application}/reject', [\App\Http\Controllers\Leave\LeaveApplicationController::class, 'reject'])
                ->name('applications.reject')
                ->middleware('permission:approve_leave');

            // Leave Reports
            Route::get('reports/register', [\App\Http\Controllers\Leave\LeaveReportController::class, 'register'])
                ->name('reports.register')
                ->middleware('permission:view_leave_reports');
            Route::get('reports/register/export/{format}', [\App\Http\Controllers\Leave\LeaveReportController::class, 'registerExport'])
                ->name('reports.register.export')
                ->middleware('permission:view_leave_reports');
            Route::get('reports/branch-summary', [\App\Http\Controllers\Leave\LeaveReportController::class, 'branchSummary'])
                ->name('reports.branch-summary')
                ->middleware('permission:view_leave_reports');
            Route::get('reports/accrual-statement/{employee}', [\App\Http\Controllers\Leave\LeaveReportController::class, 'accrualStatement'])
                ->name('reports.accrual-statement')
                ->middleware('permission:view_leave_reports');
            Route::get('reports/audit-log', [\App\Http\Controllers\Leave\LeaveReportController::class, 'auditLog'])
                ->name('reports.audit-log')
                ->middleware('permission:view_leave_reports');
        });

    // ── Staff Take-On Wizard ──
    Route::middleware(['permission:manage_staff_take_on', 'agency.required'])
        ->prefix('staff-take-on')
        ->name('staff-take-on.')
        ->group(function () {
            Route::get('/', [\App\Http\Controllers\StaffTakeOnController::class, 'index'])->name('index');
            Route::get('create', [\App\Http\Controllers\StaffTakeOnController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\StaffTakeOnController::class, 'store'])->name('store');
            Route::get('{takeOn}/wizard/{step}', [\App\Http\Controllers\StaffTakeOnController::class, 'wizard'])->name('wizard');
            Route::patch('{takeOn}/wizard/{step}', [\App\Http\Controllers\StaffTakeOnController::class, 'saveStep'])->name('save-step');
            Route::post('{takeOn}/complete', [\App\Http\Controllers\StaffTakeOnController::class, 'complete'])->name('complete');
            Route::post('{takeOn}/upload-document', [\App\Http\Controllers\StaffTakeOnController::class, 'uploadDocument'])->name('upload-document');
        });

    Route::get('/supervision', [CoreXPlaceholderController::class, 'show'])->defaults('section', 'supervision')->middleware('permission:access_supervision')->name('corex.supervision');
    // Training placeholder replaced by LMS module (training.index route above)

    // Guided Tours directory (AT-41) — agent self-serve training index. Any
    // authenticated user; the list itself is filtered to the tours they can access.
    Route::get('/guided-tours', [\App\Http\Controllers\CoreX\GuidedToursController::class, 'index'])->name('corex.guided-tours.index');

    // Settings (admin only)
    Route::get('/settings', [CoreXSettingsController::class, 'index'])->middleware(['permission:access_settings', 'agency.required'])->name('corex.settings');

    // Agency Onboarding Setup Wizard (authenticated; token gate handed off here
    // after login). Reuses the settings save paths. Spec: agency-onboarding-setup.md
    Route::middleware(['permission:agency_setup.run', 'agency.required'])->group(function () {
        Route::get('/agency-setup', [\App\Http\Controllers\CoreX\AgencySetupWizardController::class, 'index'])->name('corex.agency-setup.index');
        Route::get('/agency-setup/step/{step}', [\App\Http\Controllers\CoreX\AgencySetupWizardController::class, 'show'])->name('corex.agency-setup.step');
        Route::post('/agency-setup/step/{step}', [\App\Http\Controllers\CoreX\AgencySetupWizardController::class, 'save'])->name('corex.agency-setup.step.save');
        Route::post('/agency-setup/step/{step}/skip', [\App\Http\Controllers\CoreX\AgencySetupWizardController::class, 'skip'])->name('corex.agency-setup.step.skip');
        Route::post('/agency-setup/finish', [\App\Http\Controllers\CoreX\AgencySetupWizardController::class, 'finish'])->name('corex.agency-setup.finish');
        // Inline list editors (property types/statuses/mandate/condition, contact sources)
        Route::post('/agency-setup/collection/{collection}', [\App\Http\Controllers\CoreX\AgencySetupWizardController::class, 'addCollectionItem'])->name('corex.agency-setup.collection.add');
        Route::delete('/agency-setup/collection/{collection}/{id}', [\App\Http\Controllers\CoreX\AgencySetupWizardController::class, 'removeCollectionItem'])->name('corex.agency-setup.collection.remove');
    });
    Route::post('/settings/generate-token', [CoreXSettingsController::class, 'generateApiToken'])->name('corex.settings.generate-token');
    Route::post('/settings/notifications', [CoreXSettingsController::class, 'updateNotificationPreferences'])->middleware('permission:access_settings')->name('corex.settings.notifications.update');
    Route::post('/settings/my-portal', [CoreXSettingsController::class, 'updatePortalPreferences'])->middleware('permission:access_settings')->name('corex.settings.my-portal.update');
    Route::post('/settings/marketing-enabled', [CoreXSettingsController::class, 'updateMarketingEnabled'])->middleware('permission:access_settings')->name('corex.settings.marketing-enabled');
    Route::post('/settings/syndication-portals', [CoreXSettingsController::class, 'updateSyndicationPortals'])->middleware('permission:access_settings')->name('corex.settings.syndication-portals');
    Route::post('/settings/presentations', [CoreXSettingsController::class, 'updatePresentations'])->middleware('permission:access_settings')->name('corex.settings.presentations.update');
    // Build 4 — agency default toggles for which report sections render.
    Route::post('/settings/presentations/sections', [CoreXSettingsController::class, 'updatePresentationSections'])->middleware('permission:access_settings')->name('corex.settings.presentations.sections.update');
    Route::post('/settings/matches-enabled', [CoreXSettingsController::class, 'updateMatchesEnabled'])->middleware('permission:access_settings')->name('corex.settings.matches-enabled');
    Route::post('/settings/matches-wa-message', [CoreXSettingsController::class, 'updateMatchesWaMessage'])->middleware('permission:access_settings')->name('corex.settings.matches-wa-message');
    Route::post('/settings/matches-show-on-properties', [CoreXSettingsController::class, 'updateMatchesShowOnProperties'])->middleware('permission:access_settings')->name('corex.settings.matches-show-on-properties');
    Route::post('/settings/matches-visibility-scope', [CoreXSettingsController::class, 'updateMatchesVisibilityScope'])->middleware('permission:access_settings')->name('corex.settings.matches-visibility-scope');
    Route::post('/settings/contacts-per-page', [CoreXSettingsController::class, 'updateContactsPerPage'])->middleware('permission:access_settings')->name('corex.settings.contacts-per-page');
    Route::post('/settings/properties-per-page', [CoreXSettingsController::class, 'updatePropertiesPerPage'])->middleware('permission:access_settings')->name('corex.settings.properties-per-page');
    Route::post('/settings/filing-register-per-page', [CoreXSettingsController::class, 'updateFilingRegisterPerPage'])->middleware('permission:access_settings')->name('corex.settings.filing-register-per-page');
    Route::post('/settings/properties-sort', [CoreXSettingsController::class, 'updatePropertiesSort'])->middleware('permission:access_settings')->name('corex.settings.properties-sort');
    Route::post('/settings/remote-access', [CoreXSettingsController::class, 'updateRemoteAccess'])->middleware('permission:agency.manage_access_authorization')->name('corex.settings.remote-access');
    // Old compliance-officers endpoint — kept for backwards compat, redirects
    Route::post('/settings/compliance-officers', function () {
        return redirect('/corex/settings?tab=user');
    })->middleware('permission:access_settings')->name('corex.settings.compliance-officers');

    // ── Prospecting Setup (per-agency configuration of towns / property types / bedroom segments / price bands) ──
    // Spec: .ai/specs/prospecting-setup-spec.md Section 6.
    Route::prefix('settings/prospecting')
        ->middleware('permission:prospecting_setup.manage')
        ->name('settings.prospecting.')
        ->group(function () {
            Route::get('/',                                       [\App\Http\Controllers\Settings\Prospecting\TownsController::class, 'index'])->name('index');

            // Build-from-Web helper (curated SA region library) — spec S4.
            Route::get('/suggestions/{regionKey}',                [\App\Http\Controllers\Settings\Prospecting\TownsController::class, 'suggestions'])->name('suggestions');
            Route::post('/towns/bulk-import',                     [\App\Http\Controllers\Settings\Prospecting\TownsController::class, 'bulkImport'])->name('towns.bulk-import');

            Route::post('/towns',                                 [\App\Http\Controllers\Settings\Prospecting\TownsController::class, 'store'])->name('towns.store');
            Route::put('/towns/{town}',                           [\App\Http\Controllers\Settings\Prospecting\TownsController::class, 'update'])->name('towns.update');
            Route::post('/towns/{town}/archive',                  [\App\Http\Controllers\Settings\Prospecting\TownsController::class, 'archive'])->name('towns.archive');
            Route::post('/towns/{townId}/restore',                [\App\Http\Controllers\Settings\Prospecting\TownsController::class, 'restore'])->name('towns.restore');
            Route::post('/towns/reorder',                         [\App\Http\Controllers\Settings\Prospecting\TownsController::class, 'reorder'])->name('towns.reorder');

            Route::post('/towns/{town}/suburbs',                  [\App\Http\Controllers\Settings\Prospecting\SuburbsController::class, 'store'])->name('suburbs.store');
            Route::put('/suburbs/{suburb}',                       [\App\Http\Controllers\Settings\Prospecting\SuburbsController::class, 'update'])->name('suburbs.update');
            Route::post('/suburbs/{suburb}/archive',              [\App\Http\Controllers\Settings\Prospecting\SuburbsController::class, 'archive'])->name('suburbs.archive');
            // One-click cleanup of unmapped suburbs surfaced on the Towns tab.
            Route::post('/suburbs/map',                           [\App\Http\Controllers\Settings\Prospecting\TownsController::class, 'mapSuburb'])->name('suburbs.map');


            Route::post('/property-types',                        [\App\Http\Controllers\Settings\Prospecting\PropertyTypesController::class, 'store'])->name('property-types.store');
            Route::put('/property-types/{type}',                  [\App\Http\Controllers\Settings\Prospecting\PropertyTypesController::class, 'update'])->name('property-types.update');
            Route::post('/property-types/{type}/archive',         [\App\Http\Controllers\Settings\Prospecting\PropertyTypesController::class, 'archive'])->name('property-types.archive');
            Route::post('/property-types/{type}/toggle',          [\App\Http\Controllers\Settings\Prospecting\PropertyTypesController::class, 'toggleActive'])->name('property-types.toggle');
            Route::post('/property-types/reorder',                [\App\Http\Controllers\Settings\Prospecting\PropertyTypesController::class, 'reorder'])->name('property-types.reorder');

            Route::post('/bedroom-segments',                      [\App\Http\Controllers\Settings\Prospecting\BedroomSegmentsController::class, 'store'])->name('bedroom-segments.store');
            Route::put('/bedroom-segments/{segment}',             [\App\Http\Controllers\Settings\Prospecting\BedroomSegmentsController::class, 'update'])->name('bedroom-segments.update');
            Route::post('/bedroom-segments/{segment}/archive',    [\App\Http\Controllers\Settings\Prospecting\BedroomSegmentsController::class, 'archive'])->name('bedroom-segments.archive');
            Route::post('/bedroom-segments/reorder',              [\App\Http\Controllers\Settings\Prospecting\BedroomSegmentsController::class, 'reorder'])->name('bedroom-segments.reorder');

            Route::post('/price-bands',                           [\App\Http\Controllers\Settings\Prospecting\PriceBandsController::class, 'store'])->name('price-bands.store');
            Route::put('/price-bands/{band}',                     [\App\Http\Controllers\Settings\Prospecting\PriceBandsController::class, 'update'])->name('price-bands.update');
            Route::post('/price-bands/{band}/archive',            [\App\Http\Controllers\Settings\Prospecting\PriceBandsController::class, 'archive'])->name('price-bands.archive');
            Route::post('/price-bands/reorder',                   [\App\Http\Controllers\Settings\Prospecting\PriceBandsController::class, 'reorder'])->name('price-bands.reorder');

            // Buyer-match tier thresholds — single PUT per agency.
            Route::put('/buyer-match-tiers',                      [\App\Http\Controllers\Settings\Prospecting\BuyerMatchTiersController::class, 'update'])->name('buyer-match-tiers.update');
        });

    // ── Seller Outreach Templates (per-agency template CRUD) ──
    // Spec: .ai/specs/seller-outreach-spec.md S4, 6.3.
    Route::prefix('settings/outreach-templates')
        ->middleware('permission:outreach_templates.manage')
        ->name('settings.outreach-templates.')
        ->group(function () {
            Route::get('/',                       [\App\Http\Controllers\Settings\SellerOutreach\TemplatesController::class, 'index'])->name('index');
            Route::post('/',                      [\App\Http\Controllers\Settings\SellerOutreach\TemplatesController::class, 'store'])->name('store');
            Route::put('/{template}',             [\App\Http\Controllers\Settings\SellerOutreach\TemplatesController::class, 'update'])->name('update');
            Route::post('/{template}/archive',    [\App\Http\Controllers\Settings\SellerOutreach\TemplatesController::class, 'archive'])->name('archive');
            Route::post('/{templateId}/restore',  [\App\Http\Controllers\Settings\SellerOutreach\TemplatesController::class, 'restore'])->name('restore');
        });

    // ── Seller Outreach Composer (agent-facing pitch composer) ──
    // Spec: .ai/specs/seller-outreach-spec.md S6, 6.1.
    Route::prefix('contacts/{contact}/outreach')
        ->middleware('permission:outreach.compose')
        ->name('seller-outreach.composer.')
        ->group(function () {
            Route::get('/compose',     [\App\Http\Controllers\SellerOutreach\ComposerController::class, 'show'])->name('show');
            Route::post('/send',       [\App\Http\Controllers\SellerOutreach\ComposerController::class, 'submit'])->name('submit');
            // AT-117 §4 — add the prepared pitch to the deferred outreach queue (due-time, in-window).
            Route::post('/queue',      [\App\Http\Controllers\SellerOutreach\ComposerController::class, 'queue'])->name('queue');
            Route::get('/sent/{send}', [\App\Http\Controllers\SellerOutreach\ComposerController::class, 'sent'])->name('sent');

            // Timeline (Prompt 07) — agent-side view of every send + click + opt-out
            Route::get('/timeline',                       [\App\Http\Controllers\SellerOutreach\ContactTimelineController::class, 'index'])->name('timeline');
            Route::post('/timeline/sends/{send}/outcome', [\App\Http\Controllers\SellerOutreach\ContactTimelineController::class, 'updateOutcome'])->name('outcome');
            Route::post('/timeline/opt-out',              [\App\Http\Controllers\SellerOutreach\ContactTimelineController::class, 'recordOptOut'])->name('opt-out');
            // AT-45 — explicit opt-in marker (recorded fact; does NOT change the send gate)
            Route::post('/timeline/opt-in',               [\App\Http\Controllers\SellerOutreach\ContactTimelineController::class, 'recordOptIn'])->name('opt-in');
        });

    // ── Seller Outreach Entry Points (Prompt 08) ──
    // Property + prospecting-listing → composer redirects.
    // Contact pillar entry point is a direct link from the contact page (no controller action).
    Route::middleware('permission:outreach.compose')
        ->name('seller-outreach.entry.')
        ->group(function () {
            Route::get('/properties/{property}/outreach/compose',
                [\App\Http\Controllers\SellerOutreach\EntryPointController::class, 'fromProperty'])
                ->name('from-property');
            Route::get('/prospecting/{prospectingListingId}/outreach/compose',
                [\App\Http\Controllers\SellerOutreach\EntryPointController::class, 'fromProspecting'])
                ->where('prospectingListingId', '\d+')
                ->name('from-prospecting');
            Route::post('/prospecting/{prospectingListingId}/outreach/compose',
                [\App\Http\Controllers\SellerOutreach\EntryPointController::class, 'storeFromProspecting'])
                ->where('prospectingListingId', '\d+')
                ->name('store-from-prospecting');

            // Map Workspace Phase B (Fix 2+3) — T-pin WhatsApp / Pitch flow.
            // Mirrors the prospecting entry point but the source is a
            // TrackedProperty (no portal listing). Route model binding pulls
            // the global agency scope via the BelongsToAgency trait.
            Route::get('/tracked-properties/{trackedProperty}/outreach/compose',
                [\App\Http\Controllers\SellerOutreach\EntryPointController::class, 'fromTrackedProperty'])
                ->name('from-tracked-property');
            Route::post('/tracked-properties/{trackedProperty}/outreach/compose',
                [\App\Http\Controllers\SellerOutreach\EntryPointController::class, 'storeFromTrackedProperty'])
                ->name('store-from-tracked-property');
        });

    // ── Whistleblower Settings ──
    Route::post('/settings/whistleblow', [CoreXSettingsController::class, 'saveWhistleblowSettings'])->middleware('permission:compliance.whistleblow.configure')->name('corex.settings.whistleblow.save');

    // ── FICA Officer Appointments (unified) ──
    Route::post('/settings/fica-officers/primary', [\App\Http\Controllers\Compliance\FicaOfficerAppointmentsController::class, 'savePrimary'])
        ->middleware('permission:manage_compliance_officer')->name('corex.settings.fica-officers.primary');
    Route::post('/settings/fica-officers/mlros', [\App\Http\Controllers\Compliance\FicaOfficerAppointmentsController::class, 'saveMlros'])
        ->middleware('permission:manage_compliance_officer')->name('corex.settings.fica-officers.mlros');
    Route::post('/settings/fica-officers/{appointment}/end', [\App\Http\Controllers\Compliance\FicaOfficerAppointmentsController::class, 'endAppointment'])
        ->middleware('permission:manage_compliance_officer')->name('corex.settings.fica-officers.end');
    Route::post('/settings/fica-referral', [\App\Http\Controllers\Compliance\FicaOfficerAppointmentsController::class, 'saveReferralSettings'])
        ->middleware('permission:manage_compliance_officer')->name('corex.settings.fica-referral.save');

    // ── Phase 9c-2 — Information Officer Appointments (POPIA s55) ──
    Route::post('/settings/information-officers/primary', [\App\Http\Controllers\Compliance\InformationOfficerAppointmentsController::class, 'savePrimary'])
        ->middleware('permission:manage_information_officer')->name('corex.settings.information-officers.primary');
    Route::post('/settings/information-officers/deputies', [\App\Http\Controllers\Compliance\InformationOfficerAppointmentsController::class, 'saveDeputies'])
        ->middleware('permission:manage_information_officer')->name('corex.settings.information-officers.deputies');
    Route::post('/settings/information-officers/{appointment}/end', [\App\Http\Controllers\Compliance\InformationOfficerAppointmentsController::class, 'endAppointment'])
        ->middleware('permission:manage_information_officer')->name('corex.settings.information-officers.end');
    Route::put('/settings/agency', [CoreXSettingsController::class, 'updateAgency'])->middleware('permission:access_settings')->name('corex.settings.agency.update');

    // Split Branches toggle (Agency Settings tab)
    Route::put('/settings/agency/split-branches', [CoreXSettingsController::class, 'updateSplitBranches'])
        ->middleware('permission:manage_performance_settings')->name('corex.settings.split-branches');

    // AT-267 — Assistants toggle (Agency Settings tab). This is the control the Assistants
    // admin page points at when the feature is off, and the one the Setup Wizard writes.
    Route::put('/settings/agency/assistants', [CoreXSettingsController::class, 'updateAssistants'])
        ->middleware('permission:manage_performance_settings')->name('corex.settings.assistants');
    Route::get('/settings/preview-header', [CoreXSettingsController::class, 'previewHeader'])->middleware('permission:access_settings')->name('corex.settings.preview-header');
    Route::get('/settings/preview-signature', [CoreXSettingsController::class, 'previewSignature'])->middleware('permission:access_settings')->name('corex.settings.preview-signature');

    // Dashboard Settings (Feature Settings > Dashboard)
    Route::put('/settings/dashboard/mode', [CoreXSettingsController::class, 'updateDashboardMode'])->middleware('permission:access_settings')->name('corex.settings.dashboard.mode');
    Route::put('/settings/dashboard/agency', [CoreXSettingsController::class, 'updateAgencyDashboardSettings'])->middleware('permission:access_settings')->name('corex.settings.dashboard.agency');

    // Commission & Revenue Share Settings
    Route::get('/settings/commission', [\App\Http\Controllers\Commission\CommissionSettingsController::class, 'edit'])
        ->middleware('permission:access_settings')->name('corex.settings.commission');
    Route::post('/settings/commission', [\App\Http\Controllers\Commission\CommissionSettingsController::class, 'update'])
        ->middleware('permission:access_settings')->name('corex.settings.commission.update');

    // Role Manager — roles & permissions are agency-scoped, so an owner with
    // no active agency context is redirected to the agency picker
    // (agency.required). Non-owner admins always have a context → pass through.
    Route::get('/role-manager', [CoreXRoleManagerController::class, 'index'])->middleware(['permission:access_role_manager', 'agency.required'])->name('corex.role-manager');
    Route::post('/role-manager/permissions', [CoreXRoleManagerController::class, 'savePermissions'])
        ->middleware(['permission:edit_permissions', 'agency.required'])->name('corex.role-manager.save');
    Route::post('/role-manager/user-role', [CoreXRoleManagerController::class, 'updateUserRole'])
        ->middleware(['permission:change_user_roles', 'agency.required'])->name('corex.role-manager.user-role');
    // Role CRUD
    Route::post('/role-manager/roles', [CoreXRoleManagerController::class, 'storeRole'])
        ->middleware(['permission:edit_permissions', 'agency.required'])->name('corex.role-manager.roles.store');
    Route::put('/role-manager/roles/{role}', [CoreXRoleManagerController::class, 'updateRole'])
        ->middleware(['permission:edit_permissions', 'agency.required'])->name('corex.role-manager.roles.update');
    Route::delete('/role-manager/roles/{role}', [CoreXRoleManagerController::class, 'destroyRole'])
        ->middleware(['permission:edit_permissions', 'agency.required'])->name('corex.role-manager.roles.destroy');
    Route::post('/role-manager/copy-permissions', [CoreXRoleManagerController::class, 'copyPermissions'])
        ->middleware(['permission:edit_permissions', 'agency.required'])->name('corex.role-manager.copy');

    // Integrations — System Developer hub for external platform connections
    // (Meta/Facebook OAuth config + public legal page URLs). Owner-only.
    Route::middleware('owner_only')->prefix('admin/integrations')->name('admin.integrations.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\IntegrationsController::class, 'index'])->name('index');
    });

    // Dev Settings — system-wide developer overrides (owner-only).
    Route::middleware('owner_only')->prefix('admin/dev-settings')->name('admin.dev-settings.')->group(function () {
        Route::get('/',  [\App\Http\Controllers\Admin\DevSettingsController::class, 'index'])->name('index');
        Route::put('/', [\App\Http\Controllers\Admin\DevSettingsController::class, 'update'])->name('update');
        // Demo sidebar curation — its own page (linked under the demo-mode
        // toggle) + the save endpoint. Controls which sidebar items
        // demo-agency members see.
        Route::get('/demo-sidebar', [\App\Http\Controllers\Admin\DevSettingsController::class, 'demoSidebar'])->name('demo-sidebar');
        Route::put('/demo-sidebar', [\App\Http\Controllers\Admin\DevSettingsController::class, 'updateDemoSidebar'])->name('demo-sidebar.update');
    });

    // ── Demo Access Control (AT-230) — system-owner sales tooling. ──
    //
    // owner_only, and deliberately NO permission key in corex-permissions.php.
    // This is the list of companies evaluating CoreX — including agencies who
    // compete with each other. A permission key is GRANTABLE; one mis-click in the
    // Role Manager and an agency admin is reading it. owner_only has no delegation
    // path. That is the stronger gate, not a skipped one — see spec §8 for why this
    // satisfies rather than violates non-negotiable #5.
    //
    // Every action ALSO calls abort_unless($user->isOwnerRole(), 403) — belt,
    // braces, and the sidebar's own owner gate.
    //
    // Spec: .ai/specs/demo-access-control.md §8, §9
    Route::middleware('owner_only')->prefix('admin/dev-settings/demo-access')->name('admin.demo-access.')->group(function () {
        // T&C + connection routes come FIRST — otherwise they are swallowed by /{grant}.
        Route::get('/tnc',  [\App\Http\Controllers\Admin\DemoAccessController::class, 'tnc'])->name('tnc');
        Route::post('/tnc', [\App\Http\Controllers\Admin\DemoAccessController::class, 'publishTnc'])->name('tnc.publish');

        // The universal connector — minted HERE (on live), pasted into the demo.
        Route::get('/connection',         [\App\Http\Controllers\Admin\DemoAccessController::class, 'connection'])->name('connection');
        Route::post('/connection',        [\App\Http\Controllers\Admin\DemoAccessController::class, 'mintConnector'])->name('connection.mint');
        Route::post('/connection/revoke', [\App\Http\Controllers\Admin\DemoAccessController::class, 'revokeConnector'])->name('connection.revoke');

        Route::post('/reset', [\App\Http\Controllers\Admin\DemoAccessController::class, 'reset'])->name('reset');

        Route::get('/',       [\App\Http\Controllers\Admin\DemoAccessController::class, 'index'])->name('index');
        Route::get('/create', [\App\Http\Controllers\Admin\DemoAccessController::class, 'create'])->name('create');
        Route::post('/',      [\App\Http\Controllers\Admin\DemoAccessController::class, 'store'])->name('store');

        Route::get('/{grant}',           [\App\Http\Controllers\Admin\DemoAccessController::class, 'show'])->whereNumber('grant')->name('show');
        Route::get('/{grant}/edit',      [\App\Http\Controllers\Admin\DemoAccessController::class, 'edit'])->whereNumber('grant')->name('edit');
        Route::put('/{grant}',           [\App\Http\Controllers\Admin\DemoAccessController::class, 'update'])->whereNumber('grant')->name('update');
        Route::post('/{grant}/revoke',   [\App\Http\Controllers\Admin\DemoAccessController::class, 'revoke'])->whereNumber('grant')->name('revoke');
        Route::post('/{grant}/restore',  [\App\Http\Controllers\Admin\DemoAccessController::class, 'restore'])->whereNumber('grant')->name('restore');
        // "Delete" archives. The row is never removed (non-negotiable #1).
        Route::delete('/{grant}',        [\App\Http\Controllers\Admin\DemoAccessController::class, 'destroy'])->whereNumber('grant')->name('destroy');
    });

    // ── Demo Connection (AT-230) — the DEMO side of the link. ──
    //
    // Where a System Owner signed in on demo1.corexos.co.za pastes the CoreX URL +
    // the connector token minted on live. 404s on primary (nothing to configure
    // there — the connector is minted there instead).
    //
    // Reachable even when the connector is BROKEN: EnsureDemoGrant exempts
    // demo-owner-login and bypasses any signed-in owner on a local role check, so a
    // bad paste can always be undone from the browser. Without that, the fail-closed
    // gate would make the connection its own prerequisite.
    //
    // Spec: .ai/specs/demo-access-control.md §5.2
    Route::middleware('owner_only')->prefix('admin/dev-settings/demo-connection')->name('admin.demo-connection.')->group(function () {
        Route::get('/',      [\App\Http\Controllers\Admin\DemoConnectionController::class, 'edit'])->name('edit');
        Route::put('/',      [\App\Http\Controllers\Admin\DemoConnectionController::class, 'update'])->name('update');
        Route::post('/test', [\App\Http\Controllers\Admin\DemoConnectionController::class, 'test'])->name('test');
    });

    // Developer Users — System Owner / Developer roster, visible across all
    // agencies (cross-agency owner view). See .ai/specs/developer-users.md.
    Route::middleware('owner_only')->prefix('admin/developer-users')->name('admin.developer-users.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\DeveloperUserController::class, 'index'])->name('index');
        Route::post('/{userId}/toggle', [\App\Http\Controllers\Admin\DeveloperUserController::class, 'toggleActive'])->name('toggle');
    });

    // Backups (AT-163) — off-box restic backup status/health/history + audited
    // password reveal. Permission-gated (view_backups); the reveal is a SEPARATE
    // principal-only permission. System Developer area.
    Route::prefix('admin/backups')->name('admin.backups.')->group(function () {
        Route::middleware('permission:view_backups')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\BackupController::class, 'index'])->name('index');
            Route::put('/threshold', [\App\Http\Controllers\Admin\BackupController::class, 'updateThreshold'])->name('threshold');
        });
        Route::middleware('permission:reveal_backup_password')
            ->post('/reveal', [\App\Http\Controllers\Admin\BackupController::class, 'reveal'])->name('reveal');
    });

    // Server Health Monitor (System Developer) — read-only live vitals page + a
    // cheap JSON snapshot the page polls (~10s). The data endpoint lives under
    // /api/v1/* so it appears in the Admin → API catalog (Non-Negotiable #7).
    Route::middleware('permission:view_server_health')->group(function () {
        Route::get('/admin/system-health', [\App\Http\Controllers\Admin\ServerHealthController::class, 'index'])->name('admin.system-health.index');
        Route::get('/api/v1/system-health', [\App\Http\Controllers\Admin\ServerHealthController::class, 'data'])->name('api.v1.system-health');
    });

    // Agency Management — index/create/store/destroy/toggle-active/toggle-maintenance are owner-only.
    Route::middleware('owner_only')->prefix('settings/agencies')->name('agencies.')->group(function () {
        Route::get('/',              [\App\Http\Controllers\Admin\AgencyController::class, 'index'])->name('index');
        Route::get('/create',        [\App\Http\Controllers\Admin\AgencyController::class, 'create'])->name('create');
        Route::post('/',             [\App\Http\Controllers\Admin\AgencyController::class, 'store'])->name('store');
        Route::post('/{agency}/toggle-active', [\App\Http\Controllers\Admin\AgencyController::class, 'toggleActive'])->name('toggle-active');
        // Per-agency maintenance mode (AT-93). Owner-only; owners bypass the
        // gate so they can always lift it. Spec: .ai/specs/maintenance-mode.md
        Route::post('/{agency}/toggle-maintenance', [\App\Http\Controllers\Admin\AgencyController::class, 'toggleMaintenance'])->name('toggle-maintenance');
        Route::delete('/{agency}',   [\App\Http\Controllers\Admin\AgencyController::class, 'destroy'])->name('destroy');
    });

    // Agency Setup Progress board — platform-owner cross-agency tracking of the
    // onboarding wizard. Owner-only. Spec: agency-onboarding-setup.md §7.4.
    Route::middleware('owner_only')->get('/admin/agency-setup-progress', [\App\Http\Controllers\Admin\AgencySetupProgressController::class, 'index'])
        ->name('admin.agency-setup-progress');

    // Agency edit/update — accessible to admins with manage_performance_settings.
    // Controller enforces own-agency scope unless the user is an owner.
    Route::middleware('permission:manage_performance_settings')->prefix('settings/agencies')->name('agencies.')->group(function () {
        Route::get('/{agency}/edit', [\App\Http\Controllers\Admin\AgencyController::class, 'edit'])->name('edit');
        Route::put('/{agency}',      [\App\Http\Controllers\Admin\AgencyController::class, 'update'])->name('update');
        Route::post('/{agency}/p24/test',    [\App\Http\Controllers\Admin\AgencyController::class, 'testP24Connection'])->name('p24.test');
        Route::post('/{agency}/p24/refresh', [\App\Http\Controllers\Admin\AgencyController::class, 'refreshP24Locations'])->name('p24.refresh');
        Route::post('/{agency}/pp/test',     [\App\Http\Controllers\Admin\AgencyController::class, 'testPpConnection'])->name('pp.test');

        // Agency Public API — website API key management + master live switch.
        // Spec: .ai/specs/agency-public-api.md §7.1, §8.
        Route::middleware('permission:agency_api.manage')->group(function () {
            Route::post('/{agency}/website-toggle', [\App\Http\Controllers\Admin\AgencyApiKeyController::class, 'toggleWebsite'])->name('website.toggle');
            Route::post('/{agency}/agents/publish-all', [\App\Http\Controllers\Admin\AgencyApiKeyController::class, 'publishAllAgents'])->name('agents.publish-all');
            Route::post('/{agency}/api-keys',                  [\App\Http\Controllers\Admin\AgencyApiKeyController::class, 'store'])->name('api-keys.store');
            Route::put('/{agency}/api-keys/{apiKey}',          [\App\Http\Controllers\Admin\AgencyApiKeyController::class, 'update'])->name('api-keys.update');
            Route::post('/{agency}/api-keys/{apiKey}/regenerate', [\App\Http\Controllers\Admin\AgencyApiKeyController::class, 'regenerate'])->name('api-keys.regenerate');
            Route::post('/{agency}/api-keys/{apiKey}/revoke',  [\App\Http\Controllers\Admin\AgencyApiKeyController::class, 'revoke'])->name('api-keys.revoke');
            Route::post('/{agency}/api-keys/{apiKey}/bulk-activate', [\App\Http\Controllers\Admin\AgencyApiKeyController::class, 'bulkActivate'])->name('api-keys.bulk-activate');
            Route::delete('/{agency}/api-keys/{apiKey}',       [\App\Http\Controllers\Admin\AgencyApiKeyController::class, 'destroy'])->name('api-keys.destroy');
        });
    });

    // Company Settings (standalone admin page — separate from tabbed settings)
    Route::get('/admin/company-settings',
        [\App\Http\Controllers\Admin\CompanySettingsController::class, 'index'])
        ->middleware('permission:manage_performance_settings')
        ->name('admin.company-settings');
    Route::put('/admin/company-settings/{agency}',
        [\App\Http\Controllers\Admin\CompanySettingsController::class, 'update'])
        ->middleware('permission:manage_performance_settings')
        ->name('admin.company-settings.update');
    // Agency Public API — Website tab (own form/route). Spec: agency-public-api.md §7.4.
    Route::put('/admin/company-settings/{agency}/website',
        [\App\Http\Controllers\Admin\CompanySettingsController::class, 'updateWebsite'])
        ->middleware('permission:manage_performance_settings')
        ->name('admin.company-settings.website.update');
    // Push every SOLD listing in the agency to its website(s) at once.
    Route::post('/admin/company-settings/{agency}/push-sold',
        [\App\Http\Controllers\Admin\CompanySettingsController::class, 'pushSoldToWebsite'])
        ->middleware('permission:manage_performance_settings')
        ->name('admin.company-settings.push-sold');
    // Testimonials — publish/unpublish a captured testimonial to the website.
    // Spec: .ai/specs/testimonials.md §7.
    Route::patch('/admin/company-settings/{agency}/testimonials/{testimonial}/publish',
        [\App\Http\Controllers\Admin\CompanySettingsController::class, 'toggleTestimonial'])
        ->middleware('permission:testimonials.publish')
        ->name('admin.company-settings.testimonials.toggle');


    // SPINE-SETTINGS — Activity scoring (full catalogue: calendar +
    // every SPINE-1/2/3/2.5 instant slug). Replaces the M6.2 calendar-
    // only screen. store/destroy routes are intentionally dropped —
    // catalogue rows are now seeded; the screen edits per-agency
    // weight + active state only.
    Route::prefix('admin/activity-mappings')->name('admin.activity-mappings.')->group(function () {
        Route::get('/',                       [\App\Http\Controllers\Admin\ActivityCalendarMappingController::class, 'index'])->name('index');
        Route::put('/{id}',                   [\App\Http\Controllers\Admin\ActivityCalendarMappingController::class, 'update'])->whereNumber('id')->name('update');
        Route::post('/{id}/toggle-active',    [\App\Http\Controllers\Admin\ActivityCalendarMappingController::class, 'toggleActive'])->whereNumber('id')->name('toggle-active');
    });

    // Properties — listing sync to website
    // AT-267 — `deny_assistant_property_write` guards the WHOLE group, not a hand-picked list
    // of routes. An assistant may never create or import a listing, and several creation paths
    // in here carry no permission key at all (the classic store, every wizard mutation, the
    // sold-CSV and p24-fix uploads). Gating the group means a property-write route added later
    // is covered by DEFAULT: it fails closed until someone deliberately adds it to the
    // middleware's ASSISTANT_MAY allow list. Reads and the allow-listed edits pass straight
    // through — an assistant is supposed to work the agent's listings, just not create them.
    Route::prefix('properties')->middleware(['permission:access_properties', 'agency.required', 'deny_assistant_property_write'])->name('corex.properties.')->group(function () {
        // Marketing compliance — go live
        Route::post('/{property}/go-live', [\App\Http\Controllers\CoreX\PropertyController::class, 'goLive'])->name('go-live');

        // Presentations V2 — one-button generator (Phase 1) + coverage scorer (Phase 2)
        Route::post('/{property}/generate-presentation', [\App\Http\Controllers\Presentation\PresentationGeneratorController::class, 'generate'])
            ->name('generate-presentation');
        Route::get('/{property}/presentation-coverage', [\App\Http\Controllers\Presentation\PresentationGeneratorController::class, 'coverage'])
            ->name('presentation-coverage');

        // Phase 3j — SG document integration (server-side proxy + save to drive).
        // The /search endpoint is the only one that may HTTP out to SG; rate
        // limit it per-user (30/hr) and per-agency at the controller via cache.
        Route::post('/{property}/sg/search', [\App\Http\Controllers\CoreX\PropertySgController::class, 'search'])
            ->middleware('throttle:30,60')
            ->name('sg.search');
        Route::post('/{property}/sg/save-all', [\App\Http\Controllers\CoreX\PropertySgController::class, 'saveAll'])
            ->middleware('throttle:30,60')
            ->name('sg.save-all');
        Route::post('/{property}/sg/documents/{sgDoc}/save', [\App\Http\Controllers\CoreX\PropertySgController::class, 'saveDocument'])
            ->middleware('throttle:60,60')
            ->name('sg.save-document');
        Route::get('/{property}/sg/documents/{sgDoc}/download', [\App\Http\Controllers\CoreX\PropertySgController::class, 'download'])
            ->name('sg.download');

        // Seller Live Links — agent management
        Route::post('/seller-links/generate', [\App\Http\Controllers\SellerLinkController::class, 'generate'])->name('seller-links.generate');
        Route::post('/seller-links/{link}/revoke', [\App\Http\Controllers\SellerLinkController::class, 'revoke'])->name('seller-links.revoke');

        // Mark as Sold
        Route::post('/mark-sold', function (\Illuminate\Http\Request $request) {
            $data = $request->validate([
                'property_id' => 'required|integer|exists:properties,id',
                'sold_price' => 'required|numeric|min:0',
                'sold_date' => 'required|date',
                'listing_price_at_sale' => 'nullable|numeric',
                'notes' => 'nullable|string|max:1000',
            ]);
            $property = \App\Models\Property::withoutGlobalScopes()->findOrFail($data['property_id']);
            $dom = $property->published_at ? (int) $property->published_at->diffInDays(now()) : null;

            \Illuminate\Support\Facades\DB::table('property_sold_records')->insert([
                'property_id' => $property->id,
                'address' => $property->title,
                'suburb' => $property->suburb,
                'sold_price' => $data['sold_price'],
                'sold_date' => $data['sold_date'],
                'listing_price_at_sale' => $data['listing_price_at_sale'] ?? $property->price,
                'days_on_market' => $dom,
                'property_type' => $property->property_type,
                'source' => 'manual',
                'captured_by_user_id' => auth()->id(),
                'captured_at' => now(),
                'agency_id' => $property->agency_id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $property->update(['status' => 'sold']);

            \App\Models\PropertyMarketingActivity::create([
                'property_id' => $property->id,
                'activity_type' => 'other',
                'activity_data' => ['action' => 'marked_sold', 'sold_price' => $data['sold_price']],
                'occurred_at' => now(),
                'logged_by_user_id' => auth()->id(),
            ]);

            return back()->with('success', 'Property marked as sold. Sold record created.');
        })->name('mark-sold');

        // Marketing Activity — manual logging
        Route::post('/marketing-activity', function (\Illuminate\Http\Request $request) {
            $data = $request->validate([
                'property_id' => 'required|integer|exists:properties,id',
                'activity_type' => 'required|string|max:50',
                'notes' => 'nullable|string|max:1000',
                'occurred_at' => 'nullable|date',
                'internal_only' => 'nullable|boolean',
            ]);
            \App\Models\PropertyMarketingActivity::create([
                'property_id' => $data['property_id'],
                'activity_type' => $data['activity_type'],
                'activity_data' => $data['notes'] ? ['notes' => $data['notes']] : null,
                'occurred_at' => $data['occurred_at'] ?? now(),
                'logged_by_user_id' => auth()->id(),
                'internal_only' => $data['internal_only'] ?? false,
            ]);
            return back()->with('success', 'Marketing activity logged.');
        })->name('marketing-activity.store');

        // Property Intelligence Hub — recommendation actions
        Route::post('/recommendations/{id}/action', function (\Illuminate\Http\Request $request, int $id) {
            $rec = \Illuminate\Support\Facades\DB::table('property_recommendations')->where('id', $id)->first();
            if (!$rec) abort(404);
            $action = $request->input('action'); // 'actioned' or 'dismissed'
            if ($action === 'actioned') {
                \Illuminate\Support\Facades\DB::table('property_recommendations')->where('id', $id)->update(['actioned_at' => now(), 'actioned_by' => auth()->id()]);
            } elseif ($action === 'dismissed') {
                \Illuminate\Support\Facades\DB::table('property_recommendations')->where('id', $id)->update(['dismissed_at' => now(), 'dismissed_by' => auth()->id()]);
            } elseif ($action === 'toggle_seller_visible') {
                $current = (bool) $rec->seller_visible;
                \Illuminate\Support\Facades\DB::table('property_recommendations')->where('id', $id)->update(['seller_visible' => !$current]);
            }
            return $request->wantsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Recommendation updated.');
        })->name('recommendations.action');

        Route::get('/',                        [\App\Http\Controllers\CoreX\PropertyController::class, 'index'])->name('index');
        Route::get('/create',                  [\App\Http\Controllers\CoreX\PropertyController::class, 'create'])->name('create');
        Route::post('/',                       [\App\Http\Controllers\CoreX\PropertyController::class, 'store'])->name('store');

        // Sold Properties Import — super-admin only (AT-24)
        Route::middleware('super_admin')->group(function () {
            Route::get('/import-sold',          [\App\Http\Controllers\CoreX\SoldPropertyImportController::class, 'form'])->name('import-sold');
            Route::post('/import-sold/preview', [\App\Http\Controllers\CoreX\SoldPropertyImportController::class, 'preview'])->name('import-sold.preview');
            Route::post('/import-sold/confirm', [\App\Http\Controllers\CoreX\SoldPropertyImportController::class, 'run'])->name('import-sold.run');

            // P24 Listing-Number repair — backfill p24_ref from the original
            // P24 CSV so pushes update originals instead of duplicating.
            Route::post('/p24-fix/upload',  [\App\Http\Controllers\CoreX\P24ListingNumberFixController::class, 'upload'])->name('p24-fix.upload');
            Route::post('/p24-fix/process', [\App\Http\Controllers\CoreX\P24ListingNumberFixController::class, 'process'])->name('p24-fix.process');
        });

        Route::get('/contacts/search',         [\App\Http\Controllers\CoreX\PropertyContactController::class, 'searchGlobal'])->name('contacts.search-global');
        // Upload Wizard (parallel path — does not replace /create)
        Route::get ('/wizard',                          [\App\Http\Controllers\CoreX\PropertyWizardController::class, 'start'])->name('wizard');
        Route::post('/wizard/draft',                    [\App\Http\Controllers\CoreX\PropertyWizardController::class, 'createDraft'])->name('wizard.draft');
        Route::post('/wizard/{property}/photos',        [\App\Http\Controllers\CoreX\PropertyWizardController::class, 'uploadPhotos'])->name('wizard.photos');
        Route::post('/wizard/{property}/photos/reorder',[\App\Http\Controllers\CoreX\PropertyWizardController::class, 'reorderPhotos'])->name('wizard.photos.reorder');
        Route::post('/wizard/{property}/photos/remove', [\App\Http\Controllers\CoreX\PropertyWizardController::class, 'removePhoto'])->name('wizard.photos.remove');
        Route::post('/wizard/{property}/step',          [\App\Http\Controllers\CoreX\PropertyWizardController::class, 'saveStep'])->name('wizard.step');
        Route::post('/wizard/{property}/finalize',      [\App\Http\Controllers\CoreX\PropertyWizardController::class, 'finalize'])->name('wizard.finalize');
        Route::delete('/wizard/{property}',             [\App\Http\Controllers\CoreX\PropertyWizardController::class, 'discardDraft'])->name('wizard.discard');
        // Same-origin image proxy for the Ad Manager (html2canvas needs same-origin
        // images). MUST be declared before the /{property} catch-all so "ad-media"
        // isn't matched as a property slug. See PropertyController@adMedia.
        Route::get('/ad-media',                [\App\Http\Controllers\CoreX\PropertyController::class, 'adMedia'])->name('ad-media');
        Route::get('/{property}',              [\App\Http\Controllers\CoreX\PropertyController::class, 'show'])->name('show');
        // Phase 3g — JSON detail card for the Map module.
        Route::get('/{property}/map-card',     [\App\Http\Controllers\Map\MapController::class, 'propertyCard'])->name('map-card');
        Route::get('/{property}/edit',         [\App\Http\Controllers\CoreX\PropertyController::class, 'edit'])->name('edit');
        Route::get('/{property}/ad',           [\App\Http\Controllers\CoreX\PropertyController::class, 'ad'])->name('ad');
        // Printable Brochure — A4 PDF data sheet (always-first Ad Manager template).
        Route::get('/{property}/brochure',     [\App\Http\Controllers\CoreX\PropertyController::class, 'brochure'])->name('brochure');
        Route::put('/{property}',              [\App\Http\Controllers\CoreX\PropertyController::class, 'update'])->name('update');
        Route::delete('/{property}',           [\App\Http\Controllers\CoreX\PropertyController::class, 'destroy'])->name('destroy');
        Route::post('/{property}/restore',     [\App\Http\Controllers\CoreX\PropertyController::class, 'restore'])->name('restore')->withTrashed();
        Route::post('/{property}/duplicate',   [\App\Http\Controllers\CoreX\PropertyController::class, 'duplicate'])->name('duplicate');
        // AT-262 — change listing type = duplicate to the other type + archive (de-list) the original.
        Route::post('/{property}/change-type', [\App\Http\Controllers\CoreX\PropertyController::class, 'changeType'])->name('change-type');
        Route::post('/{property}/publish-toggle', [\App\Http\Controllers\CoreX\PropertyController::class, 'publishToggle'])->name('publish-toggle');
        Route::post('/{property}/upload-images',[\App\Http\Controllers\CoreX\PropertyController::class, 'uploadImages'])->name('upload-images');
        Route::post('/{property}/delete-image',[\App\Http\Controllers\CoreX\PropertyController::class, 'deleteImage'])->name('deleteImage');
        Route::post('/{property}/reorder-images',[\App\Http\Controllers\CoreX\PropertyController::class, 'reorderImages'])->name('reorderImages');
        // Gallery image rotation — sibling of upload/delete/reorder. Browser-only,
        // session-authed (the /api/v1 group has stateful middleware removed for
        // mobile, so it can't auth a cookie request). Spec: gallery-image-rotation.md
        Route::post('/{property}/rotate-image',[\App\Http\Controllers\CoreX\PropertyController::class, 'rotateImage'])->name('rotate-image');
        // Rental inspection galleries — only surfaced for rental listings. Spec: rental-images.md
        Route::post('/{property}/rental-images/upload',[\App\Http\Controllers\CoreX\PropertyController::class, 'uploadRentalImages'])->name('rental-images.upload');
        Route::post('/{property}/rental-images/save',  [\App\Http\Controllers\CoreX\PropertyController::class, 'saveRentalImagesMeta'])->name('rental-images.save');
        Route::post('/{property}/rental-images/delete',[\App\Http\Controllers\CoreX\PropertyController::class, 'deleteRentalImage'])->name('rental-images.delete');
        // Notes
        Route::post('/{property}/notes',                [\App\Http\Controllers\CoreX\PropertyNoteController::class, 'store'])->name('notes.store');
        Route::delete('/{property}/notes/{note}',       [\App\Http\Controllers\CoreX\PropertyNoteController::class, 'destroy'])->name('notes.destroy');
        // Files (Drive) — now uses unified Document model
        Route::post('/{property}/files',                    [\App\Http\Controllers\CoreX\PropertyFileController::class, 'store'])->name('files.store');
        Route::put('/{property}/files/{document}/tag',      [\App\Http\Controllers\CoreX\PropertyFileController::class, 'updateTag'])->name('files.tag');
        Route::delete('/{property}/files/{document}',       [\App\Http\Controllers\CoreX\PropertyFileController::class, 'destroy'])->name('files.destroy');
        // Contacts
        Route::get('/{property}/contacts/search',       [\App\Http\Controllers\CoreX\PropertyContactController::class, 'search'])->name('contacts.search');
        Route::post('/{property}/contacts/link',        [\App\Http\Controllers\CoreX\PropertyContactController::class, 'link'])->name('contacts.link');
        Route::post('/{property}/contacts/create-link', [\App\Http\Controllers\CoreX\PropertyContactController::class, 'createAndLink'])->name('contacts.createAndLink');
        Route::put('/{property}/contacts/{contact}/role', [\App\Http\Controllers\CoreX\PropertyContactController::class, 'updateRole'])->name('contacts.updateRole');
        Route::delete('/{property}/contacts/{contact}', [\App\Http\Controllers\CoreX\PropertyContactController::class, 'unlink'])->name('contacts.unlink');
        // Marketing
        Route::get('/{property}/marketing',              [\App\Http\Controllers\PropertyMarketingController::class, 'index'])->name('marketing.index');
        Route::post('/{property}/marketing/generate-copy', [\App\Http\Controllers\PropertyMarketingController::class, 'generateCopy'])->name('marketing.generateCopy');
        Route::post('/{property}/marketing/publish',     [\App\Http\Controllers\PropertyMarketingController::class, 'publish'])->name('marketing.publish');
        // Private Property Syndication
        Route::post('/{property}/syndication/toggle',     [\App\Http\Controllers\PrivateProperty\SyndicationController::class, 'toggle'])->name('syndication.toggle');
        Route::post('/{property}/syndication/submit',     [\App\Http\Controllers\PrivateProperty\SyndicationController::class, 'submit'])->name('syndication.submit');
        Route::post('/{property}/syndication/deactivate', [\App\Http\Controllers\PrivateProperty\SyndicationController::class, 'deactivate'])->name('syndication.deactivate');
        Route::post('/{property}/syndication/reactivate', [\App\Http\Controllers\PrivateProperty\SyndicationController::class, 'reactivate'])->name('syndication.reactivate');
        Route::post('/{property}/syndication/showday',    [\App\Http\Controllers\PrivateProperty\SyndicationController::class, 'showday'])->name('syndication.showday');
        Route::delete('/{property}/syndication/showday/{showday}', [\App\Http\Controllers\PrivateProperty\SyndicationController::class, 'deleteShowday'])->name('syndication.showday.delete');
        Route::post('/{property}/syndication/visibility', [\App\Http\Controllers\PrivateProperty\SyndicationController::class, 'updateVisibility'])->name('syndication.visibility');
        Route::get('/{property}/syndication/status',      [\App\Http\Controllers\PrivateProperty\SyndicationController::class, 'status'])->name('syndication.status');
        Route::get('/{property}/syndication/readiness',   [\App\Http\Controllers\PrivateProperty\SyndicationController::class, 'readiness'])->name('syndication.readiness');
        // PP Agent management
        Route::post('/syndication/agent/register',        [\App\Http\Controllers\PrivateProperty\SyndicationController::class, 'registerAgent'])->name('syndication.agent.register');
        Route::post('/syndication/agent/deactivate',      [\App\Http\Controllers\PrivateProperty\SyndicationController::class, 'deactivateAgent'])->name('syndication.agent.deactivate');
        Route::post('/syndication/agent/image',           [\App\Http\Controllers\PrivateProperty\SyndicationController::class, 'uploadAgentImage'])->name('syndication.agent.image');
        // PP Video/Matterport & Listing Ownership
        Route::post('/{property}/syndication/video',     [\App\Http\Controllers\PrivateProperty\PropertyPpController::class, 'video'])->name('syndication.video');
        Route::post('/{property}/syndication/update-id',  [\App\Http\Controllers\PrivateProperty\PropertyPpController::class, 'updateId'])->name('syndication.update-id');
        // Property24 ExDev Syndication
        Route::post('/{property}/p24-syndication/toggle',     [\App\Http\Controllers\Property24\P24SyndicationController::class, 'toggle'])->name('p24-syndication.toggle');
        Route::post('/{property}/p24-syndication/submit',     [\App\Http\Controllers\Property24\P24SyndicationController::class, 'submit'])->name('p24-syndication.submit');
        Route::post('/{property}/p24-syndication/deactivate', [\App\Http\Controllers\Property24\P24SyndicationController::class, 'deactivate'])->name('p24-syndication.deactivate');
        Route::post('/{property}/p24-syndication/reactivate', [\App\Http\Controllers\Property24\P24SyndicationController::class, 'reactivate'])->name('p24-syndication.reactivate');
        Route::get('/{property}/p24-syndication/status',      [\App\Http\Controllers\Property24\P24SyndicationController::class, 'status'])->name('p24-syndication.status');
        Route::get('/{property}/p24-syndication/sync-state',   [\App\Http\Controllers\Property24\P24SyndicationController::class, 'syncState'])->name('p24-syndication.sync-state');
        Route::get('/{property}/p24-syndication/readiness',   [\App\Http\Controllers\Property24\P24SyndicationController::class, 'readiness'])->name('p24-syndication.readiness');
        // Website syndication — per-(property × website key). Spec: agency-public-api.md §6.5.2
        Route::post('/{property}/website-syndication/{apiKey}/toggle',     [\App\Http\Controllers\Website\WebsiteSyndicationController::class, 'toggle'])->name('website-syndication.toggle');
        Route::post('/{property}/website-syndication/{apiKey}/activate',   [\App\Http\Controllers\Website\WebsiteSyndicationController::class, 'activate'])->name('website-syndication.activate');
        Route::post('/{property}/website-syndication/{apiKey}/deactivate', [\App\Http\Controllers\Website\WebsiteSyndicationController::class, 'deactivate'])->name('website-syndication.deactivate');
        Route::post('/{property}/website-syndication/{apiKey}/refresh',    [\App\Http\Controllers\Website\WebsiteSyndicationController::class, 'refresh'])->name('website-syndication.refresh');
    });

    // Phase 3g — Map module (standalone page + JSON pin + detail endpoints).
    // Same permission as Properties; agency scoping enforced inside the service.
    Route::prefix('map')->middleware(['permission:access_properties', 'agency.required'])->name('corex.map.')->group(function () {
        Route::get('/',                       [\App\Http\Controllers\Map\MapController::class, 'index'])->name('index');
        Route::get('/pins',                   [\App\Http\Controllers\Map\MapController::class, 'pins'])->name('pins');
        Route::get('/demand',                 [\App\Http\Controllers\Map\MapController::class, 'demand'])->name('demand');
        Route::get('/sold/{layerId}',         [\App\Http\Controllers\Map\MapController::class, 'soldCard'])->name('sold');
        Route::get('/active/{layerId}',       [\App\Http\Controllers\Map\MapController::class, 'activeCard'])->name('active');
        Route::get('/mic-subject/{report}',   [\App\Http\Controllers\Map\MapController::class, 'micSubjectCard'])->name('mic-subject');
        Route::get('/scheme-owner/{owner}',   [\App\Http\Controllers\Map\MapController::class, 'schemeOwnerCard'])->name('scheme-owner');
        // Phase A.2 — activity log endpoint for map-launched actions.
        Route::post('/activity/log',          [\App\Http\Controllers\Map\MapActivityController::class, 'log'])->name('activity.log');
        // Phase A.3.2 — per-user saved searches CRUD.
        Route::get('/saved-searches',         [\App\Http\Controllers\Map\MapSavedSearchController::class, 'index'])->name('saved-searches.index');
        Route::post('/saved-searches',        [\App\Http\Controllers\Map\MapSavedSearchController::class, 'store'])->name('saved-searches.store');
        Route::patch('/saved-searches/{id}',  [\App\Http\Controllers\Map\MapSavedSearchController::class, 'update'])->name('saved-searches.update')->whereNumber('id');
        Route::delete('/saved-searches/{id}', [\App\Http\Controllers\Map\MapSavedSearchController::class, 'destroy'])->name('saved-searches.destroy')->whereNumber('id');
    });

    // Ad Template Builder
    Route::prefix('ad-templates')->middleware('permission:access_properties')->name('corex.ad-templates.')->group(function () {
        Route::get('/builder',                    [\App\Http\Controllers\CoreX\PropertyAdTemplateController::class, 'builder'])->name('builder');
        Route::get('/builder/{template}',         [\App\Http\Controllers\CoreX\PropertyAdTemplateController::class, 'builder'])->name('builder.edit');
        Route::post('/upload-media',              [\App\Http\Controllers\CoreX\PropertyAdTemplateController::class, 'uploadMedia'])->name('upload-media');
        Route::post('/',                          [\App\Http\Controllers\CoreX\PropertyAdTemplateController::class, 'store'])->name('store');
        Route::put('/{template}',                 [\App\Http\Controllers\CoreX\PropertyAdTemplateController::class, 'update'])->name('update');
        Route::delete('/{template}',              [\App\Http\Controllers\CoreX\PropertyAdTemplateController::class, 'destroy'])->name('destroy');
    });

    // Core Matches (top-level index)
    Route::get('/core-matches', [\App\Http\Controllers\CoreX\ContactMatchController::class, 'index'])
        ->middleware('permission:access_contacts')
        ->name('corex.core-matches.index');

    // Core Matches — All View (agency / branch oversight for managers & admins)
    Route::get('/core-matches/all', [\App\Http\Controllers\CoreX\ContactMatchController::class, 'allView'])
        ->middleware('permission:core_matches.all_view')
        ->name('corex.core-matches.all');

    // Portal Leads (P24 + PP unified). Spec: .ai/specs/portal-leads.md
    Route::prefix('real-estate/portal-leads')
        ->middleware(['permission:access_portal_leads', 'agency.required'])
        ->name('corex.portal-leads.')
        ->group(function () {
            Route::get('/',     [\App\Http\Controllers\CoreX\PortalLeadController::class, 'index'])->name('index');
            Route::get('/poll', [\App\Http\Controllers\CoreX\PortalLeadController::class, 'poll'])->name('poll');
            Route::post('/{portalLead}/mark-notified', [\App\Http\Controllers\CoreX\PortalLeadController::class, 'markNotified'])->name('mark-notified');
        });

    // WhatsApp Outreach Summary board (agents × outreach states).
    // Spec: .ai/specs/whatsapp-outreach-summary.md (AT-91)
    Route::prefix('real-estate/outreach-summary')
        ->middleware(['permission:outreach.summary.view', 'agency.required'])
        ->name('corex.outreach-summary.')
        ->group(function () {
            Route::get('/', [\App\Http\Controllers\CoreX\WhatsappOutreachSummaryController::class, 'index'])->name('index');
        });

    // Part 4 — unified Outreach & Canvassing board (Activity Feed + AT-91 consent
    // funnel). Reuses the AT-91 permission (same audience; embeds the AT-91 board).
    Route::prefix('real-estate/outreach-canvassing')
        ->middleware(['permission:outreach.summary.view', 'agency.required'])
        ->name('corex.outreach-canvassing.')
        ->group(function () {
            Route::get('/', [\App\Http\Controllers\CoreX\OutreachCanvassingController::class, 'index'])->name('index');
        });

    // AT-117 §6 / AT-120 — Outreach Queue (work-the-list). Access gated on the
    // scoped outreach_queue.view capability (own/branch/all); dispatch + cancel are
    // additionally gated in-controller by their own capabilities + act-own.
    Route::prefix('real-estate/outreach-queue')
        ->middleware(['permission:outreach_queue.view', 'agency.required'])
        ->name('corex.outreach-queue.')
        ->group(function () {
            Route::get('/', [\App\Http\Controllers\CoreX\OutreachQueueController::class, 'index'])->name('index');
            // AT-117 §7 — canonical client enqueue (MIC / map surfaces capture body here).
            Route::post('/enqueue', [\App\Http\Controllers\CoreX\OutreachQueueController::class, 'enqueue'])->name('enqueue');
            Route::post('/{outreachQueue}/open',   [\App\Http\Controllers\CoreX\OutreachQueueController::class, 'open'])->name('open');
            Route::post('/{outreachQueue}/cancel', [\App\Http\Controllers\CoreX\OutreachQueueController::class, 'cancel'])->name('cancel');
        });

    // AT-118 — Communications Access inbox (approvers: owning agents + grant_access holders).
    // Any comms-capable user may open it; the controller shows only the requests they may authorise.
    Route::get('/comms-access/inbox', [\App\Http\Controllers\Communications\CommsAccessRequestController::class, 'inbox'])
        ->middleware(['permission:communications.view', 'agency.required'])
        ->name('corex.comms-access.inbox');

    // Contacts
    Route::prefix('contacts')->middleware(['permission:access_contacts', 'agency.required'])->name('corex.contacts.')->group(function () {
        Route::get('/',                   [\App\Http\Controllers\CoreX\ContactController::class, 'index'])->name('index');
        Route::post('/',                  [\App\Http\Controllers\CoreX\ContactController::class, 'store'])->name('store');
        Route::post('/check-duplicate',   [\App\Http\Controllers\CoreX\ContactController::class, 'checkDuplicate'])->name('check-duplicate');
        // Part 3 — live "already on our books" address check for the capture modal.
        Route::post('/check-held-address', [\App\Http\Controllers\CoreX\ContactController::class, 'checkHeldAddress'])->name('check-held-address');
        Route::post('/import',            [\App\Http\Controllers\CoreX\ContactImportController::class, 'import'])->name('import');
        Route::get('/export',             [\App\Http\Controllers\CoreX\ContactExportController::class, 'export'])->middleware('permission:contacts.export')->name('export');
        Route::delete('/destroy-all',     [\App\Http\Controllers\CoreX\ContactController::class, 'destroyAll'])->middleware('permission:contacts.delete')->name('destroy-all');
        Route::get('/{contact}',          [\App\Http\Controllers\CoreX\ContactController::class, 'show'])->middleware(\App\Http\Middleware\LogsContactAccess::class . ':view')->name('show');
        Route::put('/{contact}',          [\App\Http\Controllers\CoreX\ContactController::class, 'update'])->middleware(\App\Http\Middleware\LogsContactAccess::class . ':edit')->name('update');
        Route::put('/{contact}/property-address', [\App\Http\Controllers\CoreX\ContactController::class, 'updatePropertyAddress'])->middleware(\App\Http\Middleware\LogsContactAccess::class . ':edit')->name('property-address.update');
        Route::delete('/{contact}/property-address', [\App\Http\Controllers\CoreX\ContactController::class, 'clearPropertyAddress'])->middleware(\App\Http\Middleware\LogsContactAccess::class . ':edit')->name('property-address.clear');
        Route::delete('/{contact}',       [\App\Http\Controllers\CoreX\ContactController::class, 'destroy'])->middleware(\App\Http\Middleware\LogsContactAccess::class . ':delete')->name('destroy');
        Route::post('/{contact}/tags',    [\App\Http\Controllers\CoreX\ContactController::class, 'syncTags'])->name('tags.sync');
        Route::post('/{contact}/consent/record', [\App\Http\Controllers\CoreX\ContactController::class, 'recordConsent'])->name('consent.record');
        Route::post('/{contact}/consent/revoke', [\App\Http\Controllers\CoreX\ContactController::class, 'revokeConsent'])->name('consent.revoke');
        Route::post('/{contact}/touch',   [\App\Http\Controllers\CoreX\ContactController::class, 'touch'])->name('touch');
        Route::post('/{contact}/birthday-reminder', [\App\Http\Controllers\CoreX\ContactController::class, 'toggleBirthdayReminder'])->name('birthday-reminder.toggle');
        Route::post('/{contact}/increment', [\App\Http\Controllers\CoreX\ContactController::class, 'incrementChannel'])->name('increment');

        // Notes
        Route::post('/{contact}/notes',          [\App\Http\Controllers\CoreX\ContactNoteController::class, 'store'])->name('notes.store');
        Route::delete('/{contact}/notes/{note}', [\App\Http\Controllers\CoreX\ContactNoteController::class, 'destroy'])->name('notes.destroy');

        // Testimonials (capture only — publishing lives in Company Settings → Website)
        Route::post('/{contact}/testimonials',                  [\App\Http\Controllers\CoreX\ContactTestimonialController::class, 'store'])->name('testimonials.store');
        Route::put('/{contact}/testimonials/{testimonial}',     [\App\Http\Controllers\CoreX\ContactTestimonialController::class, 'update'])->name('testimonials.update');
        Route::delete('/{contact}/testimonials/{testimonial}',  [\App\Http\Controllers\CoreX\ContactTestimonialController::class, 'destroy'])->name('testimonials.destroy');

        // Documents (Drive)
        Route::post('/{contact}/documents',                    [\App\Http\Controllers\CoreX\ContactDocumentController::class, 'store'])->name('documents.store');
        Route::get('/{contact}/documents/{document}/download', [\App\Http\Controllers\CoreX\ContactDocumentController::class, 'download'])->name('documents.download');
        Route::put('/{contact}/documents/{document}/tag',      [\App\Http\Controllers\CoreX\ContactDocumentController::class, 'updateTag'])->name('documents.tag');
        Route::delete('/{contact}/documents/{document}',       [\App\Http\Controllers\CoreX\ContactDocumentController::class, 'destroy'])->name('documents.destroy');
        // Properties
        Route::get('/{contact}/properties/search',    [\App\Http\Controllers\CoreX\ContactPropertyController::class, 'search'])->name('properties.search');
        Route::post('/{contact}/properties/link',     [\App\Http\Controllers\CoreX\ContactPropertyController::class, 'link'])->name('properties.link');
        Route::delete('/{contact}/properties/{property}', [\App\Http\Controllers\CoreX\ContactPropertyController::class, 'unlink'])->name('properties.unlink');
        // Core Matches
        Route::post('/{contact}/matches',                              [\App\Http\Controllers\CoreX\ContactMatchController::class, 'store'])->name('matches.store');
        Route::get('/{contact}/matches/{match}/edit',                  [\App\Http\Controllers\CoreX\ContactMatchController::class, 'edit'])->name('matches.edit');
        Route::put('/{contact}/matches/{match}',                       [\App\Http\Controllers\CoreX\ContactMatchController::class, 'update'])->name('matches.update');
        Route::post('/{contact}/matches/{match}/status',               [\App\Http\Controllers\CoreX\ContactMatchController::class, 'setStatus'])->name('matches.setStatus');
        Route::get('/{contact}/matches/{match}/results',               [\App\Http\Controllers\CoreX\ContactMatchController::class, 'results'])->name('matches.results');
        Route::post('/{contact}/matches/{match}/hide/{property}',      [\App\Http\Controllers\CoreX\ContactMatchController::class, 'toggleHide'])->name('matches.toggleHide');
        Route::post('/{contact}/matches/{match}/convert/{property}',   [\App\Http\Controllers\CoreX\ContactMatchController::class, 'convertToDeal'])->middleware('permission:core_matches.convert_to_deal')->name('matches.convertToDeal');
        Route::delete('/{contact}/matches/{match}',                    [\App\Http\Controllers\CoreX\ContactMatchController::class, 'destroy'])->name('matches.destroy');

        // Client App Login — Spec: .ai/specs/client-auth.md
        Route::post('/{contact}/client-login',                  [\App\Http\Controllers\Contacts\ClientLoginController::class, 'create'])->name('client-login.create');
        Route::post('/{contact}/client-login/reset',            [\App\Http\Controllers\Contacts\ClientLoginController::class, 'reset'])->name('client-login.reset');
        Route::post('/{contact}/client-login/force-logout',     [\App\Http\Controllers\Contacts\ClientLoginController::class, 'forceLogout'])->name('client-login.force-logout');
        Route::delete('/{contact}/client-login',                [\App\Http\Controllers\Contacts\ClientLoginController::class, 'remove'])->name('client-login.remove');
    });

    // Contact Types (settings)
    Route::prefix('settings/contact-types')->middleware('permission:access_settings')->name('corex.settings.contact-types.')->group(function () {
        Route::post('/',              [\App\Http\Controllers\CoreX\ContactTypeController::class, 'store'])->name('store');
        Route::put('/{contactType}',  [\App\Http\Controllers\CoreX\ContactTypeController::class, 'update'])->name('update');
        Route::delete('/{contactType}', [\App\Http\Controllers\CoreX\ContactTypeController::class, 'destroy'])->name('destroy');
    });

    // Contact Sources (settings)
    Route::prefix('settings/contact-sources')->middleware('permission:access_settings')->name('corex.settings.contact-sources.')->group(function () {
        Route::post('/',                  [\App\Http\Controllers\CoreX\ContactSourceController::class, 'store'])->name('store');
        Route::put('/{contactSource}',    [\App\Http\Controllers\CoreX\ContactSourceController::class, 'update'])->name('update');
        Route::delete('/{contactSource}', [\App\Http\Controllers\CoreX\ContactSourceController::class, 'destroy'])->name('destroy');
    });

    // Contact Tags (settings)
    Route::prefix('settings/contact-tags')->middleware('permission:access_settings')->name('corex.settings.contact-tags.')->group(function () {
        Route::post('/',              [\App\Http\Controllers\CoreX\ContactTagController::class, 'store'])->name('store');
        Route::put('/{contactTag}',   [\App\Http\Controllers\CoreX\ContactTagController::class, 'update'])->name('update');
        Route::delete('/{contactTag}', [\App\Http\Controllers\CoreX\ContactTagController::class, 'destroy'])->name('destroy');
        Route::delete('/',            [\App\Http\Controllers\CoreX\ContactTagController::class, 'bulkDestroy'])->name('bulk-destroy');
    });

    // Property Setting Items (settings)
    Route::prefix('settings/property-items')->middleware('permission:access_settings')->name('corex.settings.property-items.')->group(function () {
        Route::post('/',                    [\App\Http\Controllers\CoreX\SettingsController::class, 'storePropertySettingItem'])->name('store');
        Route::put('/{item}',              [\App\Http\Controllers\CoreX\SettingsController::class, 'updatePropertySettingItem'])->name('update');
        Route::post('/{item}/toggle',      [\App\Http\Controllers\CoreX\SettingsController::class, 'togglePropertySettingItem'])->name('toggle');
        Route::post('/reorder',               [\App\Http\Controllers\CoreX\SettingsController::class, 'reorderPropertySettingItems'])->name('reorder');
        Route::post('/batch-toggle/{group}', [\App\Http\Controllers\CoreX\SettingsController::class, 'batchToggleDefaultItems'])->name('batch-toggle');
        Route::delete('/{item}',           [\App\Http\Controllers\CoreX\SettingsController::class, 'destroyPropertySettingItem'])->name('destroy');
    });

    // Marketing: post insights sync + social account disconnect
    Route::post('/marketing/posts/{post}/sync-insights', [\App\Http\Controllers\PropertyMarketingController::class, 'syncInsights'])->middleware('permission:access_properties')->name('corex.marketing.sync-insights');
    Route::post('/marketing/social/disconnect', [\App\Http\Controllers\PropertyMarketingController::class, 'disconnectAccount'])->middleware('permission:access_properties')->name('corex.marketing.social.disconnect');
    Route::post('/marketing/upload-template-image', [\App\Http\Controllers\PropertyMarketingController::class, 'uploadTemplateImage'])->middleware('permission:access_properties')->name('corex.marketing.upload-template-image');

    // Social OAuth
    Route::get('/social/oauth/redirect', [\App\Http\Controllers\PropertyMarketingController::class, 'oauthRedirect'])->middleware('permission:access_properties')->name('corex.social.oauth.redirect');
    Route::get('/social/oauth/callback', [\App\Http\Controllers\PropertyMarketingController::class, 'oauthCallback'])->middleware('permission:access_properties')->name('corex.social.oauth.callback');
});


// ===== COMMERCIAL EVALUATIONS =====
Route::middleware(['auth', 'permission:access_commercial_evaluations'])->prefix('commercial-evaluations')->name('commercial-evaluations.')->group(function () {
    Route::get('/',                                          [\App\Http\Controllers\CommercialEvaluationController::class, 'index'])            ->name('index');
    Route::get('/create',                                   [\App\Http\Controllers\CommercialEvaluationController::class, 'create'])           ->name('create');
    Route::post('/',                                        [\App\Http\Controllers\CommercialEvaluationController::class, 'store'])            ->name('store');
    Route::get('/{evaluation}',                             [\App\Http\Controllers\CommercialEvaluationController::class, 'show'])             ->name('show');
    Route::get('/{evaluation}/edit',                        [\App\Http\Controllers\CommercialEvaluationController::class, 'edit'])             ->name('edit');
    Route::put('/{evaluation}',                             [\App\Http\Controllers\CommercialEvaluationController::class, 'update'])           ->name('update');
    Route::delete('/{evaluation}',                          [\App\Http\Controllers\CommercialEvaluationController::class, 'destroy'])          ->name('destroy');
    Route::post('/{evaluation}/restore',                    [\App\Http\Controllers\CommercialEvaluationController::class, 'restore'])          ->name('restore')->withTrashed();
    Route::post('/{evaluation}/evaluate',                   [\App\Http\Controllers\CommercialEvaluationController::class, 'evaluate'])         ->name('evaluate');
    Route::get('/{evaluation}/pdf',                         [\App\Http\Controllers\CommercialEvaluationController::class, 'downloadPdf'])      ->name('pdf');
    Route::post('/{evaluation}/financials',                 [\App\Http\Controllers\CommercialEvaluationController::class, 'storeFinancials'])  ->name('financials.store');
    Route::post('/{evaluation}/comparables',                [\App\Http\Controllers\CommercialEvaluationController::class, 'storeComparable']) ->name('comparables.store');
    Route::delete('/{evaluation}/comparables/{comparable}', [\App\Http\Controllers\CommercialEvaluationController::class, 'destroyComparable'])->name('comparables.destroy');
    Route::post('/{evaluation}/assets',                     [\App\Http\Controllers\CommercialEvaluationController::class, 'storeAsset'])       ->name('assets.store');
    Route::delete('/{evaluation}/assets/{asset}',           [\App\Http\Controllers\CommercialEvaluationController::class, 'destroyAsset'])     ->name('assets.destroy');
    Route::post('/{evaluation}/units',                      [\App\Http\Controllers\CommercialEvaluationController::class, 'storeUnit'])        ->name('units.store');
    Route::delete('/{evaluation}/units/{unit}',             [\App\Http\Controllers\CommercialEvaluationController::class, 'destroyUnit'])      ->name('units.destroy');
    Route::post('/{evaluation}/crops',                      [\App\Http\Controllers\CommercialEvaluationController::class, 'storeCrop'])        ->name('crops.store');
    Route::delete('/{evaluation}/crops/{crop}',             [\App\Http\Controllers\CommercialEvaluationController::class, 'destroyCrop'])      ->name('crops.destroy');
    Route::post('/{evaluation}/livestock',                  [\App\Http\Controllers\CommercialEvaluationController::class, 'storeLivestock'])   ->name('livestock.store');
    Route::delete('/{evaluation}/livestock/{livestock}',    [\App\Http\Controllers\CommercialEvaluationController::class, 'destroyLivestock']) ->name('livestock.destroy');
});

// ===== PRESENTATION VERSION HISTORY (P17) =====
Route::middleware(['auth', 'permission:access_presentations'])->group(function () {
    Route::get('/presentations/versions', [\App\Http\Controllers\Presentation\PresentationVersionController::class, 'index'])
        ->name('presentations.versions.index');
});

Route::middleware(['auth', 'permission:access_presentations'])->group(function () {
    Route::get('/my/presentations/versions', [\App\Http\Controllers\Presentation\PresentationVersionController::class, 'mine'])
        ->name('presentations.versions.mine');
});

// ===== PRESENTATIONS =====
Route::middleware(['auth', 'permission:access_presentations'])->prefix('presentations')->name('presentations.')->group(function () {
    Route::get('/',       [\App\Http\Controllers\Presentation\PresentationController::class, 'index'])  ->name('index');
    Route::get('/create', [\App\Http\Controllers\Presentation\PresentationController::class, 'create']) ->name('create');
    Route::post('/',      [\App\Http\Controllers\Presentation\PresentationController::class, 'store'])  ->name('store');

    Route::get('/{presentation}',              [\App\Http\Controllers\Presentation\PresentationController::class, 'show'])     ->name('show');
    Route::get('/{presentation}/edit',         [\App\Http\Controllers\Presentation\PresentationController::class, 'edit'])     ->name('edit');
    Route::patch('/{presentation}',            [\App\Http\Controllers\Presentation\PresentationController::class, 'update'])   ->name('update');
    Route::get('/{presentation}/analysis',     [\App\Http\Controllers\Presentation\PresentationController::class, 'analysis']) ->name('analysis');

    // Build 2 — review flow (per-version). Routes accept the version id
    // (not the presentation id) so each render has its own review URL.
    // Lookup uses presentation_versions; soft-deleted versions 404.
    Route::get('/version/{version}/review',
        [\App\Http\Controllers\Presentation\PresentationReviewController::class, 'show'])
        ->name('review.show');
    Route::post('/version/{version}/review/comps/{comp}/toggle',
        [\App\Http\Controllers\Presentation\PresentationReviewController::class, 'toggleComp'])
        ->name('review.toggle-comp');
    // AT-22 / AT-21 — comparable-sales curation toolkit: batch include-set
    // (slider / sort / select-all / bulk), and browse-and-add freehold comps
    // beyond the auto-gated pool.
    Route::post('/version/{version}/review/comps/set',
        [\App\Http\Controllers\Presentation\PresentationReviewController::class, 'setComps'])
        ->name('review.set-comps');
    Route::get('/version/{version}/review/comps/browse',
        [\App\Http\Controllers\Presentation\PresentationReviewController::class, 'browseComps'])
        ->name('review.browse-comps');
    Route::post('/version/{version}/review/comps/add',
        [\App\Http\Controllers\Presentation\PresentationReviewController::class, 'addComps'])
        ->name('review.add-comps');
    // Competitor Stock — toggle a scored prospecting_listings competitor on/off.
    Route::post('/version/{version}/review/competitors/{listingId}/toggle',
        [\App\Http\Controllers\Presentation\PresentationReviewController::class, 'toggleCompetitor'])
        ->where('listingId', '[0-9]+')
        ->name('review.toggle-competitor');
    // Competitor Stock — GET refreshed payload (matches/included_ids/visible/display_cap)
    // so the review screen can re-render the Active Competition section in place after the
    // manual-picker modal closes. No full page reload.
    Route::get('/version/{version}/review/competitor-data',
        [\App\Http\Controllers\Presentation\PresentationReviewController::class, 'competitorData'])
        ->name('review.competitor-data');
    // Competitor Stock — manual-picker modal search. Agent-loosened filters
    // (suburb / property_type / price / beds / free-text); Level-1 family gate
    // stays ENFORCED inside searchForManualPicker. Returns scored rows + the
    // bootstrap criteria for filter pre-population.
    Route::get('/version/{version}/review/competitor-picker',
        [\App\Http\Controllers\Presentation\PresentationReviewController::class, 'competitorPickerSearch'])
        ->name('review.competitor-picker');
    // Holding Cost — per-component inline override (Section 6).
    Route::post('/version/{version}/review/holding-cost-component',
        [\App\Http\Controllers\Presentation\PresentationReviewController::class, 'setHoldingCostComponent'])
        ->name('review.holding-cost-component');
    // Build 3 — agent picks/clears condition on the review screen.
    Route::post('/version/{version}/review/condition',
        [\App\Http\Controllers\Presentation\PresentationReviewController::class, 'setCondition'])
        ->name('review.condition');
    // Build 4 — toggle a report section on/off with dependency cascade.
    Route::post('/version/{version}/review/sections',
        [\App\Http\Controllers\Presentation\PresentationReviewController::class, 'toggleSection'])
        ->name('review.sections');
    // AT-27 Phase A — review-screen forward action: persist curation only,
    // hand off to the Analysis working surface (no freeze/publish here).
    Route::post('/version/{version}/continue',
        [\App\Http\Controllers\Presentation\PresentationReviewController::class, 'continueToAnalysis'])
        ->name('review.continue');
    // AT-27 Phase B — review.publish RETIRED. The snapshot freeze now lives in
    // PresentationController::confirmAndGenerate (presentations.analysis.confirm).
    Route::post('/version/{version}/revert',
        [\App\Http\Controllers\Presentation\PresentationReviewController::class, 'revert'])
        ->name('review.revert');
    Route::post('/version/{version}/review/takeover',
        [\App\Http\Controllers\Presentation\PresentationReviewController::class, 'takeover'])
        ->name('review.takeover');
    // Phase 3g V2 Part D — embedded spatial view JSON for the analysis screen.
    Route::get('/{presentation}/spatial-pins', [\App\Http\Controllers\Map\MapController::class, 'presentationPins'])->name('spatial-pins');

    // Phase 4 — snapshot share link management (agent-side).
    Route::post('/{presentation}/snapshot-links',                       [\App\Http\Controllers\Presentation\SnapshotLinkController::class, 'store'])
        ->name('snapshot-links.store');
    Route::post('/{presentation}/snapshot-links/{link}/revoke',         [\App\Http\Controllers\Presentation\SnapshotLinkController::class, 'revoke'])
        ->name('snapshot-links.revoke');
    Route::post('/{presentation}/snapshot-links/{link}/extend',         [\App\Http\Controllers\Presentation\SnapshotLinkController::class, 'extend'])
        ->name('snapshot-links.extend');
    // Phase 5 — teaser leads index.
    Route::get('/{presentation}/teaser-leads',                          [\App\Http\Controllers\Presentation\SnapshotLinkController::class, 'teaserLeads'])
        ->name('teaser-leads');

    // Phase 8 — outcome capture on a single presentation.
    Route::post('/{presentation}/outcome',  [\App\Http\Controllers\Presentation\PresentationOutcomeController::class, 'record'])
        ->name('outcome.record');
    Route::patch('/{presentation}/outcome', [\App\Http\Controllers\Presentation\PresentationOutcomeController::class, 'update'])
        ->name('outcome.update');
    // Phase 3 — AI summary generation + accept.
    Route::post('/{presentation}/ai-summary/generate', [\App\Http\Controllers\Presentation\AiSummaryController::class, 'generate'])
        ->middleware('throttle:30,1')
        ->name('ai-summary.generate');
    Route::post('/{presentation}/ai-summary/accept',   [\App\Http\Controllers\Presentation\AiSummaryController::class, 'accept'])
        ->name('ai-summary.accept');

    // Phase 6 — Send-to-Recipient flow.
    Route::post('/{presentation}/deliveries/preview',                   [\App\Http\Controllers\Presentation\PresentationDeliveryController::class, 'preview'])
        ->name('deliveries.preview');
    Route::post('/{presentation}/deliveries/send',                      [\App\Http\Controllers\Presentation\PresentationDeliveryController::class, 'send'])
        ->middleware('throttle:30,1')
        ->name('deliveries.send');
    Route::get('/{presentation}/deliveries',                            [\App\Http\Controllers\Presentation\PresentationDeliveryController::class, 'index'])
        ->name('deliveries.index');
    Route::post('/{presentation}/analysis/run',[\App\Http\Controllers\Presentation\PresentationController::class, 'runAnalysis'])  ->name('analysis.run');
    // AT-27 Phase B — the single finalise point: recompile → freeze → exec
    // summary from confirmed numbers → Overview. Replaces the review publish path.
    Route::post('/{presentation}/analysis/confirm', [\App\Http\Controllers\Presentation\PresentationController::class, 'confirmAndGenerate'])
        ->name('analysis.confirm');
    // AT-27 — re-open a confirmed/published version to a mutable draft so the
    // agent can edit after confirming (the "edit after confirm" path).
    Route::post('/{presentation}/analysis/reopen', [\App\Http\Controllers\Presentation\PresentationController::class, 'reopenForEditing'])
        ->name('analysis.reopen');
    // AT-27 C1a (option 1) — subject editing removed; the property is the single
    // source of truth (corrected on the property edit screen).
    Route::patch('/{presentation}/analysis-selections', [\App\Http\Controllers\Presentation\PresentationController::class, 'updateAnalysisSelections'])
        ->name('analysis-selections.update');

    Route::post('/{presentation}/upload', [\App\Http\Controllers\Presentation\PresentationController::class, 'upload'])
        ->name('upload');
    Route::patch('/{presentation}/uploads/{upload}/type', [\App\Http\Controllers\Presentation\PresentationController::class, 'updateUploadType'])
        ->name('uploads.update-type');
    Route::patch('/{presentation}/uploads/{upload}/override', [\App\Http\Controllers\Presentation\PresentationController::class, 'saveUploadOverride'])
        ->name('uploads.override');
    Route::delete('/{presentation}/uploads/{upload}/override', [\App\Http\Controllers\Presentation\PresentationController::class, 'clearUploadOverride'])
        ->name('uploads.override.clear');
    Route::post('/{presentation}/uploads/{upload}/re-extract', [\App\Http\Controllers\Presentation\PresentationController::class, 'reExtractUpload'])
        ->name('uploads.re-extract');
    Route::delete('/{presentation}/uploads/{upload}', [\App\Http\Controllers\Presentation\PresentationController::class, 'destroyUpload'])
        ->name('uploads.destroy');

    Route::post('/{presentation}/links', [\App\Http\Controllers\Presentation\PresentationController::class, 'storeLink'])
        ->name('links.store');
    Route::patch('/{presentation}/links/{link}/type', [\App\Http\Controllers\Presentation\PresentationController::class, 'updateLinkType'])
        ->name('links.update-type');
    Route::patch('/{presentation}/links/{link}/override', [\App\Http\Controllers\Presentation\PresentationController::class, 'saveLinkOverride'])
        ->name('links.override');
    Route::delete('/{presentation}/links/{link}/override', [\App\Http\Controllers\Presentation\PresentationController::class, 'clearLinkOverride'])
        ->name('links.override.clear');
    Route::delete('/{presentation}/links/{link}', [\App\Http\Controllers\Presentation\PresentationController::class, 'destroyLink'])
        ->name('links.destroy');
    Route::post('/{presentation}/links/{link}/re-extract', [\App\Http\Controllers\Presentation\PresentationController::class, 'reExtractLink'])
        ->name('links.re-extract');

    // Snapshot routes — names preserved for existing tests
    Route::post('/{presentation}/snapshots', [\App\Http\Controllers\Presentation\PresentationSnapshotController::class, 'saveSnapshot'])
        ->name('snapshots.save');
    Route::get('/{presentation}/snapshots/{snapshot}', [\App\Http\Controllers\Presentation\PresentationSnapshotController::class, 'showSnapshot'])
        ->name('snapshots.show');

    // Blueprint compiler + live simulation
    Route::post('/{presentation}/compile',  [\App\Http\Controllers\Presentation\PresentationController::class, 'compile'])
        ->name('compile');
    Route::post('/{presentation}/simulate', [\App\Http\Controllers\Presentation\PresentationController::class, 'simulate'])
        ->name('simulate');

    // URL snapshot ingestion (P6/P7/P8)
    Route::post('/{presentation}/url-snapshots', [\App\Http\Controllers\Presentation\PresentationController::class, 'storeUrlSnapshot'])
        ->name('url-snapshots.store');

    // Holding cost inputs (P15)
    Route::patch('/{presentation}/holding-cost', [\App\Http\Controllers\Presentation\PresentationController::class, 'updateHoldingCost'])
        ->name('holding-cost.update');

    // Multi-step price trajectory simulation (C1)
    Route::post('/{presentation}/simulate-trajectory', [\App\Http\Controllers\Presentation\PresentationController::class, 'simulateTrajectory'])
        ->name('simulate-trajectory');

    // Optimal price band scan (C2)
    Route::post('/{presentation}/price-band', [\App\Http\Controllers\Presentation\PresentationController::class, 'priceBand'])
        ->name('price-band');

    // Competitive threat ranking (C3)
    Route::post('/{presentation}/competitive-threats', [\App\Http\Controllers\Presentation\PresentationController::class, 'competitiveThreats'])
        ->name('competitive-threats');

    // Pricing Simulator (replaces Brain Simulation)
    Route::get('/{presentation}/pricing-simulator', [\App\Http\Controllers\Presentation\PresentationController::class, 'pricingSimulator'])
        ->name('pricing-simulator');
    Route::post('/{presentation}/pricing-simulator/compute', [\App\Http\Controllers\Presentation\PresentationController::class, 'computePricingSimulator'])
        ->name('pricing-simulator.compute');
    Route::post('/{presentation}/pricing-simulator/save', [\App\Http\Controllers\Presentation\PresentationController::class, 'savePricingSimulator'])
        ->name('pricing-simulator.save');
    Route::get('/{presentation}/pricing-simulator/present', [\App\Http\Controllers\Presentation\PresentationController::class, 'pricingSimulatorPresent'])
        ->name('pricing-simulator.present');

    // Seller Live Probability Screen
    Route::get('/{presentation}/seller-live', [\App\Http\Controllers\Presentation\PresentationController::class, 'sellerLive'])
        ->name('seller-live');
    Route::post('/{presentation}/seller-live/capture', [\App\Http\Controllers\Presentation\PresentationController::class, 'captureSellerLive'])
        ->name('seller-live.capture');

    // Legacy Brain route → redirect to Pricing Simulator
    Route::get('/{presentation}/brain', [\App\Http\Controllers\Presentation\PresentationController::class, 'brain'])
        ->name('brain');

    // PDF pack download (P18) — feature-flagged via config('features.presentation_pdf_v1')
    Route::get('/{presentation}/versions/{version}/pdf', [\App\Http\Controllers\Presentation\PresentationPdfController::class, 'download'])
        ->name('versions.pdf');
    Route::get('/{presentation}/versions/{version}/complete-pack', [\App\Http\Controllers\Presentation\PresentationPdfController::class, 'downloadCompletePack'])
        ->name('versions.complete-pack');

    // Portal captures (extension-based ingestion)
    Route::get('/{presentation}/portal-captures', [\App\Http\Controllers\Presentation\PortalCaptureController::class, 'index'])
        ->name('portal-captures.index');
    Route::post('/{presentation}/portal-captures/reclassify', [\App\Http\Controllers\Presentation\PortalCaptureController::class, 'reclassify'])
        ->name('portal-captures.reclassify');
    Route::post('/{presentation}/portal-captures/{capture}/attach', [\App\Http\Controllers\Presentation\PortalCaptureController::class, 'attach'])
        ->name('portal-captures.attach');
    Route::delete('/{presentation}/portal-captures/{capture}', [\App\Http\Controllers\Presentation\PortalCaptureController::class, 'destroy'])
        ->name('portal-captures.destroy');

    // Live snapshot polling (B1 — zero-refresh updates)
    Route::get('/{presentation}/live-snapshot', [\App\Http\Controllers\Presentation\PortalCaptureController::class, 'liveSnapshot'])
        ->name('live-snapshot');

    // Restore soft-deleted presentation
    Route::post('/{presentation}/restore', [\App\Http\Controllers\Presentation\PresentationController::class, 'restore'])
        ->name('restore')->withTrashed();
});

// ===== E-SIGN COMPILE STUDIO (AT-177 WS4-S) — internal tool, esign.compiler.* gated =====
Route::prefix('docuperfect/compiler')->middleware(['auth', 'verified', 'permission:esign.compiler.view'])
    ->name('docuperfect.compiler.')->group(function () {
        $c = \App\Http\Controllers\Docuperfect\Compiler\CompileStudioController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::get('/studio/{id}', [$c, 'studio'])->whereNumber('id')->name('studio');

        Route::middleware('permission:esign.compiler.compile')->group(function () use ($c) {
            Route::post('/start', [$c, 'start'])->name('start');
            Route::post('/studio/{id}/bind-field', [$c, 'bindField'])->whereNumber('id')->name('bindField');
            Route::post('/studio/{id}/structure', [$c, 'updateStructure'])->whereNumber('id')->name('updateStructure');
            Route::post('/studio/{id}/party', [$c, 'declareParty'])->whereNumber('id')->name('declareParty');
            Route::post('/studio/{id}/visibility', [$c, 'setVisibility'])->whereNumber('id')->name('setVisibility');
            Route::post('/studio/{id}/editability', [$c, 'setEditability'])->whereNumber('id')->name('setEditability');
            Route::post('/studio/{id}/suggest', [$c, 'suggest'])->whereNumber('id')->name('suggest');
            Route::post('/studio/{id}/lint', [$c, 'lint'])->whereNumber('id')->name('lint');
            Route::post('/studio/{id}/certify', [$c, 'certify'])->whereNumber('id')->name('certify');
            Route::post('/studio/{id}/archive', [$c, 'archive'])->whereNumber('id')->name('archive');
        });

        Route::post('/studio/{id}/publish', [$c, 'publish'])->whereNumber('id')
            ->middleware('permission:esign.compiler.publish')->name('publish');
    });

// ===== DOCUPERFECT =====
Route::prefix('docuperfect')->middleware(['auth', 'permission:access_docuperfect'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Docuperfect\DashboardController::class, 'index'])->name('docuperfect.dashboard');
    Route::get('/create', [\App\Http\Controllers\Docuperfect\DashboardController::class, 'create'])->name('docuperfect.create');

    // Templates (admin/BM)
    Route::get('/templates', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'index'])->name('docuperfect.templates.index');
    Route::post('/templates/upload', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'upload'])->name('docuperfect.templates.upload');
    // CDS Document Engine (DB-backed drafts)
    Route::get('/templates/cds/builder/{draft}', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'cdsBuilder'])->name('docuperfect.cds.builder');
    Route::post('/templates/cds/mappings', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'cdsSaveMappings'])->name('docuperfect.cds.mappings');
    Route::post('/templates/cds/draft/save', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'cdsSaveDraft'])->name('docuperfect.cds.draft.save');
    Route::post('/templates/cds/generate', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'cdsGenerate'])->name('docuperfect.cds.generate');
    Route::delete('/templates/cds/draft/{draft}', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'cdsDestroyDraft'])->name('docuperfect.cds.draft.destroy');
    Route::get('/templates/{id}/edit', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'edit'])->name('docuperfect.templates.edit');
    Route::get('/templates/{id}/web-preview', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'webPreview'])->name('docuperfect.templates.webPreview');
    Route::post('/templates/{id}/fields', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'saveFields'])->name('docuperfect.templates.saveFields');
    Route::post('/templates/{id}/pages', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'uploadPageImages'])->name('docuperfect.templates.uploadPages');
    Route::post('/templates/{id}/archive', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'archive'])->name('docuperfect.templates.archive');
    Route::post('/templates/{id}/restore', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'restore'])->name('docuperfect.templates.restore');
    Route::post('/templates/{id}/copy', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'copy'])->name('docuperfect.templates.copy');
    Route::delete('/templates/{id}', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'destroy'])->name('docuperfect.templates.destroy');

    // Template Wizard Config
    Route::get('/templates/{id}/wizard-config', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'wizardConfig'])->name('docuperfect.templates.wizardConfig');
    Route::post('/templates/{id}/wizard-config', [\App\Http\Controllers\Docuperfect\TemplateController::class, 'saveWizardConfig'])->name('docuperfect.templates.wizardConfig.save');

    // Documents — bare /docuperfect/documents redirects to dashboard (pack_instance keeps existing view)
    Route::get('/documents', function (\Illuminate\Http\Request $request) {
        if (!$request->query('pack_instance')) {
            return redirect()->route('docuperfect.dashboard');
        }
        return app(\App\Http\Controllers\Docuperfect\DocumentController::class)->index($request);
    })->name('docuperfect.documents.index');
    Route::get('/documents/create/{templateId}', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'create'])->name('docuperfect.documents.create');
    Route::post('/documents/create/{templateId}', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'store'])->name('docuperfect.documents.store');
    Route::get('/documents/{id}/edit', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'edit'])->name('docuperfect.documents.edit');
    Route::post('/documents/{id}/fields', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'saveFields'])->name('docuperfect.documents.saveFields');
    Route::post('/documents/{id}/rename', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'rename'])->name('docuperfect.documents.rename');
    Route::post('/documents/{id}/archive', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'archive'])->name('docuperfect.documents.archive');
    Route::post('/documents/{id}/restore', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'restore'])->name('docuperfect.documents.restore');
    Route::delete('/documents/{id}', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'destroy'])->name('docuperfect.documents.destroy');
    Route::post('/documents/{id}/send-to-rentals', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'sendToRentals'])->name('docuperfect.documents.sendToRentals');
    Route::post('/documents/{id}/reject', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'reject'])->name('docuperfect.documents.reject');
    Route::get('/api/pack-instance/{instanceId}/combined-pdf-data', [\App\Http\Controllers\Docuperfect\DocumentController::class, 'combinedPdfData'])->name('docuperfect.api.combinedPdfData');

    // Clauses
    Route::get('/clauses', [\App\Http\Controllers\Docuperfect\ClauseController::class, 'index'])->name('docuperfect.clauses.index');
    Route::post('/clauses', [\App\Http\Controllers\Docuperfect\ClauseController::class, 'store'])->name('docuperfect.clauses.store');
    Route::put('/clauses/{id}', [\App\Http\Controllers\Docuperfect\ClauseController::class, 'update'])->name('docuperfect.clauses.update');
    Route::post('/clauses/{id}/copy', [\App\Http\Controllers\Docuperfect\ClauseController::class, 'copy'])->name('docuperfect.clauses.copy');
    Route::delete('/clauses/{id}', [\App\Http\Controllers\Docuperfect\ClauseController::class, 'destroy'])->name('docuperfect.clauses.destroy');
    Route::post('/clauses/{clause}/restore', [\App\Http\Controllers\Docuperfect\ClauseController::class, 'restore'])->name('docuperfect.clauses.restore')->withTrashed();
    Route::get('/api/clauses', [\App\Http\Controllers\Docuperfect\ClauseController::class, 'listJson'])->name('docuperfect.clauses.json');

    // Page images (authenticated)
    Route::get('/templates/{id}/page/{page}', [\App\Http\Controllers\Docuperfect\PageImageController::class, 'show'])->name('docuperfect.page.image');

    // Document-level page images (flattened web templates)
    Route::get('/documents/{id}/page/{page}', [\App\Http\Controllers\Docuperfect\PageImageController::class, 'showDocumentPage'])->name('docuperfect.documents.pageImage');

    // Document Types settings (admin)
    Route::get('/settings/types', [\App\Http\Controllers\Docuperfect\DocumentTypeController::class, 'index'])->name('docuperfect.settings.types');
    Route::post('/settings/types', [\App\Http\Controllers\Docuperfect\DocumentTypeController::class, 'store'])->name('docuperfect.settings.types.store');
    Route::put('/settings/types/{id}', [\App\Http\Controllers\Docuperfect\DocumentTypeController::class, 'update'])->name('docuperfect.settings.types.update');
    Route::delete('/settings/types/{id}', [\App\Http\Controllers\Docuperfect\DocumentTypeController::class, 'destroy'])->name('docuperfect.settings.types.destroy');
    Route::post('/settings/types/{type}/restore', [\App\Http\Controllers\Docuperfect\DocumentTypeController::class, 'restore'])->name('docuperfect.settings.types.restore')->withTrashed();
    Route::post('/settings/types/reorder', [\App\Http\Controllers\Docuperfect\DocumentTypeController::class, 'reorder'])->name('docuperfect.settings.types.reorder');

    // Named Fields settings (admin)
    Route::get('/settings/named-fields', [\App\Http\Controllers\Docuperfect\NamedFieldController::class, 'index'])->name('docuperfect.settings.namedFields');
    Route::post('/settings/named-fields', [\App\Http\Controllers\Docuperfect\NamedFieldController::class, 'store'])->name('docuperfect.settings.namedFields.store');
    Route::put('/settings/named-fields/{id}', [\App\Http\Controllers\Docuperfect\NamedFieldController::class, 'update'])->name('docuperfect.settings.namedFields.update');
    Route::delete('/settings/named-fields/{id}', [\App\Http\Controllers\Docuperfect\NamedFieldController::class, 'destroy'])->name('docuperfect.settings.namedFields.destroy');
    Route::post('/settings/named-fields/{field}/restore', [\App\Http\Controllers\Docuperfect\NamedFieldController::class, 'restore'])->name('docuperfect.settings.namedFields.restore')->withTrashed();
    Route::post('/settings/named-fields/reorder', [\App\Http\Controllers\Docuperfect\NamedFieldController::class, 'reorder'])->name('docuperfect.settings.namedFields.reorder');

    // Field Groups
    Route::get('/field-groups', [\App\Http\Controllers\Docuperfect\FieldGroupController::class, 'index'])->name('docuperfect.field-groups.index');
    Route::post('/field-groups', [\App\Http\Controllers\Docuperfect\FieldGroupController::class, 'store'])->name('docuperfect.field-groups.store');
    Route::put('/field-groups/{group}', [\App\Http\Controllers\Docuperfect\FieldGroupController::class, 'update'])->name('docuperfect.field-groups.update');
    Route::delete('/field-groups/{group}', [\App\Http\Controllers\Docuperfect\FieldGroupController::class, 'destroy'])->name('docuperfect.field-groups.destroy');
    Route::get('/field-groups/json', [\App\Http\Controllers\Docuperfect\FieldGroupController::class, 'json'])->name('docuperfect.field-groups.json');

    // Document Packs
    Route::get('/packs', [\App\Http\Controllers\Docuperfect\PackController::class, 'index'])->name('docuperfect.packs.index');
    Route::get('/packs/create', [\App\Http\Controllers\Docuperfect\PackController::class, 'create'])->name('docuperfect.packs.create');
    Route::post('/packs', [\App\Http\Controllers\Docuperfect\PackController::class, 'store'])->name('docuperfect.packs.store');
    Route::get('/packs/{id}/edit', [\App\Http\Controllers\Docuperfect\PackController::class, 'edit'])->name('docuperfect.packs.edit');
    Route::put('/packs/{id}', [\App\Http\Controllers\Docuperfect\PackController::class, 'update'])->name('docuperfect.packs.update');
    Route::delete('/packs/{id}', [\App\Http\Controllers\Docuperfect\PackController::class, 'destroy'])->name('docuperfect.packs.destroy');
    Route::post('/packs/{pack}/restore', [\App\Http\Controllers\Docuperfect\PackController::class, 'restore'])->name('docuperfect.packs.restore')->withTrashed();
    Route::get('/packs/{id}/launch', [\App\Http\Controllers\Docuperfect\PackController::class, 'showLaunch'])->name('docuperfect.packs.showLaunch');
    Route::post('/packs/{id}/launch', [\App\Http\Controllers\Docuperfect\PackController::class, 'executeLaunch'])->name('docuperfect.packs.launch');
    Route::get('/attachments/{id}/download', [\App\Http\Controllers\Docuperfect\PackController::class, 'downloadAttachment'])->name('docuperfect.attachments.download');

    // Web Packs
    Route::get('/web-packs', [\App\Http\Controllers\Docuperfect\WebPackController::class, 'index'])->name('docuperfect.web-packs.index');
    Route::get('/web-packs/create', [\App\Http\Controllers\Docuperfect\WebPackController::class, 'create'])->name('docuperfect.web-packs.create');
    Route::post('/web-packs', [\App\Http\Controllers\Docuperfect\WebPackController::class, 'store'])->name('docuperfect.web-packs.store');
    Route::get('/web-packs/{id}/edit', [\App\Http\Controllers\Docuperfect\WebPackController::class, 'edit'])->name('docuperfect.web-packs.edit');
    Route::put('/web-packs/{id}', [\App\Http\Controllers\Docuperfect\WebPackController::class, 'update'])->name('docuperfect.web-packs.update');
    Route::delete('/web-packs/{id}', [\App\Http\Controllers\Docuperfect\WebPackController::class, 'destroy'])->name('docuperfect.web-packs.destroy');

    // Pack Instance Values API
    Route::get('/api/pack-instance-values/{instanceId}', [\App\Http\Controllers\Docuperfect\PackInstanceValueController::class, 'show'])->name('docuperfect.api.packInstanceValues');
    Route::post('/api/pack-instance-values', [\App\Http\Controllers\Docuperfect\PackInstanceValueController::class, 'save'])->name('docuperfect.api.packInstanceValuesSave');

    // ===== E-SIGN WIZARD =====
    Route::get('/esign/my-documents', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'myDocuments'])->name('docuperfect.esign.myDocuments');
    Route::get('/esign/test-render/{templateId}', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'testRender'])->name('docuperfect.esign.testRender');
    Route::get('/esign/create', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'create'])->name('docuperfect.esign.create');
    Route::post('/esign/store', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'store'])->name('docuperfect.esign.store');
    Route::get('/esign/{flow}/step/{step}', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'showStep'])->name('docuperfect.esign.step');
    Route::post('/esign/{flow}/step/{step}', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'saveStep'])->name('docuperfect.esign.saveStep');
    Route::post('/esign/{flow}/draft', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'saveDraft'])->name('docuperfect.esign.saveDraft');
    Route::delete('/esign/{flow}', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'destroy'])->name('docuperfect.esign.destroy');
    Route::post('/esign/{flow}/autosave-fields', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'autosaveFields'])->name('docuperfect.esign.autosaveFields');
    Route::post('/esign/{flow}/prepare-signing', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'prepareSigning'])->name('docuperfect.esign.prepareSigning');
    Route::post('/esign/{flow}/prepare-download', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'prepareDownload'])->name('docuperfect.esign.prepareDownload');
    Route::post('/esign/{flow}/prepare-wet-ink', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'prepareWetInk'])->name('docuperfect.esign.prepareWetInk');
    Route::get('/esign/{flow}/signing-complete', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'signingComplete'])->name('docuperfect.esign.signingComplete');
    Route::get('/esign/{flow}/wet-ink-confirmation', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'wetInkConfirmation'])->name('docuperfect.esign.wetInkConfirmation');
    Route::post('/esign/wet-ink/{document}/upload', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'wetInkAgentUpload'])->name('docuperfect.esign.wetInkAgentUpload');
    Route::post('/esign/wet-ink/{document}/approve', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'wetInkAgentApprove'])->name('docuperfect.esign.wetInkAgentApprove');
    Route::get('/esign/download/{document}', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'downloadDocument'])->name('docuperfect.esign.downloadDocument');
    Route::get('/esign/download/{document}/pdf', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'downloadDocumentPdf'])->name('docuperfect.esign.downloadDocumentPdf');
    Route::get('/esign/api/properties', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'searchProperties'])->name('docuperfect.esign.api.properties');
    Route::get('/esign/api/contacts', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'searchContacts'])->name('docuperfect.esign.api.contacts');
    Route::get('/esign/api/template/{templateId}/pages', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'templatePages'])->name('docuperfect.esign.api.templatePages');

    // Pack FICA per-party duplication (MERGE pack model — the legacy
    // initPackChain/nextPackDocument/packStatus CHAIN engine was removed:
    // dead, unreferenced, no SignatureRequest<->pack linkage; audit BL-1).
    Route::post('/esign/{flow}/duplicate-fica', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'duplicateFicaPerParty'])->name('docuperfect.esign.duplicateFica');
    Route::post('/esign/documents/{signatureTemplate}/cancel', [\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'cancelDocument'])->name('docuperfect.esign.cancelDocument');

    // ===== DOCUMENT IMPORTER =====
    Route::get('/import', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'index'])->name('docuperfect.import.index');
    Route::post('/import/parse', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'parse'])->name('docuperfect.import.parse');
    Route::post('/import/cds', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'generateCdsTemplate'])->name('docuperfect.import.cds');
    Route::get('/import/review', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'review'])->name('docuperfect.import.review');
    Route::post('/import/generate', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'generate'])->name('docuperfect.import.generate');
    Route::post('/import/review/mappings', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'saveMappings'])->name('docuperfect.import.review.mappings');
    Route::post('/import/draft/save', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'saveDraft'])->name('docuperfect.import.draft.save');
    Route::delete('/import/draft/{id}', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'destroyDraft'])->name('docuperfect.import.draft.destroy');

    // Signing party management (agency-level)
    Route::get('/import/parties', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'getParties'])->name('docuperfect.import.parties.index');
    Route::post('/import/parties', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'storeParty'])->name('docuperfect.import.parties.store');
    Route::put('/import/parties/{id}', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'updateParty'])->name('docuperfect.import.parties.update');
    Route::delete('/import/parties/{id}', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'destroyParty'])->name('docuperfect.import.parties.destroy');
    Route::post('/import/parties/reorder', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'reorderParties'])->name('docuperfect.import.parties.reorder');
    Route::post('/import/template/{id}/edit', [\App\Http\Controllers\Docuperfect\DocumentImporterController::class, 'editFromTemplate'])->name('docuperfect.import.template.edit');

    // ===== ES-9 / ES-3 — Other Conditions + Strikethrough + Amendment Review =====
    // Add a condition or propose a strikethrough during signing or agent preparation
    Route::post('/signing/{signatureTemplate}/conditions',     [\App\Http\Controllers\Docuperfect\ConditionsController::class, 'storeCondition'])->name('docuperfect.conditions.store');
    Route::post('/signing/{signatureTemplate}/strikethroughs', [\App\Http\Controllers\Docuperfect\ConditionsController::class, 'storeStrikethrough'])->name('docuperfect.strikethroughs.store');

    // Agent review surface
    Route::get('/amendments/{amendment}/review',           [\App\Http\Controllers\Docuperfect\AmendmentController::class, 'review'])->name('docuperfect.amendments.review');
    Route::post('/amendments/{amendment}/approve',         [\App\Http\Controllers\Docuperfect\AmendmentController::class, 'approve'])->name('docuperfect.amendments.approve');
    Route::post('/amendments/{amendment}/reject-change',   [\App\Http\Controllers\Docuperfect\AmendmentController::class, 'rejectChange'])->name('docuperfect.amendments.rejectChange');
    Route::post('/amendments/{amendment}/reject-document', [\App\Http\Controllers\Docuperfect\AmendmentController::class, 'rejectDocument'])->name('docuperfect.amendments.rejectDocument');

    // ===== RENDERER TEST =====
    Route::get('/renderer-test', function () {
        return view('docuperfect.renderer-test');
    })->name('docuperfect.renderer-test');

    // ===== CDS PARSER TEST =====
    Route::get('/parser-test', function () {
        return view('docuperfect.parser-test');
    })->name('docuperfect.parser-test');

    Route::post('/parser-test', function (\Illuminate\Http\Request $request) {
        $request->validate(['document' => 'required|file|mimes:docx']);

        $file = $request->file('document');
        $fullPath = $file->getPathname();

        $parser = new \App\Services\Docuperfect\CdsParserService();
        $cds = $parser->parse($fullPath);

        $renderer = new \App\Services\Docuperfect\CdsRendererService();
        $html = $renderer->render($cds);

        return view('docuperfect.parser-test-result', [
            'cds' => $cds,
            'html' => $html,
            'sectionCount' => count($cds['sections'] ?? []),
            'title' => $cds['title'] ?? 'Unknown',
        ]);
    })->name('docuperfect.parser-test.upload');

    // ===== WEB TEMPLATE PREVIEWS =====
    Route::get('/web-preview/letting-mandate-v5', [\App\Http\Controllers\Docuperfect\WebTemplateController::class, 'lettingMandateV5'])->name('docuperfect.webPreview.lettingMandateV5');
    Route::get('/web-preview/rental-application-v8', [\App\Http\Controllers\Docuperfect\WebTemplateController::class, 'rentalApplicationV8'])->name('docuperfect.webPreview.rentalApplicationV8');
    Route::get('/web-preview/letting-mandatory-disclosure-v7', [\App\Http\Controllers\Docuperfect\WebTemplateController::class, 'lettingMandatoryDisclosureV7'])->name('docuperfect.webPreview.lettingMandatoryDisclosureV7');
    Route::get('/web-preview/letting-marketing-permission-v7', [\App\Http\Controllers\Docuperfect\WebTemplateController::class, 'lettingMarketingPermissionV7'])->name('docuperfect.webPreview.lettingMarketingPermissionV7');
    Route::get('/web-preview/lease-agreement-popi-v8', [\App\Http\Controllers\Docuperfect\WebTemplateController::class, 'leaseAgreementPopiV8'])->name('docuperfect.webPreview.leaseAgreementPopiV8');
    Route::get('/web-preview/commercial-lease-agreement-v5', [\App\Http\Controllers\Docuperfect\WebTemplateController::class, 'commercialLeaseAgreementV5'])->name('docuperfect.webPreview.commercialLeaseAgreementV5');

    // ===== RENTAL DOCUMENTS (redirect to new Rental Division) =====
    Route::get('/rental', function () {
        return redirect()->route('docuperfect.esign.myDocuments');
    })->name('docuperfect.rental');

    // Rental Upload & Send (standalone signing flow)
    Route::get('/rental/upload-and-send', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'showUploadAndSend'])->name('docuperfect.rental.uploadAndSend');
    Route::post('/rental/upload-and-send', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'processUploadAndSend'])->name('docuperfect.rental.uploadAndSend.store');

    // ===== SIGNATURES =====

    // Agent approval gate
    Route::get('/documents/{document}/signatures/review', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'review'])->name('docuperfect.signatures.review');
    Route::post('/documents/{document}/signatures/approve-and-advance', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'approveAndAdvance'])->name('docuperfect.signatures.approveAndAdvance');
    Route::get('/documents/{document}/signatures/authorise-signing', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'authoriseSigning'])->name('docuperfect.signatures.authoriseSigning');
    Route::post('/documents/{document}/signatures/return-to-candidate', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'returnToCandidate'])->name('docuperfect.signatures.returnToCandidate');

    // Dashboard polling
    Route::get('/rental/status-check', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'statusCheck'])->name('docuperfect.rental.statusCheck');

    // Pre-signed document upload
    Route::post('/documents/{document}/signatures/upload-presigned', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'uploadPresigned'])->name('docuperfect.signatures.uploadPresigned');

    // Signature setup
    Route::get('/documents/{document}/signatures/setup', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'setup'])->name('docuperfect.signatures.setup');
    Route::post('/documents/{document}/signatures/parties', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'saveParties'])->name('docuperfect.signatures.saveParties');
    Route::post('/documents/{document}/signatures/markers', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'saveMarkers'])->name('docuperfect.signatures.saveMarkers');
    Route::put('/documents/{document}/signatures/markers', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'updateMarkers'])->name('docuperfect.signatures.updateMarkers');

    // Dynamic signature zones
    Route::get('/documents/{document}/signatures/zones', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'getZones'])->name('docuperfect.signatures.zones');
    Route::post('/documents/{document}/signatures/zones', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'storeZone'])->name('docuperfect.signatures.storeZone');
    Route::post('/documents/{document}/signatures/zones/batch', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'batchStoreZones'])->name('docuperfect.signatures.batchStoreZones');
    Route::put('/documents/{document}/signatures/zones/{zone}', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'updateZone'])->name('docuperfect.signatures.updateZone');
    Route::delete('/documents/{document}/signatures/zones/{zone}', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'deleteZone'])->name('docuperfect.signatures.deleteZone');

    // Internal signing
    Route::get('/documents/{document}/sign', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'sign'])->name('docuperfect.signatures.sign');
    Route::post('/documents/{document}/sign/{marker}', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'captureSignature'])->name('docuperfect.signatures.capture');
    Route::post('/documents/{document}/save-agent-fields', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'saveAgentFields'])->name('docuperfect.signatures.saveAgentFields');
    Route::post('/documents/{document}/save-agent-web-fields', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'saveAgentWebFields'])->name('docuperfect.signatures.saveAgentWebFields');
    Route::post('/documents/{document}/sign-complete', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'signComplete'])->name('docuperfect.signatures.signComplete');
    Route::post('/documents/{document}/web-sign-complete', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'webSignComplete'])->name('docuperfect.signatures.webSignComplete');

    // Send + reminders
    Route::get('/documents/{document}/send-confirmation', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'sendConfirmation'])->name('docuperfect.signatures.sendConfirmation');
    Route::post('/documents/{document}/send-for-signature', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'sendForSignature'])->name('docuperfect.signatures.send');
    Route::post('/documents/{document}/send-reminder/{signatureRequest}', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'sendReminder'])->name('docuperfect.signatures.sendReminder');

    // Audit & download
    Route::get('/documents/{document}/signatures/audit', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'audit'])->name('docuperfect.signatures.audit');
    Route::get('/documents/{document}/signatures/download', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'download'])->name('docuperfect.signatures.download');

    // Wet ink inspection
    Route::get('/documents/{document}/signatures/inspect/{signingRequest}', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'wetInkReview'])->name('docuperfect.signatures.wetInkReview');
    Route::post('/documents/{document}/signatures/inspect/{signingRequest}/decision', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'wetInkDecision'])->name('docuperfect.signatures.wetInkDecision');
    Route::get('/documents/{document}/signatures/inspect/{signingRequest}/file/{fileIndex}', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'wetInkFile'])->name('docuperfect.signatures.wetInkFile');
    Route::post('/documents/{document}/signatures/inspect/{signingRequest}/upload-on-behalf', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'uploadOnBehalf'])->name('docuperfect.signatures.uploadOnBehalf');

    // Deferred signing
    Route::post('/documents/{document}/signatures/resume-deferred', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'resumeDeferred'])->name('docuperfect.signatures.resumeDeferred');

    // Property document dashboard
    Route::get('/property/{propertyId}/documents', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'propertyDocuments'])->name('docuperfect.property.documents');

    // Section signing (agent/internal)
    Route::post('/documents/{document}/sections/accept', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'acceptSection'])->name('docuperfect.signatures.acceptSection');
    Route::get('/documents/{document}/sections/progress', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'getSectionProgress'])->name('docuperfect.signatures.sectionProgress');

    // Amendment review (agent/internal)
    Route::get('/documents/{document}/amendments', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'amendments'])->name('docuperfect.signatures.amendments');
    Route::post('/documents/{document}/amendments/{amendment}/action', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'amendmentAction'])->name('docuperfect.signatures.amendmentAction');

    // Supersede & Reject
    Route::post('/documents/{document}/supersede', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'supersede'])->name('docuperfect.signatures.supersede');
    Route::post('/documents/{document}/reject', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'reject'])->name('docuperfect.signatures.reject');

    // Flattened page images (authenticated)
    Route::get('/signatures/{templateId}/flattened-page/{page}', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'flattenedPageImage'])->name('docuperfect.signatures.flattenedPage');

    // Lease records
    Route::get('/leases', [\App\Http\Controllers\Docuperfect\SignatureController::class, 'leases'])->name('docuperfect.leases.index');

    // Lease lifecycle
    Route::post('/leases/{lease}/renew', [\App\Http\Controllers\Docuperfect\LeaseController::class, 'renewLease'])->name('docuperfect.leases.renew');
    Route::post('/leases/{lease}/terminate', [\App\Http\Controllers\Docuperfect\LeaseController::class, 'terminateLease'])->name('docuperfect.leases.terminate');
    Route::get('/leases/{lease}/history', [\App\Http\Controllers\Docuperfect\LeaseController::class, 'leaseHistory'])->name('docuperfect.leases.history');

    // ===== SALES DOCUMENTS =====
    Route::get('/sales', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'index'])->name('docuperfect.sales');
    Route::get('/sales/send', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'showSendForm'])->name('docuperfect.sales.send');
    Route::post('/sales/send', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'sendToClient'])->name('docuperfect.sales.send.store');
    Route::post('/sales/recipient/{recipient}/mark-returned', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'markAsReturned'])->name('docuperfect.sales.mark-returned');
    Route::post('/sales/recipient/{recipient}/resend', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'resend'])->name('docuperfect.sales.resend');
    Route::post('/sales/recipient/{recipient}/remind', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'sendManualReminder'])->name('docuperfect.sales.remind');
    Route::post('/sales/{send}/approve/{recipient}', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'approveAndSendNext'])->name('docuperfect.sales.approve');
    Route::get('/sales/{send}/download', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'downloadOriginal'])->name('docuperfect.sales.download');
    Route::post('/sales/documents/{document}/upload-signed', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'uploadSignedDocument'])->name('docuperfect.sales.uploadSigned');
    Route::post('/sales/{send}/cancel', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'cancel'])->name('docuperfect.sales.cancel');
    Route::get('/sales/{send}/review/{recipient}', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'reviewUpload'])->name('docuperfect.sales.review');
    Route::get('/sales/{send}/recipient/{recipient}/file/{index}', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'serveReturnedFile'])->name('docuperfect.sales.recipientFile');
    Route::post('/sales/{send}/upload-on-behalf/{recipient}', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'uploadOnBehalf'])->name('docuperfect.sales.uploadOnBehalf');
});

// ===== RENTAL DIVISION =====
Route::prefix('rental')->middleware(['auth', 'permission:view_rentals'])->name('rental.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Rental\RentalDivisionController::class, 'dashboard'])->name('dashboard');
    Route::get('/signatures', [\App\Http\Controllers\Rental\RentalDivisionController::class, 'signatures'])->name('signatures');
    Route::post('/signatures/{document}/assign-metadata', [\App\Http\Controllers\Rental\RentalDivisionController::class, 'assignMetadata'])->name('signatures.assign-metadata');
    Route::post('/signatures/{document}/set-expiry', [\App\Http\Controllers\Rental\RentalDivisionController::class, 'setExpiry'])->name('signatures.set-expiry');
    Route::get('/active-leases', [\App\Http\Controllers\Rental\RentalDivisionController::class, 'activeLeases'])->name('active-leases');
    Route::get('/expired-leases', [\App\Http\Controllers\Rental\RentalDivisionController::class, 'expiredLeases'])->name('expired-leases');
    Route::get('/settings', [\App\Http\Controllers\Rental\RentalDivisionController::class, 'settings'])->name('settings');

    // Settings sub-routes
    Route::prefix('settings')->name('settings.')->group(function () {
        // Properties
        Route::get('/properties', [\App\Http\Controllers\Rental\RentalPropertyController::class, 'index'])->name('properties.index');
        Route::get('/properties/create', [\App\Http\Controllers\Rental\RentalPropertyController::class, 'create'])->name('properties.create');
        Route::post('/properties', [\App\Http\Controllers\Rental\RentalPropertyController::class, 'store'])->name('properties.store');
        Route::get('/properties/{property}/edit', [\App\Http\Controllers\Rental\RentalPropertyController::class, 'edit'])->name('properties.edit');
        Route::put('/properties/{property}', [\App\Http\Controllers\Rental\RentalPropertyController::class, 'update'])->name('properties.update');
        Route::post('/properties/{property}/toggle', [\App\Http\Controllers\Rental\RentalPropertyController::class, 'toggleActive'])->name('properties.toggle');
        Route::get('/properties/search', [\App\Http\Controllers\Rental\RentalPropertyController::class, 'search'])->name('properties.search');

        // Document Types
        Route::get('/document-types', [\App\Http\Controllers\Rental\RentalDocumentTypeController::class, 'index'])->name('document-types.index');
        Route::post('/document-types', [\App\Http\Controllers\Rental\RentalDocumentTypeController::class, 'store'])->name('document-types.store');
        Route::put('/document-types/{type}', [\App\Http\Controllers\Rental\RentalDocumentTypeController::class, 'update'])->name('document-types.update');
        Route::post('/document-types/{type}/toggle', [\App\Http\Controllers\Rental\RentalDocumentTypeController::class, 'toggleActive'])->name('document-types.toggle');

        // Reminders
        Route::get('/reminders', [\App\Http\Controllers\Rental\RentalReminderSettingsController::class, 'index'])->name('reminders.index');
        Route::put('/reminders', [\App\Http\Controllers\Rental\RentalReminderSettingsController::class, 'update'])->name('reminders.update');
    });
});

// ===== SALES DOCUMENT RETURN (public, no auth, token-based) =====
Route::get('/sales-documents/return/{token}', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'showUploadPage'])->name('sales-documents.upload');
Route::post('/sales-documents/return/{token}/verify', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'verifySalesIdentity'])->name('sales-documents.verify');
Route::get('/sales-documents/return/{token}/download', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'downloadForRecipient'])->name('sales-documents.download');
Route::post('/sales-documents/return/{token}', [\App\Http\Controllers\Docuperfect\SalesDocumentController::class, 'handleUpload'])->name('sales-documents.upload.store');

// ===== EXTERNAL SIGNING (no auth, token-based) =====
Route::prefix('sign')->group(function () {
    Route::get('/{token}', [\App\Http\Controllers\Docuperfect\SigningController::class, 'show'])->name('signatures.external');
    Route::get('/{token}/gateway', [\App\Http\Controllers\Docuperfect\SigningController::class, 'gateway'])->name('signatures.external.gateway');
    Route::post('/{token}/verify', [\App\Http\Controllers\Docuperfect\SigningController::class, 'verify'])->name('signatures.external.verify');
    Route::get('/{token}/consent', [\App\Http\Controllers\Docuperfect\SigningController::class, 'showConsent'])->name('signatures.external.showConsent');
    Route::post('/{token}/consent', [\App\Http\Controllers\Docuperfect\SigningController::class, 'captureConsent'])->name('signatures.external.consent');
    Route::get('/{token}/already-signed', [\App\Http\Controllers\Docuperfect\SigningController::class, 'alreadySigned'])->name('signatures.external.alreadySigned');
    Route::post('/{token}/choose-method', [\App\Http\Controllers\Docuperfect\SigningController::class, 'chooseMethod'])->name('signatures.external.chooseMethod');
    Route::post('/{token}/capture/{marker}', [\App\Http\Controllers\Docuperfect\SigningController::class, 'capture'])->name('signatures.external.capture');
    Route::post('/{token}/save-fields', [\App\Http\Controllers\Docuperfect\SigningController::class, 'saveFields'])->name('signatures.external.saveFields');
    Route::post('/{token}/save-web-fields', [\App\Http\Controllers\Docuperfect\SigningController::class, 'saveWebFields'])->name('signatures.external.saveWebFields');
    Route::post('/{token}/complete-web', [\App\Http\Controllers\Docuperfect\SigningController::class, 'completeWeb'])->name('signatures.external.completeWeb');
    Route::post('/{token}/complete', [\App\Http\Controllers\Docuperfect\SigningController::class, 'complete'])->name('signatures.external.complete');
    Route::get('/{token}/completed', [\App\Http\Controllers\Docuperfect\SigningController::class, 'completed'])->name('signatures.external.completed');
    Route::post('/{token}/upload', [\App\Http\Controllers\Docuperfect\SigningController::class, 'uploadWetInk'])->name('signatures.external.upload');
    Route::get('/{token}/download', [\App\Http\Controllers\Docuperfect\SigningController::class, 'downloadForSigning'])->name('signatures.external.download');
    Route::get('/{token}/print', [\App\Http\Controllers\Docuperfect\SigningController::class, 'printView'])->name('signatures.external.print');
    Route::get('/{token}/download-pdf', [\App\Http\Controllers\Docuperfect\SigningController::class, 'downloadWebPdf'])->name('signing.download-pdf');
    Route::get('/{token}/wet-ink-portal', [\App\Http\Controllers\Docuperfect\SigningController::class, 'wetInkPortal'])->name('signatures.external.wetInkPortal');
    Route::post('/{token}/decline', [\App\Http\Controllers\Docuperfect\SigningController::class, 'decline'])->name('signatures.external.decline');
    Route::get('/{token}/flattened-page/{page}', [\App\Http\Controllers\Docuperfect\SigningController::class, 'flattenedPageImage'])->name('signatures.external.flattenedPage');

    // Section signing (external)
    Route::post('/{token}/sections/accept', [\App\Http\Controllers\Docuperfect\SigningController::class, 'acceptSection'])->name('signatures.external.acceptSection');
    Route::post('/{token}/sections/reject', [\App\Http\Controllers\Docuperfect\SigningController::class, 'rejectSection'])->name('signatures.external.rejectSection');
    Route::get('/{token}/sections/progress', [\App\Http\Controllers\Docuperfect\SigningController::class, 'getSectionProgress'])->name('signatures.external.sectionProgress');

    // Amendment review (external — re-signing)
    Route::get('/{token}/amendment-review', [\App\Http\Controllers\Docuperfect\SigningController::class, 'amendmentReview'])->name('signatures.external.amendment-review');
    Route::post('/{token}/amendment/{amendment}/accept', [\App\Http\Controllers\Docuperfect\SigningController::class, 'acceptAmendment'])->name('signatures.external.acceptAmendment');
    Route::post('/{token}/amendment/{amendment}/reject', [\App\Http\Controllers\Docuperfect\SigningController::class, 'rejectAmendment'])->name('signatures.external.rejectAmendment');

    // Phase 1B.5 — recipient Other Conditions / focused initialing
    Route::post('/{token}/conditions',          [\App\Http\Controllers\Docuperfect\SigningController::class, 'addCondition'])->name('signatures.external.addCondition');
    Route::post('/{token}/initial-amendments', [\App\Http\Controllers\Docuperfect\SigningController::class, 'initialAmendments'])->name('signatures.external.initialAmendments');
    // Phase 1B.7 — inline per-condition initialing (distinct from bulk
    // amendment cascade above).
    Route::post('/{token}/conditions/{condition}/initial', [\App\Http\Controllers\Docuperfect\SigningController::class, 'initialCondition'])->name('signatures.external.initialCondition');

    // Phase 1B.6 (FIX 2) — recipient clause-flag (replaces Phase 1B.5 strikethrough modal).
    Route::post('/{token}/flag-clause',         [\App\Http\Controllers\Docuperfect\SigningController::class, 'flagClause'])->name('signatures.external.flagClause');
    // Phase 1B.9 (FIX 1) — recipient self-undo pre-completion.
    Route::delete('/{token}/flag/{clauseRef}',  [\App\Http\Controllers\Docuperfect\SigningController::class, 'removeOwnFlag'])->name('signatures.external.removeOwnFlag');
    // Soft-deprecated Phase 1B.5 endpoint — returns 410 with redirect hint.
    Route::post('/{token}/strikethroughs',      [\App\Http\Controllers\Docuperfect\SigningController::class, 'proposeStrikethrough'])->name('signatures.external.proposeStrikethrough');
});

// Phase 1B.9 (FIX 1) — Flag Removal consent flow.
// Agent-side request (auth required) + recipient consent screen (public,
// token-authenticated).
Route::middleware(['auth'])->group(function () {
    Route::post('/docuperfect/flags/{amendment}/request-removal',
        [\App\Http\Controllers\Docuperfect\FlagRemovalController::class, 'requestRemoval'])
        ->name('docuperfect.flags.requestRemoval');
});
Route::get('/flag-removal/{token}',
    [\App\Http\Controllers\Docuperfect\FlagRemovalController::class, 'showConsent'])
    ->name('signatures.flag-removal.consent.show');
Route::post('/flag-removal/{token}/consent',
    [\App\Http\Controllers\Docuperfect\FlagRemovalController::class, 'submitConsent'])
    ->name('signatures.flag-removal.consent.submit');

// ===== SIGNED DOCUMENT DOWNLOAD (no auth, token-based) =====
Route::get('/documents/download/{token}', [\App\Http\Controllers\Docuperfect\SigningController::class, 'downloadPage'])->name('signatures.download.page');
Route::post('/documents/download/{token}/verify', [\App\Http\Controllers\Docuperfect\SigningController::class, 'downloadVerify'])->name('signatures.download.verify');
Route::get('/documents/download/{token}/file', [\App\Http\Controllers\Docuperfect\SigningController::class, 'downloadSignedFile'])->name('signatures.download.file');

// ===== DOCUMENT LIBRARY =====
Route::middleware(['auth', 'permission:access_document_library'])->prefix('documents')->name('documents.')->group(function () {
    Route::get('/library', [\App\Http\Controllers\Documents\DocumentLibraryController::class, 'index'])
        ->name('library.index');
    Route::post('/library/upload', [\App\Http\Controllers\Documents\DocumentLibraryController::class, 'upload'])
        ->name('library.upload');
    Route::get('/library/{item}/download', [\App\Http\Controllers\Documents\DocumentLibraryController::class, 'download'])
        ->name('library.download');
    Route::post('/library/attach', [\App\Http\Controllers\Documents\DocumentLibraryController::class, 'attach'])
        ->name('library.attach');

    // Document type management
    Route::post('/library/types', [\App\Http\Controllers\Documents\DocumentLibraryController::class, 'storeType'])
        ->name('library.types.store');
    Route::put('/library/types/{documentType}', [\App\Http\Controllers\Documents\DocumentLibraryController::class, 'updateType'])
        ->name('library.types.update');
    Route::delete('/library/types/{documentType}', [\App\Http\Controllers\Documents\DocumentLibraryController::class, 'destroyType'])
        ->name('library.types.destroy');
});

// ===== SHARED DRIVE =====
// Google-Drive-style team file store. Spec: .ai/specs/shared-drive.md
Route::middleware(['auth', 'permission:access_shared_drive'])
    ->prefix('documents/shared-drive')
    ->name('documents.shared-drive.')
    ->group(function () {
        Route::get('/', [\App\Http\Controllers\Documents\SharedDriveController::class, 'index'])
            ->name('index');

        // Drives (top-level containers, optionally access-restricted)
        Route::post('/drives', [\App\Http\Controllers\Documents\SharedDriveController::class, 'storeDrive'])
            ->name('drives.store');
        Route::get('/drives/{drive}', [\App\Http\Controllers\Documents\SharedDriveController::class, 'show'])
            ->name('drive');
        Route::get('/drives/{drive}/folder/{folder}', [\App\Http\Controllers\Documents\SharedDriveController::class, 'show'])
            ->name('folder');
        Route::put('/drives/{drive}/access', [\App\Http\Controllers\Documents\SharedDriveController::class, 'updateDriveAccess'])
            ->name('drives.access');
        Route::delete('/drives/{drive}', [\App\Http\Controllers\Documents\SharedDriveController::class, 'destroyDrive'])
            ->name('drives.destroy');

        Route::post('/folders', [\App\Http\Controllers\Documents\SharedDriveController::class, 'storeFolder'])
            ->name('folders.store');
        Route::delete('/folders/{folder}', [\App\Http\Controllers\Documents\SharedDriveController::class, 'destroyFolder'])
            ->name('folders.destroy');

        Route::post('/upload', [\App\Http\Controllers\Documents\SharedDriveController::class, 'upload'])
            ->name('upload');
        Route::post('/files/bulk-download', [\App\Http\Controllers\Documents\SharedDriveController::class, 'bulkDownload'])
            ->name('files.bulk-download');
        Route::delete('/files/bulk', [\App\Http\Controllers\Documents\SharedDriveController::class, 'destroyFilesBulk'])
            ->name('files.bulk-destroy');
        Route::get('/files/{file}/view', [\App\Http\Controllers\Documents\SharedDriveController::class, 'view'])
            ->name('files.view');
        Route::get('/files/{file}/download', [\App\Http\Controllers\Documents\SharedDriveController::class, 'download'])
            ->name('files.download');
        Route::delete('/files/{file}', [\App\Http\Controllers\Documents\SharedDriveController::class, 'destroyFile'])
            ->name('files.destroy');
    });

// ===== TRACKED PROPERTIES (Prospecting sub-menu) =====
// Universe of properties CoreX knows about, regardless of mandate status.
// Spec: CLAUDE.md HARD RULE #10 (Universal Match-or-Create Rule), Build D.3.
Route::middleware(['auth', 'permission:access_prospecting'])
    ->prefix('corex/tracked-properties')
    ->name('corex.tracked-properties.')
    ->group(function () {
        // Phase D1 — legacy GET root redirects to the Opportunities tab.
        // Phase D4 — legacy GET detail also 301-redirects so any bookmark
        // resolves to the new MIC URL. The POST endpoints (edit, set-primary,
        // promote, merge stub) stay mounted at the original paths because
        // redirecting POSTs would break form submissions.
        Route::redirect('/', '/corex/market-intelligence/opportunities', 301)->name('index');
        Route::get('/{trackedProperty}', function ($trackedProperty) {
            return redirect('/corex/market-intelligence/opportunities/' . $trackedProperty, 301);
        })->where('trackedProperty', '[0-9]+')->name('show');
        Route::post('/{trackedProperty}/promote', [\App\Http\Controllers\CoreX\TrackedPropertyController::class, 'promote'])->name('promote');

        // Phase C3 — address management on the TP detail page.
        Route::post('/{trackedProperty}/address/edit',
            [\App\Http\Controllers\CoreX\TrackedPropertyController::class, 'editAddress'])
            ->middleware('permission:mic.edit_address')
            ->name('address.edit');

        Route::post('/{trackedProperty}/address/add-alternative',
            [\App\Http\Controllers\CoreX\TrackedPropertyController::class, 'addAlternativeAddress'])
            ->middleware('permission:mic.edit_address')
            ->name('address.add-alternative');

        Route::post('/{trackedProperty}/address/{address}/set-primary',
            [\App\Http\Controllers\CoreX\TrackedPropertyController::class, 'setPrimaryAddress'])
            ->middleware('permission:mic.edit_address')
            ->name('address.set-primary');

        Route::get('/{trackedProperty}/merge',
            [\App\Http\Controllers\CoreX\TrackedPropertyController::class, 'stubMergeDuplicate'])
            ->middleware('permission:mic.merge_duplicates')
            ->name('merge');
    });

// ===== MARKET INTELLIGENCE (Build F.1 — rename of Prospecting) =====
// New canonical surface for the canvassing pool. Mirrors every legacy
// prospecting.* route name 1:1 under market-intelligence.*. The legacy group
// below remains mounted for the F.1–F.6 migration window so internal callers
// using route('prospecting.*') keep resolving and rollback is a one-line revert.
//
// GET-only redirects sit BEFORE the legacy group so bookmarked browser hits to
// /prospecting and /prospecting/* land on the new URL without breaking any
// internal Alpine :action="'/prospecting/...'" form posts (which still hit the
// legacy POST routes unchanged).
//
// Spec: .ai/specs/build-f-market-intelligence-redesign-spec.md §6.
Route::middleware(['auth', 'permission:access_prospecting'])
    ->prefix('corex/market-intelligence')
    ->name('market-intelligence.')
    ->group(function () {
        // Phase D1 — four-tab structure. Work is the default landing.
        Route::get('/',              [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'work'])->name('work');
        Route::get('/work',          [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'work']);
        Route::get('/opportunities',                       [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'opportunities'])->name('opportunities');
        Route::get('/opportunities/{tp}',                  [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'opportunityShow'])
            ->where('tp', '[0-9]+')
            ->name('opportunities.show');
        Route::get('/analyse',       [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'analyse'])->name('analyse');
        Route::get('/market-pulse',  [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'marketPulse'])->name('market-pulse');

        // Q4/D1 — "P24 alerts — awaiting address" prospecting list. Surfaces
        // every p24_listings row (no address column → 100% pin-blocked) +
        // every ungeocoded prospecting_listing. Sidebar link below the
        // MIC menu group satisfies CLAUDE.md NN#2 (nav-with-feature).
        Route::get('/portal-alerts', [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'portalAlertsAwaitingAddress'])
            ->name('portal-alerts');

        // Phase G2 — BM team dashboard. Permission-gated via the controller.
        Route::get('/team', [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'team'])->name('team');

        // Phase G3 — feedback-template JSON for the claim slide-over.
        Route::get('/feedback-templates', [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'feedbackTemplates'])->name('feedback-templates');

        // Phase D5 — AI surfaces.
        Route::post('/analyse/regenerate-brief',
            [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'regenerateBrief'])
            ->middleware('permission:mic.regenerate_brief')
            ->name('brief.regenerate');
        Route::get('/analyse/pocket-narrative',
            [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'pocketNarrative'])
            ->name('pocket-narrative');
        Route::get('/suburb/{suburb}',
            [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'suburbDeepDive'])
            ->where('suburb', '[A-Za-z0-9 \-\&\']+')
            ->name('suburb-deep-dive');

        // Phase E3 — per-listing "why this matches" tooltip (Sonnet 4.6).
        Route::get('/listing/{listing}/match-tooltip',
            [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'matchTooltip'])
            ->name('match-tooltip');

        // Phase F — CMA report import pipeline. Every route gated by
        // permission:mic.upload_reports (Laravel 11 — middleware is at the
        // route level, not the controller constructor).
        //
        // Report-lifecycle Phase 1: the {report} param binds via
        // withTrashed() so soft-deleted (archived) reports resolve. The
        // show view renders an "Archived" banner + Restore button when
        // $report->trashed(). Without this binding, /reports/<trashed_id>
        // returns 404 — the report-90 symptom.
        Route::prefix('reports')->name('reports.')
            ->middleware('permission:mic.upload_reports')
            ->group(function () {
                Route::bind('report', function (string $value) {
                    return \App\Models\MarketReports\MarketReport::query()
                        ->withTrashed()
                        ->findOrFail((int) $value);
                });

                Route::get('/',                       [\App\Http\Controllers\CoreX\MarketReportController::class, 'index'])->name('index');
                Route::get('/create',                 [\App\Http\Controllers\CoreX\MarketReportController::class, 'create'])->name('create');
                Route::post('/',                      [\App\Http\Controllers\CoreX\MarketReportController::class, 'store'])->name('store');
                // Phase 3c — bulk multi-file import (declared BEFORE /{report} so
                // model-binding doesn't shadow the literal segment).
                Route::get('/bulk-import',            [\App\Http\Controllers\CoreX\MarketReportController::class, 'bulkImportShow'])->name('bulk-import');
                Route::post('/bulk-import',           [\App\Http\Controllers\CoreX\MarketReportController::class, 'bulkImportStore'])->name('bulk-import.store');
                Route::get('/parsers',                [\App\Http\Controllers\CoreX\MarketReportController::class, 'parserDashboard'])->name('parser-dashboard');
                Route::get('/{report}',               [\App\Http\Controllers\CoreX\MarketReportController::class, 'show'])->name('show');
                Route::delete('/{report}',            [\App\Http\Controllers\CoreX\MarketReportController::class, 'destroy'])->name('destroy');
                Route::post('/{report}/spot-check',   [\App\Http\Controllers\CoreX\MarketReportController::class, 'runSpotCheck'])->name('spot-check');
                Route::get('/{report}/discrepancies', [\App\Http\Controllers\CoreX\MarketReportController::class, 'discrepancies'])->name('discrepancies');

                // Phase 4 — re-parse keeps the market_reports row + original PDF,
                // clears existing data_points + comp_rows for the report, and
                // re-dispatches the parse job. Permission is the upload role
                // because re-parsing is conceptually within the agent's
                // ingest workflow (parser improved → re-extract).
                Route::post('/{report}/reparse', [\App\Http\Controllers\CoreX\MarketReportController::class, 'reparse'])->name('reparse');

                // Phase 2 — restore an archived report. Tighter permission:
                // mic.restore_reports (admin/super_admin only). Agents can
                // archive their own uploads but can't undo another agent's.
                Route::post('/{report}/restore', [\App\Http\Controllers\CoreX\MarketReportController::class, 'restore'])
                    ->middleware('permission:mic.restore_reports')
                    ->name('restore');
            });

        // Intelligence layer — declared before the {listing} catch-alls so
        // model-binding doesn't shadow them.
        Route::get('/snapshot.json', [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'snapshotJson'])->name('snapshot');
        Route::get('/segment/{dimension}/{value}/buyers',  [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'segmentBuyers'])
            ->where('dimension', 'town|property_type|bedrooms|price_band|unmapped_suburb')
            ->name('segment.buyers');
        Route::get('/segment/{dimension}/{value}/listings', [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'segmentListings'])
            ->where('dimension', 'town|property_type|bedrooms|price_band|unmapped_suburb')
            ->name('segment.listings');

        Route::get('/{listing}/buyer-matches', [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'buyerMatches'])
            ->name('buyer-matches');

        // F.4 — slide-over detail panel (async-rendered HTML)
        Route::get('/{listing}/details', [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'details'])
            ->name('details');

        // F.4 — claim-owner / manager adds a timestamped note to the active claim
        Route::post('/{listing}/note', [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'addNote'])
            ->name('add-note');

        Route::post('/claims/{claimId}/release-as-manager', [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'releaseAsManager'])
            ->where('claimId', '\d+')
            ->name('claims.release-as-manager');

        Route::get('/thumbnail/{listing}', [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'thumbnail'])->name('thumbnail');
        Route::post('/{listing}/claim',    [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'claim'])->name('claim');
        Route::post('/{listing}/feedback', [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'feedback'])->name('feedback');
        Route::post('/{listing}/release',  [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'release'])->name('release');
        Route::get('/{listing}',           [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'show'])->name('show');
    });

// ===== PROSPECTING (legacy URL prefix — Phase I1 retirement) =====
// Phase I1 retired ProspectingController. The /prospecting URL prefix
// remains mounted (preserves the prospecting.* route names that legacy
// blade partials in resources/views/prospecting/ still reference + keeps
// any external bookmarks working) but every handler now lives on
// MarketIntelligenceController. The controller file ProspectingController
// .php has been deleted.
Route::middleware(['auth', 'permission:access_prospecting'])->prefix('prospecting')->name('prospecting.')->group(function () {
    Route::get('/', [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'work'])->name('index');

    Route::get('/snapshot.json', [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'snapshotJson'])->name('snapshot');
    Route::get('/segment/{dimension}/{value}/buyers',  [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'segmentBuyers'])
        ->where('dimension', 'town|property_type|bedrooms|price_band|unmapped_suburb')
        ->name('segment.buyers');
    Route::get('/segment/{dimension}/{value}/listings', [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'segmentListings'])
        ->where('dimension', 'town|property_type|bedrooms|price_band|unmapped_suburb')
        ->name('segment.listings');

    Route::get('/{listing}/buyer-matches', [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'buyerMatches'])
        ->name('buyer-matches');

    Route::post('/claims/{claimId}/release-as-manager', [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'releaseAsManager'])
        ->where('claimId', '\d+')
        ->name('claims.release-as-manager');

    Route::get('/thumbnail/{listing}', [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'thumbnail'])->name('thumbnail');
    Route::post('/{listing}/claim',    [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'claim'])->name('claim');
    Route::post('/{listing}/feedback', [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'feedback'])->name('feedback');
    Route::post('/{listing}/release',  [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'release'])->name('release');
    Route::get('/{listing}',           [\App\Http\Controllers\CoreX\MarketIntelligenceController::class, 'show'])->name('show');
});

// Bookmark-continuity redirect from the legacy bare /prospecting URL to the new
// Market Intelligence canonical URL. Registered AFTER the legacy group so it
// wins URI matching for "/prospecting" (Laravel's last-write-wins behaviour on
// identical URIs). Sub-paths (/prospecting/{listing}, /prospecting/snapshot.json,
// etc.) intentionally remain on the legacy controller for the F.1 migration
// window so internal callers using route('prospecting.*') and existing Alpine
// :action="'/prospecting/...'" form posts keep working unchanged. F.6 retires
// the legacy surface entirely.
//
// Query string is preserved by request()->getQueryString().
//
// Spec: .ai/specs/build-f-market-intelligence-redesign-spec.md §6.
// Note: this route reuses the name `prospecting.index` so any internal caller
// that still does route('prospecting.index') gets the legacy URL (which then
// 301s through to the new canonical URL). Without this name, the route() helper
// would throw RouteNotFoundException since Laravel drops the previous URI's
// name from the registry when a new route registers the same method+URI.
Route::get('/prospecting', function () {
    $qs = request()->getQueryString();
    return redirect('/corex/market-intelligence' . ($qs ? '?' . $qs : ''), 301);
})->name('prospecting.index');

// ===== SELLER INFO PUBLIC PAGE (no auth, token-based) =====
Route::get('/info/{token}', [\App\Http\Controllers\Compliance\SellerInfoPublicController::class, 'show'])->name('seller-info.public');

// ===== FICA PUBLIC FORM (no auth, token-based) =====
Route::prefix('fica')->group(function () {
    Route::get('/{token}', [\App\Http\Controllers\Compliance\FicaPublicController::class, 'form'])->name('fica.form');
    Route::post('/{token}', [\App\Http\Controllers\Compliance\FicaPublicController::class, 'submit'])->name('fica.submit');
    Route::post('/{token}/upload', [\App\Http\Controllers\Compliance\FicaPublicController::class, 'uploadDocument'])->name('fica.upload');
    Route::get('/{token}/confirmation', [\App\Http\Controllers\Compliance\FicaPublicController::class, 'confirmation'])->name('fica.confirmation');
});

// Portal capture ingest endpoint (outside presentation prefix — extension posts here)
// Uses auth.portal_capture: session auth OR bearer token (for Chrome extension)
Route::middleware(['auth.portal_capture'])->post('/portal-captures/ingest', [\App\Http\Controllers\Presentation\PortalCaptureController::class, 'ingest'])
    ->name('portal-captures.ingest');

// WhatsApp capture ingest (AT-34). Per-device Bearer token (auth.wa_capture).
// Mirrors the portal-capture ingest pattern — a machine endpoint for the
// read-only WhatsApp Web extension, not the session API.
Route::middleware(['auth.wa_capture'])->post('/communications/wa/ingest', [\App\Http\Controllers\Communications\WaIngestController::class, 'ingest'])
    ->name('communications.wa.ingest');

// WhatsApp capture contact-check (AT-44). Same per-device Bearer auth as ingest.
// Answers "is each of these numbers a CoreX contact?" so the extension can pick
// per-chat capture depth (contact → backfill history; unknown → forward-only)
// WITHOUT the agency contact list ever leaving the server. Read-only lookup;
// the authoritative archive gate still runs in WaArchiveIngestor on ingest.
Route::middleware(['auth.wa_capture'])->post('/communications/wa/contact-check', [\App\Http\Controllers\Communications\WaIngestController::class, 'contactCheck'])
    ->name('communications.wa.contact-check');

// WhatsApp capture liveness heartbeat (AT-44). Same per-device Bearer auth.
// The extension pings on load + interval; auth.wa_capture stamps last_seen_at.
// Proves the injection -> CORS -> auth pipe independent of WhatsApp DOM detection.
Route::middleware(['auth.wa_capture'])->post('/communications/wa/ping', [\App\Http\Controllers\Communications\WaIngestController::class, 'ping'])
    ->name('communications.wa.ping');

// AT-135 — numbers with unreadable bodies, for the read-only backfill sweep.
Route::middleware(['auth.wa_capture'])->get('/communications/wa/backfill-targets', [\App\Http\Controllers\Communications\WaIngestController::class, 'backfillTargets'])
    ->name('communications.wa.backfill-targets');

// AT-149 — WAHA server-session webhook. WAHA posts ONE message per webhook; the
// controller maps it (WahaWebhookAdapter) into the messages[] contract and feeds
// the EXISTING WaArchiveIngestor. Authenticated by the WAHA HMAC/secret
// (waha.webhook middleware) — never accepts an unauthenticated POST. Machine
// endpoint (like the extension ingest), so it lives beside the wa/* capture
// routes rather than in the session API.
Route::middleware(['waha.webhook'])->post('/communications/wa/webhook', [\App\Http\Controllers\Communications\WaSessionWebhookController::class, 'handle'])
    ->name('communications.wa.webhook');

