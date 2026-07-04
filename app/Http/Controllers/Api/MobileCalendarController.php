<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile-only calendar feed scoped strictly to the authenticated user.
 *
 * Mirrors the web cockpit's visibility filters so the mobile calendar
 * shows exactly what the web shows for the same agent — no team-wide
 * events, no auto-system events the web hides via class settings.
 *
 *   GET /api/v1/mobile/calendar?year=YYYY&month=MM
 *   GET /api/v1/mobile/calendar?date=YYYY-MM-DD          (single day)
 *
 * Always returns ONLY events where calendar_events.user_id = auth user.
 * Voice-created events are automatically caught by this filter because
 * ScheduleEventIntentHandler assigns user_id from the sanctum token.
 */
class MobileCalendarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        // Range — either a single day, or a whole month
        if ($date = $request->get('date')) {
            try {
                $start = Carbon::parse($date)->startOfDay();
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Invalid date.'], 422);
            }
            $end = (clone $start)->endOfDay();
        } else {
            $year  = (int) $request->get('year', now()->year);
            $month = (int) $request->get('month', now()->month);
            try {
                $start = Carbon::create($year, $month, 1)->startOfMonth();
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Invalid year/month.'], 422);
            }
            $end = (clone $start)->endOfMonth();
        }

        $visibleClassKeys = $this->visibleClassKeysFor($user);

        $events = CalendarEvent::query()
            ->where('user_id', $user->id)                       // owner of the event
            ->where('event_date', '>=', $start)
            ->where('event_date', '<=', $end)
            ->when(!empty($visibleClassKeys), fn ($q) => $q->whereIn('category', $visibleClassKeys))
            ->whereNotNull('event_type')
            ->orderBy('event_date')
            ->get();

        // FROZEN CONTRACT (AT-164 §15.8): every key below is unchanged — the redesign
        // NEVER mutates an existing mobile field. The AT-164 surfaces (Deck + layer
        // filter sheet) are ADDED only when the client explicitly opts in via
        // ?include=deck,layers, so existing callers see byte-identical responses.
        $payload = [
            'user_id'      => $user->id,
            'agency_id'    => $user->agency_id,
            'range_start'  => $start->toIso8601String(),
            'range_end'    => $end->toIso8601String(),
            'total'        => $events->count(),
            'events'       => $events->map(fn (CalendarEvent $e) => [
                'id'            => $e->id,
                'title'         => $e->title,
                'description'   => $e->description,
                'event_type'    => $e->event_type,
                'category'      => $e->category,
                'event_date'    => optional($e->event_date)->toIso8601String(),
                'end_date'      => optional($e->end_date)->toIso8601String(),
                'all_day'       => (bool) $e->all_day,
                'priority'      => $e->priority,
                'status'        => $e->status,
                'colour'        => $e->colour,
                'contact_id'    => $e->contact_id,
                'property_id'   => $e->property_id,
                'created_by_ai' => (bool) $e->created_by_ai,
                'ai_source'     => $e->ai_source,
            ]),
        ];

        // AT-164 Gate 8 — optional aggregated surfaces (additive, opt-in only).
        $include = array_filter(array_map('trim', explode(',', (string) $request->get('include', ''))));
        if (in_array('deck', $include, true)) {
            // Same Deck the web renders (swipeable cards / bottom sheet on mobile) —
            // honours the My Deals gate + layer toggles + degrade-not-500 per tile.
            $payload['deck'] = app(\App\Services\CommandCenter\CalendarTileService::class)->buildDeck($user);
        }
        if (in_array('layers', $include, true)) {
            // Layer filter sheet: the catalogue + the user's active set.
            $payload['layers'] = [
                'catalog' => \App\Services\CommandCenter\Calendar\CalendarLayers::LAYERS,
                'active'  => \App\Services\CommandCenter\Calendar\CalendarLayers::resolveActive($user),
            ];
        }

        return response()->json($payload);
    }

    /**
     * Replicates the web cockpit's visible-class filter so mobile +
     * web stay in sync. Bypass roles (admin/super_admin/owner) see all.
     */
    private function visibleClassKeysFor($user): array
    {
        $userRole = $user->role ?? 'agent';
        $isBypass = in_array($userRole, ['super_admin', 'admin', 'owner'], true);

        $allClasses = CalendarEventClassSetting::withoutGlobalScopes()
            ->where('is_active', true)
            ->get()
            ->unique('event_class');

        if ($isBypass) {
            return $allClasses->pluck('event_class')->all();
        }

        return $allClasses->filter(function ($cls) use ($userRole) {
            $roles = array_merge(
                $cls->green_visibility ?? [],
                $cls->amber_visibility ?? [],
                $cls->red_visibility   ?? []
            );
            if (in_array('all', $roles, true)) return true;
            if (in_array($userRole, $roles, true)) return true;
            if ($userRole === 'branch_manager' && in_array('bm', $roles, true)) return true;
            return false;
        })->pluck('event_class')->all();
    }
}
