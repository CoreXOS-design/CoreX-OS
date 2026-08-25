<?php

namespace App\Exceptions;

use Exception;

/**
 * The document body clause and the actual signer being bound to a
 * SignatureRequest disagree about who represents this party — Flow 409's
 * real shape: the clause named "Ben", the party was actually going to be
 * signed and emailed by "Chris", and nothing stopped that from freezing.
 *
 * "Who represents this party" has ONE stored answer (see
 * RoleBlockExpansionService::resolvePartyRepresentation()); a clause and a
 * signer that disagree means two different things fed the document instead
 * of the one call this exception guards. Refusing loudly here — instead of
 * silently freezing a document naming one person and emailing another — is
 * the door this class closes.
 */
class PartyClauseSignerMismatchException extends Exception
{
    public static function forParty(string $signerName, string $partyClauseText): self
    {
        return new self(
            "The document clause (\"{$partyClauseText}\") does not name the signer "
            . "(\"{$signerName}\") — refusing to send. Re-resolve who represents this "
            . 'party (via the recipient template screen) before sending; the clause '
            . 'and the signer must come from the same answer.',
            0,
            null,
        );
    }
}
