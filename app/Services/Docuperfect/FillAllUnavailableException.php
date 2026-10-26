<?php

namespace App\Services\Docuperfect;

use DomainException;

/**
 * esign-signing-modes.md §10 (Mode 3 risk): the server cannot vouch for the list of places a signer asked to have
 * filled in one go (the document does not carry reliable ownership stamps for them, or the list is longer than the
 * document's own). The signer is told to click each place instead — never filled on a guess.
 */
class FillAllUnavailableException extends DomainException
{
}
