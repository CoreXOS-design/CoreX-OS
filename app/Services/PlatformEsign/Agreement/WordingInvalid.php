<?php

namespace App\Services\PlatformEsign\Agreement;

/** The wording/rates an owner tried to save or publish break a rule — carries every message so the screen can list them. */
class WordingInvalid extends \DomainException
{
    /** @param string[] $errors */
    public function __construct(public array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
