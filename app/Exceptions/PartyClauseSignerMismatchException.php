<?php

namespace App\Exceptions;

use Exception;

/**
 * The signer being bound to a SignatureRequest is not, by identity, a
 * current representative of the party they're supposed to be signing for.
 *
 * Flow 409's real shape: signer Chris, but Anna's real representative on
 * record was Ben — refused. cc4's real reproduction (row 1506) same night:
 * signer "Chris" (contact #17220), clause named "Christopher TestBentley"
 * (a different, real contact) — the FIRST version of this guard compared
 * name TEXT and let it through, because "Chris" is a literal substring of
 * "Christopher". That is not a representation match, it's a coincidence of
 * spelling. This guard compares Contact ids against the live
 * contact_representatives relationship — never name strings — so a name
 * that merely LOOKS related can never satisfy it; only the actual same
 * record can.
 */
class PartyClauseSignerMismatchException extends Exception
{
    public static function forParty(string $signerName, string $partyName): self
    {
        return new self(
            "\"{$signerName}\" is not currently linked as a representative of "
            . "\"{$partyName}\" — refusing to send. Re-link the correct representative "
            . '(via the recipient screen) before sending; the clause and the signer '
            . 'must come from the same record, not just a similar-looking name.',
            0,
            null,
        );
    }
}
