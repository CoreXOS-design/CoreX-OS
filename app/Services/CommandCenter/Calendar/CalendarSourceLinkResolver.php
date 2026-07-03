<?php

namespace App\Services\CommandCenter\Calendar;

use App\Models\CommandCenter\CalendarEvent;

/**
 * AT-164 — single source of truth for "deep link to the record behind a calendar
 * deadline". Both the aggregate-chip popover (Gate 2, CalendarController) and the
 * Deck's Notifications/Deadlines tile (Gate 4, CalendarTileService) resolve links
 * through here so the route map never diverges.
 *
 * DEEP-LINK GAP (recorded, Gate 2): only the 6 model types below have a guessable
 * show route today. Sources like ListingStock / Rental / SignatureRequest /
 * RmcpAck / UserDocument carry source_type + source_id but have no show route, so
 * they resolve to null and the surface falls back to the in-page event panel.
 * Extend the map per model as those routes land.
 */
class CalendarSourceLinkResolver
{
    /**
     * @return array{url:string,label:string}|null
     */
    public static function resolve(CalendarEvent $event): ?array
    {
        if (! $event->source_type || ! $event->source_id) {
            return null;
        }
        if (str_starts_with($event->source_type, 'synthetic:')) {
            return null;
        }

        $routeMap = [
            \App\Models\Property::class => ['route' => 'corex.properties.show', 'label' => 'View property'],
            \App\Models\FicaSubmission::class => ['route' => 'compliance.fica.show', 'label' => 'View FICA submission'],
            \App\Models\Compliance\RmcpVersion::class => ['route' => 'compliance.rmcp.show', 'label' => 'View RMCP version'],
            \App\Models\Compliance\EmployeeScreening::class => ['route' => 'compliance.screenings.show', 'label' => 'View screening'],
            \App\Models\Payroll\PayrollRun::class => ['route' => 'payroll.runs.show', 'label' => 'View payroll run'],
            \App\Models\Payroll\PayrollEmployee::class => ['route' => 'payroll.employees.show', 'label' => 'View employee'],
        ];

        $entry = $routeMap[$event->source_type] ?? null;
        if (! $entry) {
            return null;
        }

        try {
            return ['url' => route($entry['route'], $event->source_id), 'label' => $entry['label']];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
