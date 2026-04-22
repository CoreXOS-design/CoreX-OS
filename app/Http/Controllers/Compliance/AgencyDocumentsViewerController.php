<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Compliance\AgencyComplianceProvision;
use App\Models\Compliance\AgencyDocumentTypeConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AgencyDocumentsViewerController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $agency = $user->agency ?? \App\Models\Agency::find($user->effectiveAgencyId());
        abort_unless($agency, 403, 'No agency context.');

        $branchId = $user->effectiveBranchId();
        $splitEnabled = (bool) ($agency->split_branches_enabled ?? false);
        $resolveBranch = $splitEnabled ? $branchId : null;

        $configs = AgencyDocumentTypeConfig::active()->ordered()->get();

        $documents = $configs->map(function ($config) use ($resolveBranch) {
            $provision = AgencyComplianceProvision::resolveForUser($config->id, $resolveBranch);

            return (object) [
                'config'    => $config,
                'provision' => $provision,
                'status'    => $this->statusFor($provision, $config),
                'scope'     => $provision && $provision->branch_id ? 'branch' : 'company',
            ];
        });

        $branchName = $resolveBranch ? Branch::find($resolveBranch)?->name : null;

        return view('compliance.my-portal.agency-documents', compact('documents', 'splitEnabled', 'branchName'));
    }

    public function download(AgencyComplianceProvision $provision, Request $request)
    {
        $user = $request->user();
        $agency = $user->agency ?? \App\Models\Agency::find($user->effectiveAgencyId());
        abort_unless($agency, 403);

        // Multi-tenant check
        abort_unless($provision->agency_id === $user->effectiveAgencyId(), 403);

        $branchId = $user->effectiveBranchId();
        $splitEnabled = (bool) ($agency->split_branches_enabled ?? false);

        // Branch scope check
        if ($splitEnabled) {
            if ($provision->branch_id && $provision->branch_id !== $branchId) {
                abort(403, 'You can only access your own branch documents.');
            }
        } else {
            if ($provision->branch_id !== null) {
                abort(403, 'Branch-specific documents are not accessible when branch splitting is disabled.');
            }
        }

        // Must be the currently resolved version (prevent downloading superseded docs)
        $resolved = AgencyComplianceProvision::resolveForUser(
            $provision->document_type_config_id,
            $splitEnabled ? $branchId : null
        );
        abort_unless($resolved && $resolved->id === $provision->id, 403);

        abort_unless($provision->document_path, 404, 'No file attached to this document.');

        return Storage::disk('public')->download(
            $provision->document_path,
            $provision->document_original_name
        );
    }

    private function statusFor(?AgencyComplianceProvision $provision, AgencyDocumentTypeConfig $config): object
    {
        if (! $provision) {
            return (object) [
                'label'  => $config->required ? 'Required — not available' : 'Not available',
                'colour' => $config->required ? 'red' : 'slate',
            ];
        }

        if (! $config->has_expiry || ! $provision->effective_until) {
            return (object) ['label' => 'Available', 'colour' => 'teal'];
        }

        $daysLeft = (int) now()->diffInDays($provision->effective_until, false);

        if ($daysLeft < 0) {
            return (object) ['label' => 'Expired ' . abs($daysLeft) . ' days ago', 'colour' => 'red'];
        }
        if ($daysLeft <= 30) {
            return (object) ['label' => "Expiring in {$daysLeft} days", 'colour' => 'amber'];
        }

        return (object) [
            'label'  => 'Valid until ' . $provision->effective_until->format('d M Y'),
            'colour' => 'teal',
        ];
    }
}
