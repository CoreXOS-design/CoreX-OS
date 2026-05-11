<?php

namespace App\Services\Compliance;

use Illuminate\Http\Request;

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

    /**
     * Render the exception as an HTTP response (Laravel 11 renderable pattern).
     */
    public function render(Request $request)
    {
        $missing = implode(', ', $this->report->blockedBy);

        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'marketing_blocked',
                'message' => 'Marketing is blocked — property not compliance-ready.',
                'blocked_by' => $this->report->blockedBy,
                'report' => $this->report->toArray(),
            ], 422);
        }

        return redirect()->back()->with(
            'error',
            "Marketing blocked: {$missing}. See the Compliance Status panel for details."
        );
    }
}
