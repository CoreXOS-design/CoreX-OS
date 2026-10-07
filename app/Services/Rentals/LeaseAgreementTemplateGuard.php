<?php

namespace App\Services\Rentals;

use App\Models\Docuperfect\Template;
use App\Models\RentalLeaseTemplate;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/leases.md §15.12.4 (Build L1 — shell; Build L0 fills it). The one class that decides
 * whether a template may be used by an agency for the lease process, so a built-in lease
 * (no owning agency) and every other agency's template can never reach another agency.
 *
 * The shell is FAIL-CLOSED: until L0 writes the real checks it refuses every template for every agency
 * and links none, so nothing can slip through while the foundation is in place.
 */
class LeaseAgreementTemplateGuard
{
    public const NOT_AVAILABLE = 'The lease agreement check is not available yet.';

    /**
     * Every reason $template cannot be used by $agencyId for the lease process; [] = usable.
     *
     * @return array<int, string>
     */
    public function problemsFor(Template $template, int $agencyId): array
    {
        return [self::NOT_AVAILABLE];
    }

    /**
     * @throws ValidationException when problemsFor() is not empty
     */
    public function assertUsable(Template $template, int $agencyId): void
    {
        $problems = $this->problemsFor($template, $agencyId);
        if ($problems !== []) {
            throw ValidationException::withMessages(['template' => $problems]);
        }
    }

    /**
     * The agency's linked, usable residential lease agreement (the default when it has several), or null
     * when it has none. Null is the real starting state for every agency until it sets one up (R3).
     */
    public function linkedFor(int $agencyId, string $category = RentalLeaseTemplate::CATEGORY_RESIDENTIAL): ?RentalLeaseTemplate
    {
        return null;
    }
}
