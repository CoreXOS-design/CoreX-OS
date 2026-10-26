<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\TemplateAgencyCopier;
use App\Services\Docuperfect\TemplateTransfer\TemplateTransferException;
use Illuminate\Http\Request;

/**
 * "Copy template to another agency" — CoreX administrators only.
 * Routes carry owner_only + permission:templates.transfer; the copier checks again.
 * Spec: .ai/specs/cds-reimport-from-word.md.
 */
class TemplateAgencyCopyController extends Controller
{
    public function __construct(private readonly TemplateAgencyCopier $copier)
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
            $result = $this->copier->copy($template, $target, $user, $request->boolean('copy_lease_field_map'));
        } catch (TemplateTransferException $e) {
            return back()->withErrors(['copy_to_agency' => trim($e->getMessage() . ' ' . implode(' ', $e->details))]);
        }

        $copy = $result['template'];
        $msg = 'Copied to ' . $target->name . ' as "' . $copy->name . '" (template #' . $copy->id . ').'
            . ($result['renamed'] ? ' That agency already had a template with the original name, so this one was numbered.' : '');
        if ($request->boolean('copy_lease_field_map')) {
            $msg .= count($result['lease_templates'])
                ? ' Rental lease link and field map copied (' . count($result['lease_templates']) . ').'
                : ' The original has no rental lease link to copy.';
            if ($result['lease_problems']) {
                $msg .= ' The lease check found: ' . implode('; ', $result['lease_problems']) . '.';
            }
        }

        return back()->with('status', $msg);
    }
}
