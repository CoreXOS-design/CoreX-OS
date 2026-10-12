<?php

namespace Tests\Feature\Onboarding\Concerns;

use App\Models\Agency;
use App\Models\User;

/**
 * The fields a real browser would submit for a wizard step: GET the page, then
 * serialise form#wizard-step-form the way HTML does — every hidden, text,
 * number, textarea and select field; checkboxes/radios only when checked (a
 * ticked toggle posts 1 over its hidden 0; an unticked one posts just the
 * hidden 0). Decoded with parse_str so `name[]` fields arrive as arrays.
 *
 * Why: a hand-written payload goes stale every time a control joins the step
 * (a real browser always sends every toggle, so a payload missing one is one
 * no user can produce, and the saver's has()-guard rightly refuses it with
 * "That did not save"). A test that should prove something else then fails —
 * or worse, passes for the wrong reason. Build the baseline from the page,
 * layer explicit overrides on top, and unset the one key a test is about.
 */
trait PostsWizardStepLikeABrowser
{
    /**
     * The repeater rows the step's Alpine lists add in the browser. They are
     * built by x-for from the saved lists (rentals-inspection-lists partial),
     * so they are not in the server-rendered HTML browserFormFields() reads —
     * but a real browser posts them, and the condition / classification savers
     * refuse an empty list. Same names and values the templates emit.
     *
     * @return array<string, array<int, array<string, string>>>
     */
    private function alpineListRows(Agency $agency): array
    {
        $id = $agency->id;

        return [
            'refusal_reason_presets' => collect(\App\Models\RentalInspectionSetting::refusalReasonPresetsFor($id))
                ->reject(fn ($p) => $p['key'] === 'other')
                ->map(fn ($p) => ['label' => $p['label'], 'key' => $p['key']])->values()->all(),
            'condition_states' => collect(\App\Models\RentalInspectionSetting::conditionStatesFor($id))
                ->map(fn ($c) => ['label' => $c['label'], 'severity' => $c['severity'] ?? 'red', 'key' => $c['key'], 'requires_notes' => ! empty($c['requires_notes']) ? '1' : '0'])->values()->all(),
            'photo_note_classifications' => collect(\App\Models\RentalInspectionSetting::photoNoteClassificationsFor($id))
                ->map(fn ($c) => ['label' => $c['label'], 'key' => $c['key']])->values()->all(),
            'inventory_condition_states' => collect(\App\Models\RentalInventorySetting::conditionStatesFor($id))
                ->map(fn ($c) => ['label' => $c['label'], 'key' => $c['key'], 'requires_notes' => ! empty($c['requires_notes']) ? '1' : '0'])->values()->all(),
        ];
    }

    /**
     * The fields a real browser would submit for a wizard step: GET the page,
     * then serialise form#wizard-step-form the way HTML does — every hidden,
     * text, number, textarea and select field; checkboxes/radios only when
     * checked (a ticked toggle posts 1 over its hidden 0; an unticked one
     * posts just the hidden 0). Decoded with parse_str so `name[]` fields
     * arrive as arrays, as they do in PHP.
     *
     * @return array<string, mixed>
     */
    private function browserFormFields(User $admin, string $step): array
    {
        $html = $this->actingAs($admin)
            ->get(route('corex.agency-setup.step', ['step' => $step]))
            ->assertOk()
            ->getContent();

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $form = (new \DOMXPath($dom))->query('//form[@id="wizard-step-form"]')->item(0);
        $this->assertNotNull($form, 'wizard step form not found on the rendered page');

        $pairs = [];
        $add = function (string $name, string $value) use (&$pairs): void {
            $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
        };
        foreach ((new \DOMXPath($dom))->query('.//input|.//select|.//textarea', $form) as $el) {
            $name = $el->getAttribute('name');
            if ($name === '' || $el->hasAttribute('disabled')) {
                continue;
            }
            if ($el->nodeName === 'textarea') {
                $add($name, $el->textContent);
            } elseif ($el->nodeName === 'select') {
                $chosen = null;
                foreach ($el->getElementsByTagName('option') as $opt) {
                    $chosen ??= $opt->getAttribute('value');
                    if ($opt->hasAttribute('selected')) {
                        $chosen = $opt->getAttribute('value');
                    }
                }
                if ($chosen !== null) {
                    $add($name, $chosen);
                }
            } else {
                $type = strtolower($el->getAttribute('type') ?: 'text');
                if (in_array($type, ['submit', 'button', 'image', 'reset', 'file'], true)) {
                    continue;
                }
                if (in_array($type, ['checkbox', 'radio'], true) && ! $el->hasAttribute('checked')) {
                    continue;
                }
                $add($name, $el->hasAttribute('value') || $type !== 'checkbox' ? $el->getAttribute('value') : 'on');
            }
        }
        parse_str(implode('&', $pairs), $fields);

        return $fields;
    }
}
