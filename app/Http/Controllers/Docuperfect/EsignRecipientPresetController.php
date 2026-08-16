<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\EsignRecipientPreset;
use Illuminate\Http\Request;

/**
 * E-Sign → Recipient Presets (Johan 2026-08-16). Agency setup screen where the
 * entity/company-representative recipient PHRASING is DEFINED; consumed by the
 * esign wizard's expandEntityRecipients(). Agency-scoped via BelongsToAgency;
 * gated by permission:esign.settings.
 */
class EsignRecipientPresetController extends Controller
{
    private function rules(Request $request): array
    {
        return [
            'name'                    => ['required', 'string', 'max:120'],
            'applies_to'              => ['required', 'in:' . implode(',', EsignRecipientPreset::APPLIES_TO)],
            'phrasing_template'       => ['required', 'string', 'max:1000'],
            'signature_caption'       => ['nullable', 'string', 'max:1000'],
            'proxy_phrasing_template' => ['nullable', 'string', 'max:1000'],
            'proxy_signature_caption' => ['nullable', 'string', 'max:1000'],
            'is_default'              => ['nullable', 'boolean'],
        ];
    }

    /**
     * The agency this preset write belongs to. Resolve it explicitly rather than
     * leaning on BelongsToAgency's auto-stamp: that hook deliberately does NOT
     * stamp for OWNER / cross-agency roles (super_admin), so an owner creating a
     * preset would otherwise hit a NOT-NULL agency_id violation. Effective agency
     * (respects the agency switcher) with the user's home agency as the fallback.
     */
    private function actingAgencyId(): int
    {
        $u = auth()->user();
        $id = (int) ($u->effectiveAgencyId() ?? $u->agency_id ?? 0);
        abort_if($id === 0, 400, 'No agency context to attach the recipient preset to.');

        return $id;
    }

    public function index()
    {
        // Ensure the agency always has its default (seeds on first visit).
        $agencyId = $this->actingAgencyId();
        EsignRecipientPreset::defaultFor($agencyId);

        $presets = EsignRecipientPreset::orderByDesc('is_default')->orderBy('name')->get();

        return view('docuperfect.esign.settings.recipient-presets.index', [
            'presets' => $presets,
            'tokens'  => ['{entity_name}', '{rep_name}', '{capacity}', '{entity_reg_no}'],
        ]);
    }

    public function create()
    {
        return view('docuperfect.esign.settings.recipient-presets.form', [
            'preset'    => new EsignRecipientPreset([
                'applies_to'              => 'entity',
                'phrasing_template'       => EsignRecipientPreset::DEFAULT_PHRASING,
                'signature_caption'       => EsignRecipientPreset::DEFAULT_CAPTION,
                'proxy_phrasing_template' => EsignRecipientPreset::DEFAULT_PROXY_PHRASING,
                'proxy_signature_caption' => EsignRecipientPreset::DEFAULT_PROXY_CAPTION,
            ]),
            'appliesTo' => EsignRecipientPreset::APPLIES_TO,
            'tokens'    => ['{entity_name}', '{rep_name}', '{capacity}', '{entity_reg_no}'],
            'action'    => route('docuperfect.esign.recipient-presets.store'),
            'method'    => 'POST',
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request));
        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        // Stamp the agency explicitly — BelongsToAgency does not auto-stamp for
        // owner/cross-agency roles, so rely on this for every role. For a scoped
        // admin the trait would set the same value anyway.
        $data['agency_id'] = $this->actingAgencyId();

        $preset = EsignRecipientPreset::create($data);
        $preset->enforceSingleDefault();

        return redirect()->route('docuperfect.esign.recipient-presets.index')
            ->with('status', 'Recipient preset "' . $preset->name . '" created.');
    }

    public function edit(EsignRecipientPreset $preset)
    {
        return view('docuperfect.esign.settings.recipient-presets.form', [
            'preset'    => $preset,
            'appliesTo' => EsignRecipientPreset::APPLIES_TO,
            'tokens'    => ['{entity_name}', '{rep_name}', '{capacity}', '{entity_reg_no}'],
            'action'    => route('docuperfect.esign.recipient-presets.update', $preset),
            'method'    => 'PUT',
        ]);
    }

    public function update(Request $request, EsignRecipientPreset $preset)
    {
        $data = $request->validate($this->rules($request));
        $data['is_default'] = (bool) ($data['is_default'] ?? false);

        $preset->update($data);
        $preset->enforceSingleDefault();

        return redirect()->route('docuperfect.esign.recipient-presets.index')
            ->with('status', 'Recipient preset "' . $preset->name . '" updated.');
    }

    public function destroy(EsignRecipientPreset $preset)
    {
        // Never leave the agency without a default — reseed happens on next use.
        $preset->delete(); // soft delete (Non-Negotiable #1)

        return redirect()->route('docuperfect.esign.recipient-presets.index')
            ->with('status', 'Recipient preset removed.');
    }
}
