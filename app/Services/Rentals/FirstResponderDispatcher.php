<?php

namespace App\Services\Rentals;

use App\Models\RentalFaultReport;
use App\Models\User;

/**
 * .ai/specs/rentals-faults-work-orders.md section 21.3 - the ONE seam behind the fault page's two "First to look at this" buttons. The fault flow (cc6) replaces the
 * implementation bound in AppServiceProvider with the real send; the button, the route and the history line do not change.
 */
interface FirstResponderDispatcher
{
    /** The agent's two choices in the "First to look at this" block (Johan, 12 Oct 2026) - always these two, even when a default is set. */
    public const CHOICE_INSPECT = 'inspect';
    public const CHOICE_OWNER_AUTHORISE = 'owner_authorise';

    /**
     * @param  array<string, mixed>  $responder  one item of FirstResponderService::forProperty()
     * @param  string  $choice  self::CHOICE_INSPECT | self::CHOICE_OWNER_AUTHORISE
     * @return array{sent: bool, message: string}
     */
    public function send(RentalFaultReport $fault, array $responder, User $by, string $choice): array;
}
