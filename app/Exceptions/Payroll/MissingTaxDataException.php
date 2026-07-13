<?php

declare(strict_types=1);

namespace App\Exceptions\Payroll;

use RuntimeException;

/**
 * AT-237 C2 — thrown when payroll is calculated for a period that has NO SARS
 * tax brackets or rebate loaded. Replaces the old silent "PAYE = R0" degradation
 * (a compliance landmine that finalised whole runs with everyone under-deducted
 * to zero). A missing statutory table is a HARD STOP, not a zeroed payslip.
 */
final class MissingTaxDataException extends RuntimeException
{
    public function __construct(public readonly string $period, public readonly string $what)
    {
        parent::__construct(sprintf(
            'No SARS %s loaded for the tax year covering %s — PAYE cannot be calculated. '
            . 'Load the tax tables for that year before running payroll.',
            $what,
            $period,
        ));
    }
}
