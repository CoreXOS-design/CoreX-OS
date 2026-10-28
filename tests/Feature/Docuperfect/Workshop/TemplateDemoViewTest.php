<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Workshop;

use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateRelease;
use App\Services\Docuperfect\Demo\TemplateDemoSample;
use App\Services\Docuperfect\Readiness\TemplateReadinessReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/esign-template-demo-preview.md §3–§9 — the sample values and where they came from, the demo screen (banner, signers and places,
 * fields, page frame), the gate (404 across agencies, 403, held-back copies for authorisers only), PDF templates, and the proof that it
 * writes nothing.
 */
final class TemplateDemoViewTest extends TestCase
{
    use RefreshDatabase;
    use WorkshopFixtures;

    private function demoUrl(Template $t): string
    {
        return route('docuperfect.templates.demo', $t->id);
    }

    // ── the sample data ───────────────────────────────────────────────────────

    public function test_each_blank_gets_a_sample_value_and_its_source(): void
    {
        $a = $this->agency('Mega Real Estate');
        $t = $this->megaShaped($a, $this->owner());
        $ctx = app(TemplateReadinessReport::class)->context($t);

        $built = app(TemplateDemoSample::class)->build($t, $ctx);
        $byLabel = [];
        foreach ($built['fields'] as $f) {
            $byLabel[$f['label']] = $f;
        }

        $this->assertArrayHasKey('Property Address', $byLabel);
        $this->assertSame('12 Sample Street', $byLabel['Property Address']['value'] ?? null, 'a field bound to the property address gets the sample street');
        $this->assertSame('bound', $byLabel['Property Address']['source']);
        $this->assertSame('Typed by hand while preparing', $byLabel['Pet deposit']['binding']);
        $this->assertContains($byLabel['Pet deposit']['source'], ['guessed', 'placeholder']);
        foreach ($built['fields'] as $f) {
            $this->assertNotSame('', trim((string) $f['value']), 'every blank is filled');
        }
        // The frame tokens' variables are always there, and nothing in the sample is HFC's.
        $this->assertNotSame('', $built['values']['property_address']);
        $this->assertNotSame('', $built['values']['lessee_last_name']);
        $this->assertStringNotContainsStringIgnoringCase('home finders', json_encode(config('template-demo-sample')));
        $this->assertSame('Thandi Mokoena', $built['recipients_by_role']['landlord'][0]['name']);
    }

    public function test_a_blank_the_sample_knows_nothing_about_becomes_a_labelled_placeholder(): void
    {
        $a = $this->agency('Mega Real Estate');
        $t = $this->megaShaped($a, $this->owner());
        $editor = $t->editor_state;
        $editor['tags'][] = ['id' => 'tag-z', 'type' => 'input', 'label' => '[INPUT 9]', 'number' => 9];
        $editor['mappings']['tag-z'] = ['label' => 'Crest of arms', 'typeKey' => 'sf:manual', 'mappingType' => 'manual', 'namedFieldId' => null, 'fieldGroupId' => null];
        $t->update(['editor_state' => $editor]);

        $built = app(TemplateDemoSample::class)->build($t->fresh(), app(TemplateReadinessReport::class)->context($t->fresh()));
        $row = collect($built['fields'])->firstWhere('label', 'Crest of arms');
        $this->assertSame('placeholder', $row['source']);
        $this->assertSame('Sample: Crest of arms', $row['value']);
    }

    // ── the screen ────────────────────────────────────────────────────────────

    public function test_the_demo_shows_the_banner_filled_blanks_signers_places_and_the_frame(): void
    {
        $a = $this->agency('Mega Real Estate');
        $owner = $this->owner();
        $t = $this->compiled($this->megaShaped($a, $owner));

        $html = $this->actingAs($owner)->get($this->demoUrl($t))->assertOk()->getContent();

        $this->assertStringContainsString('data-demo-banner', $html);
        $this->assertStringContainsString('Nothing can be signed or sent from here', $html);
        $this->assertStringContainsString('12 Sample Street', $html, 'a blank filled with the sample property');
        $this->assertStringContainsString('data-demo-source="bound"', $html);
        $this->assertStringContainsString('data-marker-party="landlord"', $html, 'the Lessor place is drawn as the landlord\'s');
        $this->assertStringContainsString('data-marker-party="tenant"', $html);
        $this->assertStringContainsString('Thandi Mokoena', $html);

        // Signers and places.
        $this->assertStringContainsString('data-signer="owner_party"', $html);
        $this->assertStringContainsString('data-signer="acquiring_party"', $html);
        $this->assertStringContainsString('data-demo-places', $html);
        $this->assertStringNotContainsString('NOWHERE TO SIGN', $html);
        // Fields and frame.
        $this->assertStringContainsString('data-field-row=', $html);
        $this->assertStringContainsString('Mega Rent Lease – 12 Sample Street, Table View, Cape Town, 7441 – van der Merwe – ' . now()->addMonthNoOverflow()->startOfMonth()->format('Y'), $html, 'header with sample property, surname and year');
        $this->assertStringContainsString('Page 1', $html);
        // Read only: nothing in the demo itself posts anywhere (the page shell has its own search / logout forms).
        $demoPart = substr($html, strpos($html, 'data-demo-view'));
        $this->assertStringNotContainsString('<form', substr($demoPart, 0, strpos($demoPart, 'function (') ?: null));
        $this->assertStringNotContainsString('signatures/external', $demoPart);
    }

    public function test_a_signature_place_naming_an_undeclared_signer_and_a_signer_with_no_place_are_called_out(): void
    {
        $a = $this->agency('Mega Real Estate');
        $owner = $this->owner();
        $t = $this->megaShaped($a, $owner);
        $editor = $t->editor_state;
        $editor['mappings']['tag-s2']['parties'] = ['Witness'];
        $t->update(['editor_state' => $editor]);
        $t = $this->compiled($t->fresh());

        $html = $this->actingAs($owner)->get($this->demoUrl($t))->assertOk()->getContent();
        $this->assertStringContainsString('not a declared signer', $html);
        $this->assertStringContainsString('data-place-ok="0"', $html);
        $this->assertStringContainsString('NOWHERE TO SIGN', $html, 'the Lessee now has no place to sign');
    }

    public function test_a_template_without_a_built_page_file_is_shown_from_its_saved_text_with_a_note_and_nothing_is_written(): void
    {
        $a = $this->agency('Mega Real Estate');
        $owner = $this->owner();
        $t = $this->megaShaped($a, $owner, ['blade_view' => 'docuperfect.web-templates.cds.template-not-built-yet']);
        $existing = glob(resource_path('views/docuperfect/web-templates/cds/template-*.blade.php')) ?: [];

        $html = $this->actingAs($owner)->get($this->demoUrl($t))->assertOk()->getContent();
        $this->assertStringContainsString('data-demo-note', $html);
        $this->assertStringContainsString('has not been built yet', $html);
        $this->assertSame(count($existing), count(glob(resource_path('views/docuperfect/web-templates/cds/template-*.blade.php')) ?: []), 'the demo never writes a page file');
    }

    public function test_a_template_with_no_blanks_signers_or_places_still_opens_with_plain_empty_states(): void
    {
        $a = $this->agency('Mega Real Estate');
        $owner = $this->owner();
        $t = $this->megaShaped($a, $owner, ['signing_parties' => []], ['tags' => [], 'mappings' => [], 'tagged_html' => '<p>Plain wording only.</p>']);
        $t = $this->compiled($t->fresh());

        $html = $this->actingAs($owner)->get($this->demoUrl($t))->assertOk()->getContent();
        $this->assertStringContainsString('data-no-signers', $html);
        $this->assertStringContainsString('has no signature or initial places of its own', $html);
        $this->assertStringContainsString('This template has no blanks to fill', $html);
        $this->assertStringContainsString('Plain wording only.', $html);
    }

    public function test_a_pdf_template_says_so_and_lists_its_signature_zones(): void
    {
        $a = $this->agency('Mega Real Estate');
        $owner = $this->owner();
        $t = $this->pdfTemplate($a, $owner, 2);

        $html = $this->actingAs($owner)->get($this->demoUrl($t))->assertOk()->getContent();
        $this->assertStringContainsString('data-demo-pdf', $html);
        $this->assertStringContainsString('This is a PDF template', $html);
        $this->assertStringContainsString('data-demo-places', $html);
        $this->assertStringContainsString('Signature page 1', $html);
    }

    public function test_an_empty_rendered_blank_is_flagged_empty(): void
    {
        $a = $this->agency('Mega Real Estate');
        $owner = $this->owner();
        $t = $this->compiled($this->megaShaped($a, $owner));
        // A blank whose value the sample cannot give: point the street variable's sample at an empty value.
        config(['template-demo-sample.vars.property_street' => '']);

        $html = $this->actingAs($owner)->get($this->demoUrl($t))->assertOk()->getContent();
        $this->assertStringContainsString('data-demo-source="empty"', $html);
        $this->assertMatchesRegularExpression('/data-field-row="[^"]*" data-status="empty"/', $html);
    }

    // ── the gate ──────────────────────────────────────────────────────────────

    public function test_the_gate_is_owner_and_own_agency_404_across_agencies_403_without_permission(): void
    {
        $mega = $this->agency('Mega Real Estate');
        $owner = $this->owner();
        $t = $this->megaShaped($mega, $owner);
        $manager = $this->agencyManager($mega);

        $this->actingAs($owner)->get($this->demoUrl($t))->assertOk();
        $this->actingAs($manager)->get($this->demoUrl($t))->assertOk();
        $this->actingAs($owner)->get(route('docuperfect.templates.demo', 99999999))->assertNotFound();
    }

    public function test_another_agencys_manager_gets_404(): void
    {
        $mega = $this->agency('Mega Real Estate');
        $t = $this->megaShaped($mega, $this->owner());
        $foreign = $this->agencyManager($this->agency('Cape Town Lettings'));

        $this->actingAs($foreign)->get($this->demoUrl($t))->assertNotFound();
    }

    public function test_a_user_without_the_permission_gets_403(): void
    {
        $mega = $this->agency('Mega Real Estate');
        $t = $this->megaShaped($mega, $this->owner());
        \App\Services\PermissionService::forceProductionPosture();
        $plain = \App\Models\User::factory()->create(['agency_id' => $mega->id, 'role' => 'agent', 'is_active' => true]);

        $this->actingAs($plain)->get($this->demoUrl($t))->assertForbidden();
    }

    public function test_a_released_copy_awaiting_approval_can_be_demoed_by_its_authoriser_but_not_by_others(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        [, $copy] = $this->releasedCopy($this->megaShaped($w, $owner), $client, $owner);

        $this->actingAs($this->agencyAdmin($client))->get($this->demoUrl($copy))->assertOk();
        $this->actingAs($owner)->get($this->demoUrl($copy))->assertOk();
    }

    public function test_a_released_copy_is_closed_to_a_manager_who_is_not_an_authoriser(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        [, $copy] = $this->releasedCopy($this->megaShaped($w, $owner), $client, $owner);

        $this->actingAs($this->agencyManager($client, [], 'branch_manager'))->get($this->demoUrl($copy))->assertNotFound();
    }

    public function test_the_approval_screen_links_to_the_demo(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        [$release, $copy] = $this->releasedCopy($this->megaShaped($w, $owner), $client, $owner);

        $html = $this->actingAs($this->agencyAdmin($client))->get(route('docuperfect.template-approvals.show', $release->id))->assertOk()->getContent();
        $this->assertStringContainsString('data-open-demo', $html);
        $this->assertStringContainsString($this->demoUrl($copy), $html);
    }

    // ── nothing is written ────────────────────────────────────────────────────

    public function test_the_demo_writes_nothing(): void
    {
        $a = $this->agency('Mega Real Estate');
        $owner = $this->owner();
        $t = $this->compiled($this->megaShaped($a, $owner));
        $manager = $this->agencyManager($a);
        $tables = ['documents', 'flows', 'contacts', 'properties', 'domain_event_log', 'template_transfer_log', 'docuperfect_templates', 'docuperfect_template_releases', 'docuperfect_template_release_events', 'jobs', 'notifications'];
        $count = function () use ($tables) {
            $out = [];
            foreach ($tables as $tbl) {
                $out[$tbl] = \Illuminate\Support\Facades\Schema::hasTable($tbl) ? \DB::table($tbl)->count() : 0;
            }

            return $out;
        };
        $rowBefore = $this->templateSnapshot($t);
        $before = $count();
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Notification::fake();
        \Illuminate\Support\Facades\Event::fake([\App\Events\Docuperfect\TemplateReleased::class]);

        $this->actingAs($owner)->get($this->demoUrl($t))->assertOk();
        $this->actingAs($manager)->get($this->demoUrl($t))->assertOk();

        $this->assertSame($before, $count());
        $this->assertSame($rowBefore, $this->templateSnapshot($t));
        \Illuminate\Support\Facades\Mail::assertNothingSent();
        \Illuminate\Support\Facades\Notification::assertNothingSent();
    }

    public function test_the_demo_is_get_only(): void
    {
        $a = $this->agency('Mega Real Estate');
        $owner = $this->owner();
        $t = $this->megaShaped($a, $owner);

        $this->actingAs($owner)->post($this->demoUrl($t))->assertStatus(405);
        $this->actingAs($owner)->put($this->demoUrl($t))->assertStatus(405);
    }
}
