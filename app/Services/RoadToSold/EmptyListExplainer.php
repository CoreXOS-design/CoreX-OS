<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsSetting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Route;

/**
 * Road to Sold - why an agent's list for TODAY is empty, and the one thing to do about it (spec section 10.5, "Empty states").
 * Read-only: it never builds anything. The page asks it only when the day has no rows at all.
 *
 * The reasons, checked in this order (the first that is true wins):
 *   build_failed   the build was tried and threw - say so, offer to try again
 *   not_workday    no target today (weekend, public holiday, approved leave, or all weekdays at 0)
 *   no_sources     every list source is switched off for the agency
 *   no_areas       the agency locks agents to their areas and this agent has none
 *   not_built      nobody has built it yet (someone else's page - the page only builds the viewer's OWN list on open)
 *   no_stock       the build ran and nothing matched the rules
 *
 * The answer carries a plain sentence and at most one action: a link (to the screen that fixes it, only when the viewer may open it)
 * or a build button. Wording is neutral - no agency is named, the viewer is "you" and anyone else is named.
 */
final class EmptyListExplainer
{
    /**
     * @return array{code:string,line:string,action:?array{kind:string,label:string,url?:string,agent?:int}}|null null = not a case to explain (a day other than today)
     */
    public function explain(User $viewer, User $agent, CarbonInterface $date, string $track, bool $viewingOwn, bool $buildTried, bool $buildFailed = false): ?array
    {
        if (! $date->isToday()) {
            return null;
        }

        $you = $viewingOwn ? 'you' : $agent->name;
        $your = $viewingOwn ? 'your' : $agent->name . "'s";
        $first = trim(explode(' ', (string) $agent->name)[0]);

        if ($buildFailed) {
            return $this->answer('build_failed', ucfirst($your) . ' list could not be built just now.', $this->build($agent, 'Try again'));
        }

        if (! WorkdayService::make()->isWorkday($agent, $date, $track)) {
            return $this->answer('not_workday', 'Today is not a working day for ' . $you . ': weekend, public holiday or leave.',
                $viewer->hasPermission('road_to_sold.manage_targets') ? $this->link('Change working days', $this->settingsUrl('targets.index', $track)) : null);
        }

        $settings = SettingsService::forUser($agent, $track);

        if ($settings->enabledSelectionKeys() === []) {
            return $this->answer('no_sources', 'Every list source is switched off for your agency.',
                $viewer->hasPermission('road_to_sold.manage_settings') ? $this->link('Switch sources on', $this->settingsUrl('index', $track)) : null);
        }

        $hasAreas = app(AreaService::class)->hasAreas($agent, $track);

        if ($settings->lockAgentsToAreas() && ! $hasAreas) {
            $line = 'Your agency gives each agent a list from their own areas, and ' . ($viewingOwn ? 'you have none' : $agent->name . ' has none') . ' set.';
            if ($viewer->hasPermission('road_to_sold.manage_areas')) {
                return $this->answer('no_areas', $line, $this->link($viewingOwn ? 'Set your areas' : 'Set ' . $first . "'s areas", $this->settingsUrl('areas.index', $track)));
            }

            return $this->answer('no_areas', $line . ' Ask your manager to set them.', null);
        }

        if (! $buildTried) {
            return $this->answer('not_built', ucfirst($your) . ' list for today has not been built yet.', $this->build($agent, $viewingOwn ? 'Build my list' : 'Build ' . $first . "'s list"));
        }

        $where = $hasAreas ? $your . ' areas' : "your agency's market data";

        return $this->answer('no_stock', 'Nothing in ' . $where . ' matches the list rules today.',
            $hasAreas && $viewer->hasPermission('road_to_sold.manage_areas') ? $this->link('Review areas', $this->settingsUrl('areas.index', $track)) : null);
    }

    private function answer(string $code, string $line, ?array $action): array
    {
        return ['code' => $code, 'line' => $line, 'action' => $action];
    }

    private function link(string $label, ?string $url): ?array
    {
        return $url ? ['kind' => 'link', 'label' => $label, 'url' => $url] : null;
    }

    private function build(User $agent, string $label): array
    {
        return ['kind' => 'build', 'label' => $label, 'agent' => (int) $agent->id];
    }

    /** The settings screen for the track (sales mounts them as road-to-sold.settings.*, rentals as road-to-rented.settings.*); null when not mounted. */
    private function settingsUrl(string $short, string $track): ?string
    {
        $root = $track === RtsSetting::TRACK_RENTALS ? 'road-to-rented' : 'road-to-sold';
        $name = $root . '.settings.' . $short;

        return Route::has($name) ? route($name) : null;
    }
}
