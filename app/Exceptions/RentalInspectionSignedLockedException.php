<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * .ai/specs/rental-inspections.md §47 — thrown when the CONTENT of a report that someone has already signed is changed.
 * A signed report is locked; the only way to change it is the explicit "Edit report" action, which clears every
 * signature. Extends RentalInspectionNotRecordableException so every existing handler (409 + the message) treats it the
 * same way.
 */
class RentalInspectionSignedLockedException extends RentalInspectionNotRecordableException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'This report has been signed, so it is locked. To change it, press "Edit report" — that clears ALL signatures and everyone must sign again.');
    }
}
