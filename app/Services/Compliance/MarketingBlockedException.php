<?php

namespace App\Services\Compliance;

class MarketingBlockedException extends \Exception
{
    public function __construct(
        private ReadinessReport $report,
        string $message = 'Property does not meet marketing readiness requirements.',
    ) {
        parent::__construct($message);
    }

    public function getReport(): ReadinessReport
    {
        return $this->report;
    }
}
