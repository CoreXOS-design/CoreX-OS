<?php

namespace App\Services\TrainingVideos\Auto;

use App\Models\Agency;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;

/**
 * AT-526 - the ONE answer to "may this person take the narrated guided tour of this screen?". Used by the "Guide me" button (which draws only when the answer is yes)
 * and by the tour API (a 404 when it is no). All of these must hold: the agency has Narrated guided tours switched on (default on), the person holds
 * training_tours.use (Role Manager), the screen has a script marked `narrated` (that mark is the tour's sign-off; the video's own `approved` is separate), and the script's audience (if it names roles) includes them.
 */
class NarratedTourGate
{
    public function __construct(private readonly TrainingScriptRepository $scripts, private readonly AgencyFeatureService $features)
    {
    }

    public function script(?User $user, string $screenKey): ?TrainingScript
    {
        $s = $this->scripts->find($screenKey);

        return $s && $this->allows($user, $s) ? $s : null;
    }

    /** The narrated script of the screen being shown (route name first, then path). */
    public function forRequest(?User $user, ?string $routeName, ?string $path): ?TrainingScript
    {
        $s = $this->scripts->forRoute($routeName) ?? $this->scripts->forPath($path);

        return $s && $this->allows($user, $s) ? $s : null;
    }

    public function allows(?User $user, TrainingScript $s): bool
    {
        if (! $user || ! $s->narrated()) {
            return false;
        }
        if (! $user->hasPermission('training_tours.use')) {
            return false;
        }
        $agency = $user->agency_id ? Agency::withoutGlobalScopes()->find($user->agency_id) : null;
        if (! $this->features->enabled('narrated-tours', $agency) || ! $this->features->enabled('guided-tours', $agency)) {
            return false;
        }

        return $s->audience() === [] || in_array((string) $user->role, $s->audience(), true) || $user->isOwnerRole();
    }
}
