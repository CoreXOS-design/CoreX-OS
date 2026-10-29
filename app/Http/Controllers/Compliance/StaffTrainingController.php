<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Compliance\PolicyVersion;
use App\Models\Compliance\RmcpVersion;
use App\Services\Compliance\StaffTrainingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * "Build staff training from this version" - one button on the RMCP page and on every policy page (compliance.md).
 * Whoever may approve the document may send it to staff. Only an ACTIVE version can be sent: a draft is not in force yet.
 */
class StaffTrainingController extends Controller
{
    public function __construct(private readonly StaffTrainingService $training) {}

    public function rmcp(RmcpVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('approve_rmcp'), 403);

        return $this->send($version, 'compliance.rmcp.show', 'RMCP v' . $version->version_number);
    }

    public function policy(PolicyVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('approve_policy'), 403);
        $version->loadMissing('policy');

        return $this->send($version, 'compliance.policy.show', ($version->policy?->name ?: $version->title) . ' v' . $version->version_number);
    }

    private function send(Model $version, string $showRoute, string $label)
    {
        if ($version->status !== 'active') {
            return redirect()->route($showRoute, $version)
                ->with('error', 'Only the active version can be sent to staff. Approve this version first.');
        }

        $r = $this->training->send($version, Auth::user(), StaffTrainingService::MODE_BUTTON);

        return redirect()->route($showRoute, $version)->with('success',
            "{$label} sent to staff. {$r['asked']} staff member(s) were asked to read it and sign; {$r['total']} active staff in total."
        );
    }
}
