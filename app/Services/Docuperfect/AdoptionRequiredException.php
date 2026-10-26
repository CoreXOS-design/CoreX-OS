<?php

namespace App\Services\Docuperfect;

use DomainException;

/**
 * esign-signing-modes.md §1 (Johan, 26 Oct 2026): a click on a place, or "Apply to all my places", with no adoption on
 * record for this signer (or an adoption missing either mark) is refused — the signing page answers by opening the adopt
 * popup, then applies what the signer asked for. Never a silent failure and never a half-adopted stamp.
 */
class AdoptionRequiredException extends DomainException
{
}
