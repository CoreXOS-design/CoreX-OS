<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\RecipientTemplate;
use Illuminate\Http\Request;

/**
 * Johan, 2026-08-24 — the recipient template settings screen. Authored like
 * the clause library (docuperfect.clauses.*) in UX terms — freeform text
 * with insertable fields, a named list-and-edit screen — but scoped
 * differently: agency-scoped, never platform-global, with NO interface path
 * to write agency_id from the client (always server-stamped from the acting
 * user's effective agency) or to see/edit another agency's rows. CoreX's own
 * NULL-agency seeded defaults are shown but read-only here — they're
 * maintained by RecipientTemplateSeeder, not this screen.
 */
class RecipientTemplateController extends Controller
{
    public function index(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $agencyTemplates = RecipientTemplate::query()
            ->where('agency_id', $agencyId)
            ->orderBy('role_token')->orderBy('name')
            ->get();

        $coreXDefaults = RecipientTemplate::query()
            ->whereNull('agency_id')
            ->orderBy('role_token')->orderBy('name')
            ->get();

        return view('docuperfect.recipient-templates.index', compact('agencyTemplates', 'coreXDefaults'));
    }

    public function store(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();
        abort_unless($agencyId, 403, 'No agency context.');

        $data = $this->validated($request);
        $data['agency_id'] = $agencyId; // server-stamped — never trust a client-supplied agency_id

        RecipientTemplate::create($data);

        return redirect()->route('docuperfect.recipient-templates.index')
            ->with('status', "Recipient template \"{$data['name']}\" created.");
    }

    public function edit(Request $request, RecipientTemplate $recipientTemplate)
    {
        $agencyId = $request->user()->effectiveAgencyId();
        // Same scope as update()/destroy() — an id from another agency (or a
        // CoreX NULL-agency default) 404s here rather than being editable.
        abort_unless($recipientTemplate->agency_id === $agencyId, 404);

        return view('docuperfect.recipient-templates.edit', compact('recipientTemplate'));
    }

    public function update(Request $request, RecipientTemplate $recipientTemplate)
    {
        $agencyId = $request->user()->effectiveAgencyId();
        // Scoped by agency_id, not just findOrFail — an id from another agency
        // (or a CoreX NULL-agency default) 404s here rather than being editable.
        abort_unless($recipientTemplate->agency_id === $agencyId, 404);

        $data = $this->validated($request);
        // agency_id is deliberately NOT in $data — it can never move to another
        // agency through this form; the row already belongs to the acting agency.

        $recipientTemplate->update($data);

        return redirect()->route('docuperfect.recipient-templates.index')
            ->with('status', "Recipient template \"{$recipientTemplate->name}\" updated.");
    }

    public function destroy(Request $request, RecipientTemplate $recipientTemplate)
    {
        $agencyId = $request->user()->effectiveAgencyId();
        abort_unless($recipientTemplate->agency_id === $agencyId, 404);

        $recipientTemplate->delete(); // SoftDeletes — no hard delete, ever

        return redirect()->route('docuperfect.recipient-templates.index')
            ->with('status', "Recipient template \"{$recipientTemplate->name}\" removed.");
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'role_token' => 'required|string|in:seller,buyer,lessor,lessee,any',
            'key' => 'required|string|max:60|regex:/^[a-z0-9_]+$/',
            'name' => 'required|string|max:120',
            'text_template' => 'required|string',
            'party_slots' => 'required|array|min:1',
            'party_slots.*.key' => 'required|string|regex:/^[a-z0-9_]+$/',
            'party_slots.*.label' => 'required|string|max:80',
        ]);

        return $validated;
    }
}
