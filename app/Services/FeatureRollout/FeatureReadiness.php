<?php

namespace App\Services\FeatureRollout;

use App\Models\Agency;
use Illuminate\Support\Facades\Route;

/**
 * Is an agency ready for a feature? (AT-478 §12.1, acceptance #14.) The requirements live in
 * config/feature-rollout.php `readiness`; a feature with none listed is always ready.
 */
class FeatureReadiness
{
    /**
     * What is still missing for this feature, each with where to fill it in.
     *
     * @return list<array{label:string, step:string, step_title:string, url:?string}>
     */
    public function missing(Agency $agency, string $feature): array
    {
        $out = [];
        foreach ((array) config('feature-rollout.readiness.' . $feature, []) as $req) {
            if (trim((string) ($agency->getAttribute($req['field']) ?? '')) !== '') {
                continue;
            }
            $step = (string) $req['step'];
            $out[] = [
                'label'      => (string) $req['label'],
                'step'       => $step,
                'step_title' => (string) (config("agency-onboarding-copy.$step.title") ?: $step),
                'url'        => Route::has('corex.agency-setup.step') ? route('corex.agency-setup.step', ['step' => $step], false) : null,
            ];
        }

        return $out;
    }

    public function ready(Agency $agency, string $feature): bool
    {
        return $this->missing($agency, $feature) === [];
    }
}
