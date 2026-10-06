<?php

namespace App\Services\PlatformEsign\Agreement;

/** The browser saved against an older revision — another tab/window saved first (spec §11.7). */
class AgreementConflict extends \DomainException
{
    public function __construct(public int $rev, public array $values)
    {
        parent::__construct('This agreement was updated in another window. Your screen has been refreshed with the latest entries.');
    }
}
