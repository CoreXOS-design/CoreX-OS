<?php

namespace App\Services\Rentals;

/** Audit M8 - thrown inside a scan read that has used up its share of the PHP time limit; carries the plain-words reason shown on the scan. */
class ScanTimeBudgetExceeded extends \RuntimeException
{
}
