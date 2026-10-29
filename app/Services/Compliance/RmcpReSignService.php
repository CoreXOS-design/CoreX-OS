<?php

namespace App\Services\Compliance;

use App\Models\Compliance\RmcpVersion;
use App\Models\User;

/**
 * AT-506 - what happens to staff when an agency approves a NEW RMCP version. The work now lives in StaffTrainingService
 * (shared with policies and with the "Build staff training from this version" button); this keeps the approval's
 * call site and its return shape.
 */
class RmcpReSignService
{
    public function __construct(private readonly StaffTrainingService $training) {}

    /**
     * @return array{superseded:int, notified:int}
     */
    public function afterApproval(RmcpVersion $version, ?User $approver = null): array
    {
        $r = $this->training->send($version, $approver, StaffTrainingService::MODE_APPROVAL);

        return ['superseded' => $r['superseded'], 'notified' => $r['notified']];
    }
}
