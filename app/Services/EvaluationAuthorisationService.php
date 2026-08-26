<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EvaluationCertificate;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Discoverability for the candidate-authorisation flow: which pending evaluation
 * certificates a given practitioner is eligible to authorise. Powers the /tools/cma
 * queue, the sidebar count badge, and the submit-time notification — one source of
 * truth so the badge and the list can never disagree.
 */
class EvaluationAuthorisationService
{
    public function __construct(private CandidatePractitionerService $practitioners) {}

    /**
     * The pending certificates this user may authorise (candidate flow): pending in
     * their agency AND they are an eligible authoriser of the candidate creator.
     */
    public function pendingFor(User $user): Collection
    {
        if ($this->practitioners->isCandidate($user) || ! $this->practitioners->canAuthorise($user)) {
            return collect();
        }

        $agencyId = (int) ($user->effectiveAgencyId() ?? 0);

        return EvaluationCertificate::where('agency_id', $agencyId)
            ->where('status', EvaluationCertificate::STATUS_PENDING_AUTHORISATION)
            ->latest()->limit(100)->get()
            ->filter(function (EvaluationCertificate $c) use ($user) {
                $creator = User::withoutGlobalScopes()->find($c->signed_by_user_id ?: $c->created_by_user_id);
                return $creator && $this->practitioners->canAuthoriseFor($user, $creator);
            })
            ->values();
    }

    /** Count for the sidebar badge — briefly cached (per user). */
    public function pendingCountFor(User $user): int
    {
        if (! $this->practitioners->canAuthorise($user)) {
            return 0;
        }

        return (int) Cache::remember($this->cacheKey($user), 30, fn () => $this->pendingFor($user)->count());
    }

    public function forget(User $user): void
    {
        Cache::forget($this->cacheKey($user));
    }

    private function cacheKey(User $user): string
    {
        return 'evalcert.pending_auth_count.' . (int) $user->id;
    }
}
