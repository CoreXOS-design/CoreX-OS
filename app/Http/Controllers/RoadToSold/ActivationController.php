<?php

declare(strict_types=1);

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RoadToSold\ActivationService;
use App\Services\RoadToSold\CallListService;
use App\Services\RoadToSold\Track;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Road to Sold v4 - "Start my Road to Sold", pause and resume (.ai/specs/road-to-sold.md 23.13).
 *
 * An agent starts and pauses their OWN Road to Sold with access_road_to_sold alone. Starting, pausing or resuming someone else needs
 * road_to_sold.pause_others AND the agent inside the viewer's breadth (the same viewableAgents the page uses; anyone else is a 404).
 */
class ActivationController extends Controller
{
    public function __construct(private readonly ActivationService $activation, private readonly CallListService $list)
    {
    }

    public function start(Request $request): RedirectResponse
    {
        [$track, $agent, $own] = $this->resolve($request);
        $this->activation->start($request->user(), $agent, $track);

        return $this->back($track, $agent, $own, $own ? 'Road to Sold started. Your first list loads on the next working day.' : $agent->name . ' has been started. Their first list loads on the next working day.');
    }

    public function pause(Request $request): RedirectResponse
    {
        [$track, $agent, $own] = $this->resolve($request);
        $data = $request->validate([
            'until'  => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:120'],
        ], [
            'until.required' => 'Pick the date the pause runs until.',
            'until.date' => 'Pick a real date for the pause.',
            'until.after_or_equal' => 'The pause cannot end in the past. Pick today or a later date.',
            'reason.max' => 'Keep the reason under 120 characters.',
        ]);
        $this->activation->pause($request->user(), $agent, \Illuminate\Support\Carbon::parse($data['until']), $data['reason'] ?? null, $track);

        return $this->back($track, $agent, $own, ($own ? 'You are' : $agent->name . ' is') . ' paused until ' . \Illuminate\Support\Carbon::parse($data['until'])->format('j M Y') . '. No lists load and the days do not count against targets.');
    }

    public function resume(Request $request): RedirectResponse
    {
        [$track, $agent, $own] = $this->resolve($request);
        $this->activation->resume($request->user(), $agent, $track);

        return $this->back($track, $agent, $own, ($own ? 'Welcome back.' : $agent->name . ' is back.') . ' Lists load again from the next working day.');
    }

    /** @return array{0:string,1:User,2:bool} track, the agent acted on, is it the viewer's own */
    private function resolve(Request $request): array
    {
        $viewer = $request->user();
        abort_if((int) ($viewer->effectiveAgencyId() ?: 0) <= 0, 403, 'Switch into an agency first.');
        abort_unless(ActivationService::tableReady(), 503, 'Start and pause are not switched on yet.');
        $track = Track::fromRequest($request);
        $agentId = (int) ($request->input('agent') ?: $viewer->ownershipUserId());
        $own = $agentId === (int) $viewer->ownershipUserId();
        if (! $own) {
            abort_unless($viewer->hasPermission('road_to_sold.pause_others'), 403, 'You may not start or pause another agent\'s Road to Sold.');
            abort_unless($this->list->viewableAgents($viewer)->contains('id', $agentId), 404);
        }
        $agent = User::query()->withoutGlobalScopes()->where('agency_id', (int) $viewer->effectiveAgencyId())->findOrFail($agentId);

        return [$track, $agent, $own];
    }

    private function back(string $track, User $agent, bool $own, string $message): RedirectResponse
    {
        return redirect()->route('road-to-sold.index', ($own ? [] : ['agent' => $agent->id]) + Track::params($track))->with('status', $message);
    }
}
