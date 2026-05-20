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
use App\Models\CommandCenter\CalendarEventFeedback;
use App\Models\CommandCenter\CommandTask;
use App\Models\Contact;
use App\Models\ContactAccessLog;
use App\Models\ContactConsentRecord;
use App\Models\ContactMatch;
use App\Models\Deal;
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
    }

    public function boot(): void
    {
        Agency::observe(AgencyObserver::class);
        CalendarEventFeedback::observe(CalendarEventFeedbackObserver::class);
        Contact::observe(ContactObserver::class);
        ContactAccessLog::observe(ContactAccessLogObserver::class);
        ContactConsentRecord::observe(ContactConsentRecordObserver::class);
        ContactMatch::observe(ContactMatchObserver::class);
        Deal::observe(DealObserver::class);
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
        \App\Models\ProspectingListing::observe(\App\Observers\ProspectingListingObserver::class);
        \App\Models\DealV2\DealV2::observe(\App\Observers\DealV2Observer::class);
        \App\Models\DealV2\DealStepInstance::observe(\App\Observers\DealStepInstanceObserver::class);

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

        // CMA back-propagation: when presentation_fields are written, propagate the
        // CMAInfo-extracted erf / GPS / municipal valuation back to the linked Property.
        // Failure-isolated — see PropagateCmaToProperty.
        // Spec: .ai/specs/market-intelligence-discovery.md Section 13.4.
        Event::listen(
            \App\Events\Presentation\PresentationFieldsExtracted::class,
            \App\Listeners\Presentation\PropagateCmaToProperty::class,
        );

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
    }
}
