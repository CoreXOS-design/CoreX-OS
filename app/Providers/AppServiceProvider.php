<?php

namespace App\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use App\Models\Agency;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventFeedback;
use App\Models\CommandCenter\CommandTask;
use App\Models\Contact;
use App\Models\ContactAccessLog;
use App\Models\ContactConsentRecord;
use App\Models\ContactMatch;
use App\Models\Deal;
use App\Models\DealMoneyLine;
use App\Models\DealSettlement;
use App\Models\Presentation;
use App\Models\Property;
use App\Events\Contracts\DomainEvent;
use App\Events\Prospecting\BedroomSegmentConfigured;
use App\Events\Prospecting\PriceBandConfigured;
use App\Events\Prospecting\PropertyTypeConfigured;
use App\Events\Prospecting\SuburbMappingChanged;
use App\Events\Prospecting\TownConfigured;
use App\Listeners\Audit\RecordDomainEvent;
use App\Listeners\Prospecting\InvalidateProspectingConfigurationCache;
use App\Observers\AgencyObserver;
use App\Observers\CalendarEventFeedbackObserver;
use App\Observers\CalendarEventObserver;
use App\Observers\CommandTaskObserver;
use App\Observers\ContactAccessLogObserver;
use App\Observers\ContactConsentRecordObserver;
use App\Observers\ContactMatchObserver;
use App\Observers\ContactObserver;
use App\Observers\DealObserver;
use App\Observers\DealSettlementObserver;
use App\Observers\PresentationObserver;
use App\Observers\PropertyObserver;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Services\CommandCenter\Calendar\CalendarThresholdResolver::class);
        $this->app->singleton(\App\Services\CommandCenter\Calendar\CalendarVisibilityResolver::class);
        $this->app->singleton(\App\Services\CommandCenter\Calendar\CalendarNotificationDispatcher::class);
        $this->app->singleton(\App\Services\CommandCenter\Calendar\CalendarSourceRegistry::class);

        // Prospecting configuration service: singleton so the cache-invalidation
        // listener and the controllers / consumers share the same per-request
        // cache instance. Without this, the listener would clear a fresh
        // empty cache and the controller's pre-cached state would remain stale.
        $this->app->singleton(\App\Services\Prospecting\ProspectingConfigurationService::class);

        // Universal Match-or-Create hub: singleton so every ingress path
        // (CMA propagation, P24, PP, Chrome capture, manual entry, mandate
        // promotion) shares the same instance. The service is stateless but
        // singleton-binding it makes test substitution + future caching trivial.
        // See CLAUDE.md Universal Match-or-Create Rule.
        $this->app->singleton(\App\Services\Prospecting\TrackedPropertyMatchOrCreateService::class);

        // Seller-outreach services. Singletons so the composer's per-request
        // template lookup and the landing service's repeat snapshot calls
        // share state with future cache-invalidation listeners (Prompt 03).
        $this->app->singleton(\App\Services\SellerOutreach\SellerOutreachTemplateValidator::class);
        $this->app->singleton(\App\Services\SellerOutreach\SellerOutreachComposerService::class);
        $this->app->singleton(\App\Services\SellerOutreach\SellerOutreachSenderService::class);
        $this->app->singleton(\App\Services\SellerOutreach\SellerOutreachLandingService::class);
        $this->app->singleton(\App\Services\SellerOutreach\SellerOutreachOptOutService::class);

        // MIC Phase B1 — Anthropic gateway + cost aggregator. Singletons so
        // the cache lookup, retry config, and pricing table resolve once per
        // request. The gateway is stateless; the cost aggregator is read-only.
        // Spec: .ai/specs/mic-complete-spec.md §4.8.
        $this->app->singleton(\App\Services\AI\AnthropicGateway::class);
        $this->app->singleton(\App\Services\AI\AICostAggregator::class);

        // Push transport — the single funnel for every device push flows through
        // App\Services\Push\PushNotificationService, which depends on this contract.
        // Bind the real FCM (kreait) transport when Firebase credentials resolve;
        // otherwise degrade to a no-op transport so local/CI never crash for lack
        // of a push provider. The dispatch guards (idempotency, rate cap, token
        // dedup, bounded retry) live in the service and run regardless of which
        // transport is bound. See .ai/specs/push-notifications.md.
        $this->app->singleton(\App\Services\Push\Contracts\PushTransport::class, function ($app) {
            try {
                if (! interface_exists(\Kreait\Firebase\Contract\Messaging::class)) {
                    return new \App\Services\Push\NullPushTransport();
                }
                return new \App\Services\Push\FcmService(
                    $app->make(\Kreait\Firebase\Contract\Messaging::class)
                );
            } catch (\Throwable $e) {
                return new \App\Services\Push\NullPushTransport();
            }
        });
        $this->app->singleton(\App\Services\Push\PushNotificationService::class);
    }

    public function boot(): void
    {
        // Build 1 — Str::humanType. Single source for property-type display
        // ("vacant_land" → "Vacant Land"). Used by every presentation view
        // (cover, summary table, composite list headers) so we never drift
        // back into the BUG-4 ucfirst-with-underscore display.
        \Illuminate\Support\Str::macro('humanType', function (?string $type): string {
            $t = trim((string) ($type ?? ''));
            if ($t === '') return '—';
            return \Illuminate\Support\Str::headline(str_replace(['_', '-'], ' ', $t));
        });

        Agency::observe(AgencyObserver::class);
        CalendarEventFeedback::observe(CalendarEventFeedbackObserver::class);
        CalendarEvent::observe(CalendarEventObserver::class);
        Contact::observe(ContactObserver::class);
        ContactAccessLog::observe(ContactAccessLogObserver::class);
        ContactConsentRecord::observe(ContactConsentRecordObserver::class);
        ContactMatch::observe(ContactMatchObserver::class);
        Deal::observe(DealObserver::class);
        DealMoneyLine::observe(\App\Observers\DealMoneyLineObserver::class);
        Presentation::observe(PresentationObserver::class);
        DealSettlement::observe(DealSettlementObserver::class);
        Property::observe(PropertyObserver::class);
        CommandTask::observe(CommandTaskObserver::class);
        CommandTask::observe(\App\Observers\CommandTaskPortalLeadObserver::class);

        // Portal Leads: log every new portal lead (extension point for future
        // Slack/push integrations). See .ai/specs/portal-leads.md.
        Event::listen(
            \App\Events\Leads\NewPortalLeadReceived::class,
            \App\Listeners\Leads\LogPortalLeadReceived::class,
        );
        Event::listen(
            \App\Events\Leads\NewPortalLeadReceived::class,
            \App\Listeners\Leads\PushNewPortalLeadToMobile::class,
        );
        \App\Models\ProspectingListing::observe(\App\Observers\ProspectingListingObserver::class);
        \App\Models\DealV2\DealV2::observe(\App\Observers\DealV2Observer::class);
        \App\Models\DealV2\DealStepInstance::observe(\App\Observers\DealStepInstanceObserver::class);

        // MIC Phase A3 — TrackedPropertyAddress observer keeps the cached
        // address fields on tracked_properties in sync with the primary row.
        // Spec: .ai/specs/mic-complete-spec.md §3.2.1.
        \App\Models\Prospecting\TrackedPropertyAddress::observe(\App\Observers\TrackedPropertyAddressObserver::class);

        // Register calendar source services (Phase 1)
        $registry = $this->app->make(\App\Services\CommandCenter\Calendar\CalendarSourceRegistry::class);
        $registry->register(\App\Services\CommandCenter\Calendar\Sources\ComplianceCalendarSource::class);
        $registry->register(\App\Services\CommandCenter\Calendar\Sources\DealCalendarSource::class);
        $registry->register(\App\Services\CommandCenter\Calendar\Sources\PropertyCalendarSource::class);
        $registry->register(\App\Services\CommandCenter\Calendar\Sources\RentalCalendarSource::class);
        $registry->register(\App\Services\CommandCenter\Calendar\Sources\PayrollCalendarSource::class);
        $registry->register(\App\Services\CommandCenter\Calendar\Sources\DocumentCalendarSource::class);
        $registry->register(\App\Services\CommandCenter\Calendar\Sources\PeopleCalendarSource::class);
        $registry->register(\App\Services\CommandCenter\Calendar\Sources\RecurringCalendarSource::class);

        // Domain events: every concrete DomainEvent is recorded to
        // domain_event_log by RecordDomainEvent. Spec:
        // .ai/specs/corex-domain-events-spec.md Section 6, E6.
        //
        // Laravel's dispatcher resolves listeners by interface, not by parent
        // class — so this listens on the DomainEvent interface, which every
        // AbstractDomainEvent subclass implements transitively.
        Event::listen(DomainEvent::class, RecordDomainEvent::class);

        // ─────────────────────────────────────────────────────────────────
        // MIC Phase A3 — log every activity-relevant domain event to
        // agent_activity_events. Spec §14.6: ONE listener for now;
        // additional listeners (points engine, etc.) hook into the same
        // events without rewrites.
        //
        // Listed explicitly (per spec instruction) rather than subscribing
        // to the DomainEvent base, so the activity log captures only the
        // events intentionally categorised as "agent activity" — not, e.g.,
        // configuration-change events or migration-bookkeeping events.
        // ─────────────────────────────────────────────────────────────────
        foreach ([
            // 14.1 Tracked Property
            \App\Events\Prospecting\TrackedPropertyCreated::class,
            \App\Events\Prospecting\TrackedPropertyEnriched::class,
            \App\Events\Prospecting\TrackedPropertyPromotedToStock::class,
            \App\Events\Prospecting\TrackedPropertyAddressAdded::class,
            \App\Events\Prospecting\TrackedPropertyAddressVerified::class,
            \App\Events\Prospecting\TrackedPropertyAddressPrimaryChanged::class,
            \App\Events\Prospecting\TrackedPropertyMerged::class,
            // 14.2 Claims
            \App\Events\Prospecting\ClaimCreated::class,
            \App\Events\Prospecting\ClaimConvertedFromLock::class,
            \App\Events\Prospecting\ClaimFeedbackRecorded::class,
            \App\Events\Prospecting\ClaimFlaggedAsStale::class,
            \App\Events\Prospecting\ClaimReleased::class,
            \App\Events\Prospecting\ClaimAutoReleased::class,
            // 14.3 Communication
            \App\Events\Communication\WhatsAppDraftOpened::class,
            \App\Events\Communication\WhatsAppMessageSent::class,
            \App\Events\Communication\EmailDraftOpened::class,
            \App\Events\Communication\EmailMessageSent::class,
            \App\Events\Communication\CallLogged::class,
            // 14.4 Market Reports
            \App\Events\MarketReports\MarketReportUploaded::class,
            \App\Events\MarketReports\MarketReportParsed::class,
            \App\Events\MarketReports\MarketReportSpotCheckFlagged::class,
            \App\Events\MarketReports\MarketDataPointSuperseded::class,
            // 14.5 AI
            \App\Events\AI\AINarrativeGenerated::class,
            \App\Events\AI\AINarrativeFailedFallback::class,
            // Phase B2 — agency budget signals.
            \App\Events\AI\AgencyAiBudgetWarning::class,
            \App\Events\AI\AgencyAiBudgetCapped::class,
            // Presentations Phase 8 — outcome capture lifecycle.
            \App\Events\Presentation\PresentationOutcomeRecorded::class,
            \App\Events\Presentation\PresentationOutcomePrompted::class,
            \App\Events\Presentation\PresentationOutcomeLocked::class,
            // Phase 3j — SG document save.
            \App\Events\Property\PropertySgDocumentSaved::class,
            // Phase 9d — RCR submission lifecycle.
            \App\Events\Compliance\RcrSubmissionSubmitted::class,
            // Phase A.2 — map workspace launches.
            \App\Events\Map\MapPitchLaunched::class,
            \App\Events\Map\MapWhatsAppLaunched::class,
            \App\Events\Map\MapContactOwnerLaunched::class,
            \App\Events\Map\MapComparableAdded::class,
            \App\Events\Map\MapCmaOpened::class,
            // Phase A.2.1 — "Prospect Now" from competitor active listings.
            \App\Events\Map\MapProspectLaunched::class,
            // Phase A.2.3 — portal-strip click on an HFC listing.
            \App\Events\Map\MapListingOpened::class,
            // Phase A.2.4 — Copy ID click on a sensitive fact (PII audit).
            \App\Events\Map\MapIdCopied::class,
            // Phase A.2.5 — agent overrode a coordinate-with-X prompt.
            \App\Events\Map\MapProspectOverride::class,
        ] as $micActivityEvent) {
            Event::listen($micActivityEvent, \App\Listeners\Activity\LogAgentActivity::class);
        }

        // ─────────────────────────────────────────────────────────────────
        // SPINE-2 — Activity Points instant-credit listener. Wires the
        // Phase-1 high-value existing-dispatch domain events into
        // InstantPointService::credit / revoke. One listener class with
        // per-event handler methods; the [Class, 'method'] tuple binds
        // each event to its matching slug-credit translation.
        //
        // Governing principle (Johan, locked): SCORE THE ACTION, NOT THE
        // OUTCOME. Outcomes (lost/declined/rejected) do NOT revoke; only
        // genuine reversals do (deal un-registered handled here via
        // DealStatusChanged R→other; deal soft-delete handled in
        // DealObserver::deleted so the Eloquent model event drives it).
        //
        // Every handler is failure-isolated inside the listener (see
        // CreditInstantActionListener::safeCredit/safeRevoke). The
        // InstantPointService also wraps internally — defence-in-depth.
        // ─────────────────────────────────────────────────────────────────
        $spineCredits = [
            // SPINE-2 — Phase-1 high-value existing dispatches
            \App\Events\Contact\ContactCreated::class                       => 'handleContactCreated',
            \App\Events\Deal\DealCreated::class                             => 'handleDealCreated',
            \App\Events\Deal\DealStageAdvanced::class                       => 'handleDealStageAdvanced',
            \App\Events\Deal\DealClosed::class                              => 'handleDealClosed',
            \App\Events\Deal\DealStatusChanged::class                       => 'handleDealStatusChanged',
            \App\Events\PresentationGenerated::class                        => 'handlePresentationGenerated',
            \App\Events\Presentation\PresentationOutcomeRecorded::class     => 'handlePresentationOutcomeRecorded',
            \App\Events\SellerOutreach\PitchSent::class                     => 'handlePitchSent',
            \App\Events\Prospecting\TrackedPropertyPromotedToStock::class   => 'handleTrackedPropertyPromotedToStock',
            \App\Events\Compliance\RcrSubmissionSubmitted::class            => 'handleRcrSubmissionSubmitted',
            // SPINE-3 — FICA (outcome-independent reviewer credit + agent
            // submitter credit), MIC claim (created + manual-release
            // revoke), property lifecycle (captured/published/compliance),
            // marketing (post first goes live). Two events route into
            // fica.reviewed: approved + rejected, per Johan's V1 rule
            // (the review work is the score, the decision doesn't matter).
            \App\Events\Fica\FicaSubmitted::class                           => 'handleFicaSubmitted',
            \App\Events\Fica\FicaApproved::class                            => 'handleFicaApprovedReview',
            \App\Events\Fica\FicaRejected::class                            => 'handleFicaRejectedReview',
            \App\Events\Prospecting\ClaimCreated::class                     => 'handleClaimCreated',
            \App\Events\Prospecting\ClaimReleased::class                    => 'handleClaimReleased',
            \App\Events\Property\PropertyCaptured::class                    => 'handlePropertyCaptured',
            \App\Events\Property\PropertyPublished::class                   => 'handlePropertyPublished',
            \App\Events\Property\PropertyCompliancePassed::class            => 'handlePropertyCompliancePassed',
            \App\Events\Marketing\MarketingPostPublished::class             => 'handleMarketingPostPublished',
        ];
        foreach ($spineCredits as $event => $method) {
            Event::listen($event, [\App\Listeners\Activity\CreditInstantActionListener::class, $method]);
        }

        // SPINE-3 — model observers that dispatch the missing domain events
        // (ProspectingClaim::created → ClaimCreated; updated released_at
        // NULL→set → ClaimReleased; PropertyMarketingPost::updated
        // status→published → MarketingPostPublished). Other domains
        // (Property, Fica) already had the observer/dispatch in place;
        // these two are the gap-fillers.
        \App\Models\ProspectingClaim::observe(\App\Observers\ProspectingClaimObserver::class);
        \App\Models\PropertyMarketingPost::observe(\App\Observers\PropertyMarketingPostObserver::class);

        // SPINE-2.5 — credit attendees added to a calendar event AFTER
        // its initial create. Pre-attached attendees (same transaction
        // as event create) are handled by ProvisionalPointService::
        // creditPreAttachedAttendees called from CalendarEventObserver::
        // created. This observer covers the late-add path only.
        \App\Models\CommandCenter\CalendarEventInvitation::observe(\App\Observers\CalendarEventInvitationObserver::class);

        // MIC Phase B2 — narrative cache invalidation on upstream input changes.
        // Each listener is failure-isolated (try/catch + log) so a cache-cleanup
        // hiccup never breaks the originating domain event. Spec §4.8.
        Event::listen(
            \App\Events\Prospecting\TrackedPropertyAddressVerified::class,
            \App\Listeners\AI\InvalidateOnTrackedPropertyAddressVerified::class,
        );
        Event::listen(
            \App\Events\MarketReports\MarketReportParsed::class,
            \App\Listeners\AI\InvalidateOnMarketReportParsed::class,
        );
        foreach ([
            \App\Events\Prospecting\ClaimCreated::class,
            \App\Events\Prospecting\ClaimReleased::class,
            \App\Events\Prospecting\ClaimAutoReleased::class,
        ] as $claimEvent) {
            Event::listen($claimEvent, \App\Listeners\AI\InvalidateOnClaimChange::class);
        }

        // Prospecting setup: clear the ProspectingConfigurationService's
        // per-request cache for the affected agency on any configuration write.
        // Sync — invalidation must complete before the next read in the same
        // request, otherwise the consumer sees stale data.
        // Spec: .ai/specs/prospecting-setup-spec.md S7, Section 8.
        foreach ([
            TownConfigured::class,
            SuburbMappingChanged::class,
            PropertyTypeConfigured::class,
            BedroomSegmentConfigured::class,
            PriceBandConfigured::class,
        ] as $prospectingEvent) {
            Event::listen($prospectingEvent, InvalidateProspectingConfigurationCache::class);
        }

        // Seller outreach: contact-timeline append on send / click / opt-out.
        // Failure-isolated — see AppendOutreachToContactTimeline.
        // Spec: .ai/specs/seller-outreach-spec.md S11.
        foreach ([
            \App\Events\SellerOutreach\PitchSent::class,
            \App\Events\SellerOutreach\PitchClicked::class,
            \App\Events\SellerOutreach\OptOutRecorded::class,
        ] as $outreachTimelineEvent) {
            Event::listen($outreachTimelineEvent, \App\Listeners\SellerOutreach\AppendOutreachToContactTimeline::class);
        }

        // Seller outreach: opt-out flag setter. Re-throws on failure —
        // compliance-critical per spec S9 (POPIA).
        Event::listen(
            \App\Events\SellerOutreach\OptOutRecorded::class,
            \App\Listeners\SellerOutreach\RecordOptOutOnContact::class,
        );

        // Wave 6: pillar paper-trail listeners. Each pillar has a dedicated
        // Log<Pillar>Event listener that writes a structured Log::info line for
        // every event in that pillar. The wildcard RecordDomainEvent already
        // captures the full payload in domain_event_log; these per-pillar
        // listeners give operators a lightweight tail-able trail per
        // .ai/specs/corex-domain-events-spec.md (Non-Negotiable #9).
        $wave6 = [
            // Deal pillar
            \App\Events\Deal\DealCreated::class              => \App\Listeners\Deal\LogDealEvent::class,
            \App\Events\Deal\DealStatusChanged::class        => \App\Listeners\Deal\LogDealEvent::class,
            \App\Events\Deal\DealStageAdvanced::class        => \App\Listeners\Deal\LogDealEvent::class,
            \App\Events\Deal\DealClosed::class               => \App\Listeners\Deal\LogDealEvent::class,
            \App\Events\Deal\DealMoneyLineChanged::class     => \App\Listeners\Deal\LogDealEvent::class,
            \App\Events\Deal\DealCommissionFinalised::class  => \App\Listeners\Deal\LogDealEvent::class,
            // Contact pillar
            \App\Events\Contact\ContactCreated::class           => \App\Listeners\Contact\LogContactEvent::class,
            \App\Events\Contact\ContactMergedInto::class        => \App\Listeners\Contact\LogContactEvent::class,
            \App\Events\Contact\ContactTagged::class            => \App\Listeners\Contact\LogContactEvent::class,
            \App\Events\Contact\ContactConsentChanged::class    => \App\Listeners\Contact\LogContactEvent::class,
            \App\Events\Contact\ContactLinkedToProperty::class  => \App\Listeners\Contact\LogContactEvent::class,
            // Agent pillar
            \App\Events\Agent\AgentActivated::class             => \App\Listeners\Agent\LogAgentEvent::class,
            \App\Events\Agent\AgentDeactivated::class           => \App\Listeners\Agent\LogAgentEvent::class,
            \App\Events\Agent\AgentFfcStatusChanged::class      => \App\Listeners\Agent\LogAgentEvent::class,
            \App\Events\Agent\AgentCommissionPlanChanged::class => \App\Listeners\Agent\LogAgentEvent::class,
            \App\Events\Agent\AgentBranchAssigned::class        => \App\Listeners\Agent\LogAgentEvent::class,
            // Mandate pillar
            \App\Events\Mandate\MandateSigned::class    => \App\Listeners\Mandate\LogMandateEvent::class,
            \App\Events\Mandate\MandateExpired::class   => \App\Listeners\Mandate\LogMandateEvent::class,
            \App\Events\Mandate\MandateConverted::class => \App\Listeners\Mandate\LogMandateEvent::class,
            // NOTE: MandateExpired ALSO triggers App\Listeners\Mandate\
            // DesyndicateExpiredMandate (takes the property off P24 / Private
            // Property / agency websites). That listener is wired by Laravel's
            // automatic listener discovery — do NOT add an explicit Event::listen
            // for it here, or it double-registers and fires twice (delisting
            // twice). LogMandateEvent above is safe to list explicitly because it
            // type-hints AbstractDomainEvent, so discovery binds it to the base
            // contract, not to MandateExpired. Audit: .ai/audits/
            // mandate-expiry-desyndication-2026-06-20.md
            // FICA pillar
            \App\Events\Fica\FicaSubmitted::class => \App\Listeners\Fica\LogFicaEvent::class,
            \App\Events\Fica\FicaApproved::class  => \App\Listeners\Fica\LogFicaEvent::class,
            \App\Events\Fica\FicaRejected::class  => \App\Listeners\Fica\LogFicaEvent::class,
            \App\Events\Fica\FicaExpired::class   => \App\Listeners\Fica\LogFicaEvent::class,
            // Document pillar
            \App\Events\Document\DocumentUploaded::class => \App\Listeners\Document\LogDocumentEvent::class,
            \App\Events\Document\DocumentArchived::class => \App\Listeners\Document\LogDocumentEvent::class,
            \App\Events\Document\DocumentSigned::class   => \App\Listeners\Document\LogDocumentEvent::class,
        ];
        foreach ($wave6 as $eventClass => $listenerClass) {
            Event::listen($eventClass, $listenerClass);
        }

        // CMA back-propagation: when presentation_fields are written, propagate the
        // CMAInfo-extracted erf / GPS / municipal valuation back to the linked Property.
        // Failure-isolated — see PropagateCmaToProperty.
        // Spec: .ai/specs/market-intelligence-discovery.md Section 13.4.
        Event::listen(
            \App\Events\Presentation\PresentationFieldsExtracted::class,
            \App\Listeners\Presentation\PropagateCmaToProperty::class,
        );

        // Agency Public API — fan a listing's syndication change out to per-website
        // webhooks. The wildcard RecordDomainEvent also audits it automatically.
        // Spec: .ai/specs/agency-public-api.md §6.
        Event::listen(
            \App\Events\Website\ListingSyndicationChanged::class,
            \App\Listeners\Webhooks\DispatchAgencyWebhooks::class,
        );
        Event::listen(
            \App\Events\Website\AgentVisibilityChanged::class,
            \App\Listeners\Webhooks\DispatchAgentWebhooks::class,
        );
        \App\Models\User::observe(\App\Observers\UserObserver::class);

        // Contact Testimonials — publish/unpublish fans out to per-website
        // testimonial.* webhooks. Spec: .ai/specs/testimonials.md §5.
        Event::listen(
            \App\Events\Website\TestimonialVisibilityChanged::class,
            \App\Listeners\Webhooks\DispatchTestimonialWebhooks::class,
        );
        \App\Models\ContactTestimonial::observe(\App\Observers\ContactTestimonialObserver::class);

        // NOTE: ContactTestimonialSubmitted → NotifyAgentOfClientTestimonial is
        // wired by Laravel's automatic listener discovery (it scans app/Listeners
        // and registers each handle() against its type-hinted event). Do NOT add
        // an explicit Event::listen() for it here — that double-registers the
        // listener and it fires twice (two emails / two in-app rows per submit).
        // Cross-pillar Contact → Agent per non-negotiable #9. Spec: testimonials.md §13.

        // Agent Articles — publish/unpublish/edit fans out to per-website
        // article.* webhooks. Payload carries id + agent_id so the consuming
        // site can bust its per-agent article cache. Spec: agency-public-api.md §6.1.
        Event::listen(
            \App\Events\Website\ArticleVisibilityChanged::class,
            \App\Listeners\Webhooks\DispatchArticleWebhooks::class,
        );
        \App\Models\AgentArticle::observe(\App\Observers\AgentArticleObserver::class);

        // Phase 8 — auto-record outcome=won_sale when a Deal flips to registered
        // and a linked presentation has no outcome yet. Observer is failure-
        // isolated so outcome auto-capture never breaks a deal save.
        \App\Models\Deal::observe(\App\Observers\DealRegisteredForOutcomeObserver::class);

        // buyer_preferences deprecation listener (spec D11 Phase 1).
        // Logs a WARNING to the `deprecation` channel for any query that
        // touches the deprecated table. The legitimate callers post-Prompt-08
        // are the wishlist migration commands themselves (WishlistMigrate,
        // WishlistMigrateDryRun, WishlistRollbackMigration, snapshot trait) —
        // their entries serve as audit trail. Anything else is a leaked caller
        // that Prompt 06 missed, which is exactly what this listener catches.
        if (config('corex.deprecation.buyer_preferences_listener', true)) {
            DB::listen(function ($query) {
                if (stripos($query->sql, 'buyer_preferences') !== false) {
                    $allFrames = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 14))
                        ->map(fn ($frame) => ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?'));
                    $appFrames = $allFrames
                        ->filter(fn ($line) => !str_contains($line, 'vendor/')
                            && !str_contains($line, 'vendor\\'))
                        ->take(6)
                        ->values();
                    // Fallback to top vendor frames when no app-code frame
                    // exists (e.g. Tinker / artisan invocations) so the log
                    // still names the entry point.
                    $caller = $appFrames->isNotEmpty()
                        ? $appFrames->all()
                        : $allFrames->take(4)->values()->all();
                    Log::channel('deprecation')->warning('DEPRECATED: query touched buyer_preferences', [
                        'sql'      => $query->sql,
                        'bindings' => $query->bindings,
                        'time_ms'  => $query->time,
                        'caller'   => $caller,
                    ]);
                }
            });
        }

        // Auto-sync DocuPerfect named fields after every migration run
        Event::listen(MigrationsEnded::class, function () {
            Artisan::call('docuperfect:sync-fields');
        });

        // Multi-tenancy: on login / logout, drop any stale agency-switcher
        // override from the session. Without this a prior owner-session
        // `active_agency_id` leaks into the next login and the global
        // AgencyScope filters the user out of their own record.
        Event::listen(Login::class, function (Login $event) {
            session()->forget('active_agency_id');
        });
        Event::listen(Logout::class, function (Logout $event) {
            session()->forget('active_agency_id');
        });

        // @permission('permission_key') ... @endpermission
        Blade::if('permission', function (string $permissionKey) {
            return auth()->check() && auth()->user()->hasPermission($permissionKey);
        });

        // ─────────────────────────────────────────────────────────────────
        // Agency Public API (website API). The `agency-api` guard resolves a
        // per-website Bearer token to its AgencyApiKey via AgencyApiKeyResolver.
        // The resolved key becomes the request principal; AgencyScope then
        // filters every query to its agency. Per-key rate limiting keys off the
        // resolved key's rate_limit_per_min. Spec: .ai/specs/agency-public-api.md §4.
        // ─────────────────────────────────────────────────────────────────
        \Illuminate\Support\Facades\Auth::viaRequest('agency-api', function (\Illuminate\Http\Request $request) {
            return app(\App\Services\AgencyApi\AgencyApiKeyResolver::class)->resolveFromRequest($request);
        });

        \Illuminate\Support\Facades\RateLimiter::for('website-api', function (\Illuminate\Http\Request $request) {
            $key = $request->user();
            $perMinute = ($key instanceof \App\Models\AgencyApiKey) ? max(1, (int) $key->rate_limit_per_min) : 120;
            $by = ($key instanceof \App\Models\AgencyApiKey) ? ('key:' . $key->getKey()) : ('ip:' . $request->ip());

            return \Illuminate\Cache\RateLimiting\Limit::perMinute($perMinute)->by($by);
        });
    }
}
