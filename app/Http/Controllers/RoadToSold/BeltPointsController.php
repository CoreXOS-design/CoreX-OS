<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\RoadToSold\RtsSettingAuditEntry;
use App\Services\RoadToSold\BeltPoints;
use App\Services\RoadToSold\Support\Routing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Road to Sold v4 belt points (road-to-sold.md 23.16):
 *   settings()   Settings > Points: what the agency pays for each belt credit (permission road_to_sold.manage_settings)
 *   save()       one audit row per save, only the amounts that changed
 *   log()        "why did I get these points": the signed-in agent's own credits, date / property / event / points (own scope only)
 * Sales track only: the belt is the sales ladder.
 */
class BeltPointsController extends Controller
{
    public function __construct(private readonly BeltPoints $points)
    {
    }

    public function settings(Request $request): View
    {
        $viewer = $request->user();
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        abort_if($agencyId <= 0, 403, 'Switch into an agency first.');
        abort_if(Routing::track() !== 'sales', 404);

        return view('road-to-sold.settings.points', [
            'tabs' => SettingsController::tabsFor($viewer, 'points'), 'amounts' => $this->points->amounts($agencyId), 'credits' => BeltPoints::CREDITS,
            'rb' => Routing::base(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $viewer = $request->user();
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        abort_if($agencyId <= 0, 403, 'Switch into an agency first.');
        abort_if(Routing::track() !== 'sales', 404);

        // A dot in a field name means "nested" to the validator, so the form posts a slug with its dots as underscores (rts.outcome.phone -> rts_outcome_phone).
        $rules = [];
        foreach (BeltPoints::slugs() as $slug) {
            $rules['points.' . self::field($slug)] = ['sometimes', 'integer', 'min:0', 'max:10000'];
        }
        $data = $request->validate($rules, [], collect(BeltPoints::CREDITS)->mapWithKeys(fn ($c, $s) => ['points.' . self::field($s) => $c[0]])->all());
        $posted = [];
        foreach (BeltPoints::slugs() as $slug) {
            if (array_key_exists(self::field($slug), (array) ($data['points'] ?? []))) {
                $posted[$slug] = $data['points'][self::field($slug)];
            }
        }

        $old = $this->points->amounts($agencyId);
        $changedOld = $changedNew = [];
        foreach ($posted as $slug => $value) {
            if (! isset($old[$slug]) || (int) $value === $old[$slug]) {
                continue;
            }
            if ($this->points->setAmount($agencyId, (string) $slug, (int) $value)) {
                $changedOld['points.' . $slug] = $old[$slug];
                $changedNew['points.' . $slug] = (int) $value;
            }
        }
        if ($changedNew !== []) {
            RtsSettingAuditEntry::create([
                'agency_id' => $agencyId, 'track' => 'sales', 'changed_by_user_id' => (int) $viewer->id,
                'old_values' => $changedOld, 'new_values' => $changedNew, 'changed_at' => now(),
            ]);
        }

        return redirect()->route(Routing::base() . 'points.index')->with('success', $changedNew === [] ? 'Nothing changed.' : 'Points saved. New credits use these amounts; what is already earned stays as it was.');
    }

    public static function field(string $slug): string
    {
        return str_replace('.', '_', $slug);
    }

    public function log(Request $request): View
    {
        $agent = $request->user();
        abort_if((int) ($agent->effectiveAgencyId() ?: 0) <= 0, 403, 'Switch into an agency first.');
        $filters = [
            'from' => $this->date($request->query('from')), 'to' => $this->date($request->query('to')),
            'event' => in_array($request->query('event'), BeltPoints::slugs(), true) ? $request->query('event') : null,
            'q' => trim((string) $request->query('q', '')) ?: null,
        ];

        return view('road-to-sold.points', [
            'rows' => $this->points->log($agent, $filters), 'totals' => $this->points->totals($agent), 'filters' => $filters, 'credits' => BeltPoints::CREDITS,
        ]);
    }

    private function date(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
