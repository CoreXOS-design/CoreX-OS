<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Compliance\PolicyVersion;
use App\Models\Compliance\RmcpVersion;
use App\Services\Compliance\SignedCopyService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Signed copy of an RMCP / policy version + the compliance officer's sign-off (compliance.md).
 * Download: anyone who may view the document. Upload / replace: owner, admin, the appointed compliance officer, or whoever
 * holds manage_signed_copy in Role Manager. Neither approves anything or touches staff acknowledgements.
 */
class SignedCopyController extends Controller
{
    public function __construct(private readonly SignedCopyService $signed) {}

    public function rmcpDownload(RmcpVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('access_rmcp'), 403);

        return $this->download($version, 'compliance.rmcp.show');
    }

    public function rmcpStore(Request $request, RmcpVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('access_rmcp'), 403);

        return $this->store($request, $version, 'compliance.rmcp.show');
    }

    public function policyDownload(PolicyVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('access_policy'), 403);

        return $this->download($version, 'compliance.policy.show');
    }

    public function policyStore(Request $request, PolicyVersion $version)
    {
        abort_unless(Auth::user()->hasPermission('access_policy'), 403);

        return $this->store($request, $version, 'compliance.policy.show');
    }

    private function download(Model $version, string $showRoute)
    {
        $file = $this->signed->file($version);
        if (! $file) {
            return redirect()->route($showRoute, $version)->with('error', 'There is no signed copy on the system for this version yet.');
        }

        return Storage::disk('local')->download($file['path'], $file['name'], ['Content-Type' => 'application/pdf']);
    }

    private function store(Request $request, Model $version, string $showRoute)
    {
        $user = Auth::user();
        abort_unless($this->signed->canManage($user, (int) $version->agency_id), 403);

        $back = fn () => redirect()->to(route($showRoute, $version) . '#signed-copy');

        if (! $this->signed->canAttachTo($version)) {
            return $back()->with('error', 'A signed copy can be added once the version has been approved.');
        }

        $data = $request->validate([
            'signed_copy'         => 'required|file|mimes:pdf|max:20480',
            'signed_by_name'      => 'nullable|string|max:190',
            'signed_on'           => 'nullable|date|before_or_equal:today',
            'submitted_to_fic_on' => 'nullable|date|before_or_equal:today',
        ], [
            'signed_copy.required' => 'Choose the signed PDF to upload.',
            'signed_copy.mimes'    => 'The signed copy must be a PDF.',
            'signed_copy.max'      => 'The signed copy must be 20 MB or smaller.',
        ]);

        $this->signed->store(
            $version,
            $request->file('signed_copy'),
            $user,
            $data['signed_by_name'] ?? null,
            $data['signed_on'] ?? null,
            $data['submitted_to_fic_on'] ?? null,
        );

        return $back()->with('success', 'The signed copy is on the system. Staff were not asked to sign again.');
    }
}
