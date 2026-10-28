<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Services\Docuperfect\Release\AgencyDocumentAuthorisers;
use App\Services\Docuperfect\Release\TemplateReleaseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * E-Sign → Who may authorise documents (.ai/specs/esign-template-transfer.md §13.3, Johan's R2). The people and roles an agency names to approve
 * a document CoreX has released to it. Gated by permission:esign.settings like every other e-sign settings screen; every posted id is
 * re-resolved inside the agency (a tampered id is refused). The Setup Wizard calls saveFromWizard() — has()-guarded per list so a step that posts
 * only one list never wipes the other (agency-onboarding-setup.md §6.1).
 */
class DocumentAuthoriserSettingsController extends Controller
{
    public function __construct(private readonly AgencyDocumentAuthorisers $authorisers)
    {
    }

    public function edit(Request $request)
    {
        $agencyId = (int) ($request->user()->effectiveAgencyId() ?: 0);
        $settings = AgencyDocumentAuthorisers::settings($agencyId);

        return view('docuperfect.esign.settings.document-authorisers', [
            'settings' => $settings,
            'configured' => AgencyDocumentAuthorisers::isConfigured($agencyId),
            'users' => \App\Models\User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email', 'role']),
            'roles' => AgencyDocumentAuthorisers::assignableRoles($agencyId),
            'withoutPermission' => AgencyDocumentAuthorisers::peopleWithoutThePermission($agencyId),
            'currentNames' => AgencyDocumentAuthorisers::authorisers($agencyId)->pluck('name')->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $agencyId = (int) ($request->user()->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return back()->with('error', 'No agency selected — switch into an agency first.');
        }

        try {
            $this->authorisers->save(
                $agencyId,
                $request->has('document_authoriser_user_ids') ? (array) $request->input('document_authoriser_user_ids', []) : null,
                $request->has('document_authoriser_roles') ? (array) $request->input('document_authoriser_roles', []) : null,
                $request->user(),
                'settings'
            );
        } catch (TemplateReleaseException $e) {
            return back()->withErrors(['authorisers' => $e->getMessage()]);
        }

        return redirect()->route('docuperfect.esign.settings.document-authorisers')
            ->with('status', 'Saved. Only the people and roles you ticked can approve a document CoreX releases to your agency.');
    }

    /**
     * Setup Wizard saver. 403 before any write without the e-sign settings permission; a step that did not post a list leaves it as it was.
     * The roles control posts `document_authoriser_roles_submitted` (an un-ticked checkbox group sends nothing); the people control posts an
     * empty hidden companion, so "everyone un-ticked" arrives as an empty list.
     */
    public function saveFromWizard(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && $user->hasPermission('esign.settings'), 403);
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        abort_unless($agencyId > 0, 403);

        $people = $request->has('document_authoriser_user_ids') ? (array) $request->input('document_authoriser_user_ids', []) : null;
        $roles = $request->has('document_authoriser_roles_submitted') ? (array) $request->input('document_authoriser_roles', []) : null;
        if ($people === null && $roles === null) {
            return;
        }

        try {
            $this->authorisers->save($agencyId, $people, $roles, $user, 'wizard');
        } catch (TemplateReleaseException $e) {
            abort($e->status, $e->getMessage());
        }
    }
}
