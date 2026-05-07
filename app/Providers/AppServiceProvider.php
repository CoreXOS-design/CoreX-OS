<?php

namespace App\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use App\Models\Agency;
use App\Models\CommandCenter\CalendarEventFeedback;
use App\Models\CommandCenter\CommandTask;
use App\Models\Contact;
use App\Models\ContactAccessLog;
use App\Models\ContactConsentRecord;
use App\Models\Deal;
use App\Models\DealSettlement;
use App\Models\Presentation;
use App\Models\Property;
use App\Observers\AgencyObserver;
use App\Observers\CalendarEventFeedbackObserver;
use App\Observers\CommandTaskObserver;
use App\Observers\ContactAccessLogObserver;
use App\Observers\ContactConsentRecordObserver;
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
    }

    public function boot(): void
    {
        Agency::observe(AgencyObserver::class);
        CalendarEventFeedback::observe(CalendarEventFeedbackObserver::class);
        Contact::observe(ContactObserver::class);
        ContactAccessLog::observe(ContactAccessLogObserver::class);
        ContactConsentRecord::observe(ContactConsentRecordObserver::class);
        Deal::observe(DealObserver::class);
        Presentation::observe(PresentationObserver::class);
        DealSettlement::observe(DealSettlementObserver::class);
        Property::observe(PropertyObserver::class);
        CommandTask::observe(CommandTaskObserver::class);
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
