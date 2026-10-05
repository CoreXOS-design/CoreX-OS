<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Events\Agent\AgentPpraLetterDetailsChanged;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Compliance\PpraEmploymentLetter;
use App\Models\Role;
use App\Models\User;
use App\Services\Compliance\PpraEmploymentLetterPdfService;
use App\Services\Compliance\PpraEmploymentLetterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The PPRA Confirmation of Employment letter reproduces the agency's Word "letter of employment"
 * (.ai/specs/ppra-ffc-employment-letter.md §18). These tests render the REAL PDF through Chromium and
 * read its text back with pdftotext, so they assert what lands on the page, not what the template says.
 */
final class PpraEmploymentLetterPdfLayoutTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $principal;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();

        $this->agency = Agency::create([
            'name' => 'Johan and Elize Properties Pty(Ltd)', 'slug' => 'jep',
            'trading_name' => 'Home Finders Coastal', 'ppra_number' => 'F145200',
        ]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);

        $this->principal = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
            'name' => 'Elize Reichel', 'full_first_names' => 'Elizabeth Petronella',
            'ffc_number' => '0319084', 'is_principal_practitioner' => true, 'is_active' => true,
            'designation' => 'Principal Practitioner',
        ]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
            'name' => 'Johan Reichel', 'full_first_names' => 'Johan Hendrik',
            'id_number' => '7610025020081', 'ffc_number' => '0502216', 'is_active' => true,
            'designation' => 'CEO', 'ppra_category' => 'Candidate Principal Property Practitioner',
        ]);
    }

    private function pdfText(?PpraEmploymentLetter $letter = null, ?string $agentSig = null, ?string $principalSig = null): string
    {
        $letter ??= app(PpraEmploymentLetterService::class)->create($this->agent, $this->agent, $this->principal->id);
        $path = app(PpraEmploymentLetterPdfService::class)->generate($letter, $this->agent->fresh(), $this->principal->fresh(), $this->agency->fresh(), $agentSig, $principalSig);
        if ($proof = getenv('PPRA_LETTER_PROOF_PDF')) {
            copy($path, $proof);
        }
        $text = (string) shell_exec('pdftotext -layout ' . escapeshellarg($path) . ' - 2>&1');
        @unlink($path);

        return $text;
    }

    private function lines(string $text): array
    {
        return array_values(array_filter(array_map(fn ($l) => preg_replace('/\s+/', ' ', trim($l)), explode("\n", $text)), fn ($l) => $l !== ''));
    }

    public function test_letter_body_matches_the_word_letter_top_to_bottom(): void
    {
        $text = $this->pdfText();
        $lines = $this->lines($text);
        $flat = implode("\n", $lines);

        $this->assertContains('Date ' . now()->format('j F Y'), $lines, 'day without leading zero');

        // Addressee: one line each, "Regulatory Board" default.
        $i = array_search('The Property Practitioners Regulatory Board', $lines, true);
        $this->assertNotFalse($i);
        $this->assertSame(['63 Wierda Road East', 'Sandton', '2196'], array_slice($lines, $i + 1, 3));
        $this->assertStringNotContainsString('Regulatory Authority', $flat);

        // RE line: PPRA category in caps, never the job title.
        // (the long line wraps on the page, as it does in Word — compare on the joined text)
        $this->assertStringContainsString('RE: CONFIRMATION OF EMPLOYMENT FOR A CANDIDATE PRINCIPAL PROPERTY PRACTITIONER', preg_replace('/\s+/', ' ', $flat));
        $this->assertStringNotContainsString('FOR A CEO', $flat);

        // Firm sentence (legal t/a trading, then firm number), full first names, caps.
        $sentence = preg_replace('/\s+/', ' ', $flat);
        $this->assertStringContainsString(
            'This serves to confirm that (JOHAN HENDRIK REICHEL), ID number (7610025020081) seven digit reference number (0502216) is in the employ of (Johan and Elize Properties Pty(Ltd) t/a Home Finders Coastal) (F145200).',
            $sentence
        );

        // Mentor table.
        $this->assertContains("Mentor's details:", $lines);
        $this->assertTrue(count(array_filter($lines, fn ($l) => $l === 'Name Elizabeth Petronella')) === 1);
        $this->assertContains('Surname Reichel', $lines);
        $this->assertContains('Seven digit reference number: 0319084', $lines);

        // One line: "Yours faithfully" + "Employment accepted by"; names; titles.
        $this->assertContains('Yours faithfully Employment accepted by', $lines);
        $this->assertContains('Elizabeth Petronella Reichel Johan Hendrik Reichel', $lines);
        $this->assertContains('Principal Estate Agent Candidate Principal Property Practitioner', $lines);

        // Footer.
        $this->assertContains('Page 1 of 1', $lines);
        // Old inline header/footer is gone.
        $this->assertStringNotContainsString('PPRA Reg. No:', $flat);
    }

    public function test_agency_letterhead_component_is_rendered_and_nothing_agency_specific_is_hardcoded(): void
    {
        $this->agency->update(['address' => '12 Marine Drive, Margate', 'reg_no' => '2005/012345/07']);
        $flat = implode("\n", $this->lines($this->pdfText()));

        // The shared company-header's own contact strip, driven by the agency record.
        $this->assertStringContainsString('12 Marine Drive, Margate', $flat);
        $this->assertStringContainsString('Reg no:', $flat);
        $this->assertStringContainsString('2005/012345/07', $flat);
    }

    public function test_second_agency_without_a_distinct_trading_name_reads_naturally(): void
    {
        $other = Agency::create(['name' => 'Cape Town Rentals', 'slug' => 'ctr', 'trading_name' => null, 'ppra_number' => 'F777777']);
        $branch = Branch::create(['agency_id' => $other->id, 'name' => 'CBD']);
        $agent = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'role' => 'agent', 'name' => 'Lerato Dlamini', 'id_number' => '9001015800088', 'ffc_number' => '1111111', 'designation' => 'Property Practitioner']);
        $principal = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'role' => 'admin', 'name' => 'Pieter Smit', 'ffc_number' => '2222222', 'is_principal_practitioner' => true, 'is_active' => true]);
        $letter = app(PpraEmploymentLetterService::class)->create($agent, $agent, $principal->id);

        $path = app(PpraEmploymentLetterPdfService::class)->generate($letter, $agent, $principal, $other, null, null);
        $flat = preg_replace('/\s+/', ' ', (string) shell_exec('pdftotext -layout ' . escapeshellarg($path) . ' - 2>&1'));
        @unlink($path);

        $this->assertStringContainsString('is in the employ of (Cape Town Rentals) (F777777).', $flat);
        $this->assertStringContainsString('RE: CONFIRMATION OF EMPLOYMENT FOR A PROPERTY PRACTITIONER', $flat); // falls back to designation until a category is captured
        $this->assertStringNotContainsString('Home Finders', $flat);
        $this->assertStringContainsString('(LERATO DLAMINI)', $flat); // first-word split fallback, no full first names captured
    }

    public function test_blank_full_first_names_fall_back_to_the_current_split_and_category_beats_designation(): void
    {
        $this->assertSame('Johan Hendrik', $this->agent->letterFirstNames());
        $this->agent->update(['full_first_names' => null]);
        $this->assertSame('Johan', $this->agent->fresh()->letterFirstNames());
        $this->assertSame('Reichel', $this->agent->letterSurname());

        $this->assertSame('Candidate Principal Property Practitioner', $this->agent->ppraCategoryLabel());
        $this->agent->update(['ppra_category' => null]);
        $this->assertSame('CEO', $this->agent->fresh()->ppraCategoryLabel(), 'designation only while no category is captured');
    }

    public function test_signed_letters_on_file_are_served_from_storage_and_never_re_rendered(): void
    {
        $letter = app(PpraEmploymentLetterService::class)->create($this->agent, $this->agent, $this->principal->id);
        Storage::put('ppra-employment-letters/' . $this->agency->id . '/' . $letter->id . '-signed.pdf', '%PDF-ORIGINAL-SIGNED-BYTES');
        $letter->forceFill([
            'status' => PpraEmploymentLetter::STATUS_SIGNED,
            'signed_pdf_path' => 'ppra-employment-letters/' . $this->agency->id . '/' . $letter->id . '-signed.pdf',
        ])->save();

        $resp = $this->actingAs($this->agent)->get(route('ppra-employment-letters.download', ['letter' => $letter, 'inline' => 1]))->assertOk();
        $this->assertSame('%PDF-ORIGINAL-SIGNED-BYTES', $resp->streamedContent());
    }

    public function test_staff_form_saves_full_first_names_and_category_audits_the_change_and_rejects_unknown_categories(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        Queue::fake();
        foreach ([['agent', 'Agent'], ['super_admin', 'Super Admin']] as [$n, $l]) {
            Role::firstOrCreate(['name' => $n], ['label' => $l, 'is_owner' => false, 'can_be_deleted' => true, 'sort_order' => 1]);
        }
        Role::clearCache();

        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin']);
        $staff = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Elize Reichel', 'cell' => '0825550100']);
        $payload = fn (array $extra) => array_merge([
            'name' => 'Elize', 'surname' => 'Reichel', 'email' => $staff->email, 'cell' => '0825550100',
            'role' => 'agent', 'branch_id' => $this->branch->id,
        ], $extra);

        Event::fake([AgentPpraLetterDetailsChanged::class]);

        $this->actingAs($admin)->put(route('admin.users.update', $staff), $payload([
            'full_first_names' => 'Elizabeth Petronella', 'ppra_category' => 'Principal Property Practitioner',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $staff->refresh();
        $this->assertSame('Elizabeth Petronella', $staff->full_first_names);
        $this->assertSame('Principal Property Practitioner', $staff->ppra_category);
        Event::assertDispatched(AgentPpraLetterDetailsChanged::class, fn ($e) => $e->field === 'full_first_names' && $e->from === null && $e->to === 'Elizabeth Petronella');
        Event::assertDispatched(AgentPpraLetterDetailsChanged::class, fn ($e) => $e->field === 'ppra_category' && $e->to === 'Principal Property Practitioner');

        // Unknown category is rejected, nothing changes.
        $this->actingAs($admin)->put(route('admin.users.update', $staff), $payload(['ppra_category' => 'Head Honcho']))
            ->assertSessionHasErrors('ppra_category');
        $this->assertSame('Principal Property Practitioner', $staff->fresh()->ppra_category);

        // Blank clears (back to the fallback), and the edit screen shows both fields.
        $this->actingAs($admin)->put(route('admin.users.update', $staff), $payload(['full_first_names' => '', 'ppra_category' => '']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($staff->fresh()->full_first_names);
        $this->assertNull($staff->fresh()->ppra_category);

        $this->actingAs($admin)->get(route('admin.users.edit', $staff))->assertOk()
            ->assertSee('Full first names (as on ID)')->assertSee('PPRA category');
    }
}
