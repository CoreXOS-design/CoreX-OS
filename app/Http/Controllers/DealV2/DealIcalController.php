<?php

namespace App\Http\Controllers\DealV2;

use App\Http\Controllers\Controller;
use App\Models\CommandCenter\CalendarUserPreference;
use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\DealV2;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * AT-158 DR2 WS8 (§12) — the per-user iCal deal feed.
 *
 * A tokenised, read-only .ics endpoint a calendar app can subscribe to (raw URL
 * polling — NO auth session). The token (per-user, on calendar_user_preferences)
 * is unguessable and regenerable/revocable. The feed serves the token-user's own
 * deal-step deadlines, respecting their permitted data scope via the SAME
 * clampScope discipline as the overview (default 'own'; `?scope=` can widen only
 * up to what the user is actually allowed to see). Cross-tenant-safe: the query
 * is pinned to the token-user's agency (no auth context to drive AgencyScope).
 */
class DealIcalController extends Controller
{
    /** PUBLIC — the subscribable feed. Bad/absent token → 404, never a leak. */
    public function feed(Request $request, string $token)
    {
        $pref = CalendarUserPreference::query()
            ->whereNotNull('ical_token')
            ->where('ical_token', $token)
            ->first();
        abort_if(! $pref, 404);

        $user = User::withoutGlobalScopes()->find($pref->user_id);
        abort_if(! $user || ! $user->is_active, 404);

        $scope = DealV2::clampScope(
            $request->query('scope', 'own'),
            PermissionService::getDataScope($user, 'deals_v2')
        );

        // Pin to the user's agency (public request → no AgencyScope tenant); apply
        // the deal visibility scope, then pull that user's open dated steps.
        $dealIds = DealV2::withoutGlobalScopes()
            ->where('agency_id', $user->agency_id)
            ->visibleTo($user, $scope)
            ->pluck('id');

        $steps = DealStepInstance::withoutGlobalScopes()
            ->whereIn('deal_id', $dealIds)
            ->whereNotNull('due_date')
            ->whereIn('status', ['active', 'overdue'])
            ->with(['deal.property'])
            ->orderBy('due_date')
            ->get();

        $ics = $this->buildCalendar($steps, $token);

        return response($ics, 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="corex-deals.ics"',
            'Cache-Control'       => 'no-cache, private',
        ]);
    }

    /** AUTHED — rotate the token (revokes the old URL) and return to the overview. */
    public function regenerate(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('access_deal_register_v2'), 403);
        $pref = CalendarUserPreference::firstOrNew(['user_id' => auth()->id()]);
        $pref->ical_token = self::freshToken();
        $pref->save();

        return back()->with('status', 'Calendar feed link regenerated. Any previously shared link no longer works.');
    }

    /** AUTHED — disable the feed entirely (token cleared → feed 404s). */
    public function disable(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('access_deal_register_v2'), 403);
        $pref = CalendarUserPreference::firstOrNew(['user_id' => auth()->id()]);
        $pref->ical_token = null;
        $pref->save();

        return back()->with('status', 'Calendar feed disabled.');
    }

    public static function freshToken(): string
    {
        return Str::lower(Str::random(48));
    }

    // ── ICS building (hand-rolled; no library) ─────────────────────────────

    private function buildCalendar($steps, string $token): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//CoreX OS//Deal Register//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:CoreX Deal Deadlines',
        ];

        foreach ($steps as $step) {
            $deal = $step->deal;
            $ref  = $deal?->reference ?: ('Deal #' . ($deal?->id ?? '?'));
            $addr = $deal?->property?->address;
            $date = $step->due_date; // Carbon (date cast)

            $summary = $this->escape(trim("{$ref} — {$step->name}"));
            $descParts = array_filter([
                $addr ? "Property: {$addr}" : null,
                'Status: ' . ($step->status === 'overdue' ? 'OVERDUE' : ucfirst($step->status)),
                $step->current_rag ? 'RAG: ' . ucfirst($step->current_rag) : null,
            ]);

            // Stable UID (survives token rotation — keyed on the step, not the token).
            $uid = 'deal-step-' . $step->id . '@corexos';

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $uid;
            $lines[] = 'DTSTAMP:' . now()->utc()->format('Ymd\THis\Z');
            // All-day event on the due date (DATE value, exclusive end next day).
            $lines[] = 'DTSTART;VALUE=DATE:' . $date->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:' . $date->copy()->addDay()->format('Ymd');
            $lines[] = 'SUMMARY:' . $summary;
            $lines[] = 'DESCRIPTION:' . $this->escape(implode("\n", $descParts));
            if ($step->status === 'overdue') {
                $lines[] = 'CATEGORIES:OVERDUE';
            }
            $lines[] = 'TRANSP:TRANSPARENT';
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        // CRLF line endings + 75-octet folding per RFC 5545.
        return implode("\r\n", array_map([$this, 'fold'], $lines)) . "\r\n";
    }

    /** Escape TEXT values per RFC 5545 (backslash, semicolon, comma, newline). */
    private function escape(string $v): string
    {
        $v = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $v);
        return str_replace(["\r\n", "\n", "\r"], '\\n', $v);
    }

    /** Fold a content line to <=75 octets with CRLF + single leading space. */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        while (strlen($line) > 75) {
            $out .= substr($line, 0, 74) . "\r\n ";
            $line = substr($line, 74);
        }
        return $out . $line;
    }
}
