<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Agency;
use App\Models\Docuperfect\EsignSettings;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * .ai/specs/esign-signing-modes.md Build step 1 — the per-agency signing mode, default sign_each, snapshotted onto
 * each envelope when it is created.
 */
final class SigningModeSettingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    private function admin(Agency $agency): User
    {
        return User::factory()->create(['agency_id' => $agency->id, 'role' => 'admin', 'is_active' => true]);
    }

    private function agency(string $name): Agency
    {
        return Agency::create(['name' => $name, 'slug' => strtolower($name) . '-' . uniqid()]);
    }

    public function test_an_agency_that_never_saved_the_setting_signs_every_place(): void
    {
        $agency = $this->agency('Alpha');

        $this->assertSame('sign_each', EsignSettings::signingModeFor($agency->id));
        $this->assertSame('sign_each', EsignSettings::signingModeFor(null));
        $this->assertSame('sign_each', EsignSettings::signingModeFor(0));
        $this->assertSame('sign_each', EsignSettings::forAgency($agency->id)->signing_mode);
    }

    public function test_the_screen_shows_the_current_mode_and_saves_a_change(): void
    {
        $agency = $this->agency('Beta');
        $user = $this->admin($agency);

        $this->actingAs($user)->get(route('docuperfect.esign.settings.signing-mode'))
            ->assertOk()
            ->assertSee('How my signers sign')
            ->assertSee('Adopt once, then click to stamp');

        $this->actingAs($user)->post(route('docuperfect.esign.settings.signing-mode.update'), ['signing_mode' => 'adopt_then_stamp'])
            ->assertRedirect(route('docuperfect.esign.settings.signing-mode'));

        $this->assertSame('adopt_then_stamp', EsignSettings::signingModeFor($agency->id));
    }

    public function test_an_unknown_mode_is_refused_and_nothing_is_saved(): void
    {
        $agency = $this->agency('Gamma');
        $user = $this->admin($agency);

        $this->actingAs($user)->post(route('docuperfect.esign.settings.signing-mode.update'), ['signing_mode' => 'sign_nothing'])
            ->assertSessionHasErrors('signing_mode');

        $this->assertSame('sign_each', EsignSettings::signingModeFor($agency->id));
    }

    public function test_one_agencys_choice_never_reaches_another(): void
    {
        $mega = $this->agency('Mega');
        $other = $this->agency('Other');
        $this->actingAs($this->admin($mega))->post(route('docuperfect.esign.settings.signing-mode.update'), ['signing_mode' => 'adopt_and_fill_all']);

        $this->assertSame('adopt_and_fill_all', EsignSettings::signingModeFor($mega->id));
        $this->assertSame('sign_each', EsignSettings::signingModeFor($other->id));
    }

    public function test_a_new_envelope_takes_the_agencys_mode_and_keeps_it_when_the_setting_changes(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);
        $agencyId = (int) $session['signatureTemplate']->agency_id;
        $this->assertGreaterThan(0, $agencyId);

        // Built before anything was chosen: sign_each.
        $this->assertSame('sign_each', $session['signatureTemplate']->fresh()->signing_mode);

        EsignSettings::updateOrCreate(['agency_id' => $agencyId], ['signing_mode' => 'adopt_then_stamp']);

        $newEnvelope = SignatureTemplate::create([
            'document_id' => $session['document']->id,
            'status' => SignatureTemplate::STATUS_DRAFT,
            'created_by' => $session['creator']->id,
            'agency_id' => $agencyId,
        ]);
        $this->assertSame('adopt_then_stamp', $newEnvelope->fresh()->signing_mode, 'a new envelope is created in the agency mode');

        // The agency changes its mind: the envelope already created is NOT switched.
        EsignSettings::updateOrCreate(['agency_id' => $agencyId], ['signing_mode' => 'adopt_and_fill_all']);
        $this->assertSame('adopt_then_stamp', $newEnvelope->fresh()->signing_mode);
        $this->assertSame('sign_each', $session['signatureTemplate']->fresh()->signing_mode);
    }
}
