<?php

namespace Tests\Feature\Onboarding;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Compliance\PpraEmploymentLetter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The PPRA employment-letter addressee setting in the Agency Setup Wizard's
 * Compliance step (CLAUDE.md #10a; spec ppra-ffc-employment-letter.md §11).
 *
 * Every test is the same shape the wizard really runs: GET the step, serialise
 * the form the way a browser does, change one thing, POST it, then read the real
 * column AND reopen the step to see the saved value rendered back (§6.2).
 */
final class AgencySetupWizardPpraTest extends TestCase
{
    use RefreshDatabase;

    private function agency(): Agency
    {
        return Agency::create(['name' => 'Cape Town Rentals', 'slug' => 'ct-rentals-' . uniqid()]);
    }

    private function admin(Agency $agency): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'admin',
            'is_active' => true,
        ]);
    }

    private function save(User $admin, string $step, array $overrides = [], array $unset = [])
    {
        $fields = array_replace($this->browserFormFields($admin, $step), $overrides);
        foreach ($unset as $key) {
            unset($fields[$key]);
        }

        return $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => $step]), $fields);
    }

    /**
     * The fields a real browser would submit for a wizard step (hidden/text/number/textarea/select;
     * checkboxes only when checked). Same serialiser as the QA1 helper trait PostsWizardStepLikeABrowser,
     * inlined because that trait carries rentals-only helpers that do not exist here.
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

    // ── PPRA employment letter — Compliance step ─────────────────────────

    public function test_ppra_letter_address_shows_the_ppra_default_as_a_placeholder_not_a_value(): void
    {
        $admin = $this->admin($this->agency());

        $html = $this->actingAs($admin)
            ->get(route('corex.agency-setup.step', ['step' => 'compliance']))
            ->assertOk()
            ->assertSee('PPRA employment letter — who it is addressed to')
            ->assertSee('What this changes:', false)
            ->getContent();

        // The default is the regulator's own address, not any one agency's.
        $this->assertStringContainsString('63 Wierda Road East', $html);
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="ppra_employment_letter_address_block"[^>]*placeholder="[^"]*Wierda Road East/s', $html);
        // …and it is NOT pre-filled as the value, so saving the step never freezes
        // the default into the agency's own column.
        $this->assertDoesNotMatchRegularExpression('/<textarea[^>]*name="ppra_employment_letter_address_block"[^>]*>[^<]*Wierda/s', $html);
    }

    public function test_ppra_letter_address_round_trips_and_matches_the_settings_page_save(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $block  = "The Property Practitioners Regulatory Authority\nCape Town Regional Office\n12 Strand Street\nCape Town\n8001";

        $this->save($admin, 'compliance', ['ppra_employment_letter_address_block' => $block])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($block, $agency->fresh()->ppra_employment_letter_address_block);

        // Reopening the step renders the SAVED value back.
        $this->actingAs($admin)
            ->get(route('corex.agency-setup.step', ['step' => 'compliance']))
            ->assertOk()
            ->assertSee('12 Strand Street');

        // One source of truth: clear it, then the settings page's own route stores the identical value.
        $viaWizard = $agency->fresh()->ppra_employment_letter_address_block;
        $agency->forceFill(['ppra_employment_letter_address_block' => null])->save();
        $this->actingAs($admin)
            ->post(route('corex.settings.ppra-employment-letter.save'), ['ppra_employment_letter_address_block' => $block])
            ->assertRedirect();
        $this->assertSame($viaWizard, $agency->fresh()->ppra_employment_letter_address_block);
    }

    public function test_blank_ppra_letter_address_goes_back_to_the_default(): void
    {
        $agency = $this->agency();
        $agency->forceFill(['ppra_employment_letter_address_block' => '1 Old Road'])->save();
        $admin = $this->admin($agency);

        $this->save($admin, 'compliance', ['ppra_employment_letter_address_block' => ''])->assertRedirect();

        $this->assertNull($agency->fresh()->ppra_employment_letter_address_block);
    }

    public function test_a_post_that_never_rendered_the_ppra_address_leaves_it_alone(): void
    {
        $agency = $this->agency();
        $agency->forceFill(['ppra_employment_letter_address_block' => '1 Old Road'])->save();
        $admin = $this->admin($agency);

        // §6.1 — absent means "leave it alone", never "clear it".
        $this->save($admin, 'compliance', [], ['ppra_employment_letter_address_block'])->assertRedirect();

        $this->assertSame('1 Old Road', $agency->fresh()->ppra_employment_letter_address_block);
    }

    // ── Other Agency Stock — Properties step ─────────────────────────────

    public function test_default_ppra_address_is_the_regulators_not_any_agencys(): void
    {
        $this->assertStringNotContainsStringIgnoringCase('home finders', PpraEmploymentLetter::DEFAULT_PPRA_ADDRESS_BLOCK);
    }
}
