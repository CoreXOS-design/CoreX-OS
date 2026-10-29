<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\Release\TemplateReleaseException;
use App\Services\Docuperfect\Release\TemplateReleaseService;
use Illuminate\Http\Request;

/**
 * "Copy template to another agency" — CoreX administrators only.
 * Routes carry owner_only + permission:templates.transfer; the release service checks again.
 * The copy goes through the same gate as every route a template reaches an agency by: it lands NOT live, waiting for the agency's
 * authorisers to approve it, with a release log row (spec: .ai/specs/esign-template-transfer.md §13.1).
 * Spec: .ai/specs/cds-reimport-from-word.md.
 */
class TemplateAgencyCopyController extends Controller
{
    public function __construct(private readonly TemplateReleaseService $releases)
    {
    }

    public function store(Request $request, int $id)
    {
        $user = $request->user();
        abort_unless($user && $user->isOwnerRole() && $user->hasPermission('templates.transfer'), 403);

        $data = $request->validate([
            'agency_id' => 'required|integer|exists:agencies,id',
            'copy_lease_field_map' => 'nullable|boolean',
        ], [
            'agency_id.required' => 'Choose the agency to copy this template to.',
            'agency_id.exists' => 'That agency does not exist.',
        ]);

        $template = Template::findOrFail($id);
        $template->assertAccessibleBy($user);
        $target = Agency::query()->findOrFail((int) $data['agency_id']);

        try {
            $release = $this->releases->releaseByCopy($template, $target, $user, $request->boolean('copy_lease_field_map'));
        } catch (TemplateReleaseException $e) {
            return back()->withErrors(['copy_to_agency' => trim($e->getMessage() . ' ' . implode(' ', $e->details))]);
        }

        $copy = $release->template()->withoutGlobalScopes()->first();
        $msg = 'Sent to ' . $target->name . ' as "' . ($copy->name ?? $template->name) . '" (version ' . $release->version . '). It is NOT live there yet: it waits until a person the agency has chosen approves it (Documents → Releases shows where it stands).';
        if ($request->boolean('copy_lease_field_map')) {
            $msg .= $release->lease_link
                ? ' The rental lease link and field map will be applied when the agency approves it.'
                : ' The original has no rental lease link to carry.';
        }

        return back()->with('status', $msg);
    }
}
