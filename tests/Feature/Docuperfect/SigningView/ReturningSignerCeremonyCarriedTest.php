<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * AT-373 BUG 2 — a RETURNING signer's captured ceremony fields (place / date / time) must be carried
 * forward into the changes-signing round. When rec 1 (Anine) moves from her full initial signing to the
 * re-initial cascade (template status = amendment_initialing) her token is re-activated (PENDING) and the
 * serve path re-renders her ceremony fields as fresh editable inputs — so her captured LOCATION showed
 * BLANK. The save path (completeWeb) and the PDF render already re-apply ceremony_values onto the document;
 * the serve path (show) did not. This test proves show() now paints the accumulated ceremony_values back
 * onto the served document for a returning signer, so her location is no longer dropped.
 */
final class ReturningSignerCeremonyCarriedTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    /** @return array{tpl:SignatureTemplate, seller:SignatureRequest} */
    private function seedReturningSigner(string $templateStatus, string $requestStatus): array
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Listing Agent', 'email' => 'la-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Ceremony tmpl', 'render_type' => 'web', 'template_type' => 'cds', 'category' => 'sales',
            'blade_view' => 'x', 'signing_parties' => ['agent', 'seller'], 'field_mappings' => [], 'owner_id' => $uid,
        ]);

        // A baked canonical (version 1, served verbatim) whose seller ceremony LOCATION span is still the
        // blank placeholder — the value lives only in ceremony_values and must be painted on at serve time.
        $canonical = '<div class="corex-document-wrapper"><p class="corex-clause">The fee is 5%.</p>'
            . '<p>Signed at <span data-marker-party="seller" data-marker-type="location">__________</span> '
            . 'by the Seller.</p></div>';

        $doc = Document::create([
            'name' => 'EATS - ceremony carry', 'document_type' => 'mandate',
            'owner_id' => $uid, 'template_id' => $docTmpl->id,
            'web_template_data' => [
                'merged_html'       => $canonical,
                'canonical_html'    => $canonical,
                'canonical_version' => 1,
                'ceremony_values'   => ['seller_location' => 'Margate Town Hall'],
            ],
        ]);
        $tpl = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => $templateStatus, 'created_by' => $uid,
            'parties_json' => [
                ['role' => 'agent', 'role_index' => 1, 'role_label' => 'agent'],
                ['role' => 'seller', 'role_index' => 1, 'role_label' => 'seller'],
            ],
        ]);
        SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => 'Listing Agent', 'signer_email' => 'a@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'signing_order' => 1,
        ]);
        // The re-initial signer: re-activated PENDING for the changes round (activateInitialingParty).
        $seller = SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'seller', 'role_index' => 1,
            'signer_name' => 'Anine Van der Westhuizen', 'signer_email' => 's@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => $requestStatus, 'signing_order' => 2,
        ]);

        return ['tpl' => $tpl, 'seller' => $seller];
    }

    /** In the re-initial cascade (amendment_initialing) the returning signer sees her carried location. */
    public function test_returning_signer_in_amendment_initialing_sees_carried_location(): void
    {
        ['seller' => $seller] = $this->seedReturningSigner(
            SignatureTemplate::STATUS_AMENDMENT_INITIALING,
            SignatureRequest::STATUS_PENDING,
        );

        $response = $this->get('/sign/' . $seller->token);
        $response->assertStatus(200);

        $body = $this->extractRenderedDocumentHtml($response);
        $this->assertStringContainsString('Margate Town Hall', $body,
            'the returning signer\'s captured location must be carried into the changes-signing round, not dropped');
    }

    /**
     * Control — a fresh FIRST-time signing (status signing, not a returning signer, no ceremony_values yet)
     * still renders fine and is untouched by the carry (the first-time recipient view cc6 owns is unaffected).
     */
    public function test_first_time_signing_still_renders_without_the_carry(): void
    {
        // Same doc shape but WITHOUT ceremony_values and in the normal signing state.
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Listing Agent', 'email' => 'la-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Ceremony tmpl', 'render_type' => 'web', 'template_type' => 'cds', 'category' => 'sales',
            'blade_view' => 'x', 'signing_parties' => ['agent', 'seller'], 'field_mappings' => [], 'owner_id' => $uid,
        ]);
        $canonical = '<div class="corex-document-wrapper"><p class="corex-clause">The fee is 5%.</p>'
            . '<p>Signed at <span data-marker-party="seller" data-marker-type="location">__________</span> '
            . 'by the Seller.</p></div>';
        $doc = Document::create([
            'name' => 'EATS - fresh', 'document_type' => 'mandate', 'owner_id' => $uid, 'template_id' => $docTmpl->id,
            'web_template_data' => ['merged_html' => $canonical, 'canonical_html' => $canonical, 'canonical_version' => 1],
        ]);
        $tpl = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_SIGNING, 'created_by' => $uid,
            'parties_json' => [['role' => 'seller', 'role_index' => 1, 'role_label' => 'seller']],
        ]);
        $seller = SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'seller', 'role_index' => 1,
            'signer_name' => 'Anine Van der Westhuizen', 'signer_email' => 's@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => SignatureRequest::STATUS_PENDING, 'signing_order' => 1,
        ]);

        $this->get('/sign/' . $seller->token)->assertStatus(200);
    }

    /**
     * BUG 2 (persistence) — a returning signer's re-submit must NEVER clobber the Location captured at their
     * initial signing. The changes-only round shows an EMPTY location input; if that emptied field reaches
     * the payload as seller_location='', completeWeb's merge must PRESERVE the stored value (blank-safe),
     * while a genuinely new/changed non-blank value still writes through.
     */
    public function test_blank_resubmit_does_not_clobber_captured_ceremony_value(): void
    {
        $controller = app(\App\Http\Controllers\Docuperfect\SigningController::class);
        $m = new \ReflectionMethod($controller, 'mergeCeremonyValues');
        $m->setAccessible(true);

        $existing = ['seller_location' => 'Margate Town Hall', 'seller_day' => '14', 'agent_location' => 'Head Office'];

        // A re-submit that posts a BLANK Location (emptied input) + an unrelated blank must keep the captured values.
        $afterBlank = $m->invoke($controller, $existing, ['seller_location' => '', 'agent_location' => '   ']);
        $this->assertSame('Margate Town Hall', $afterBlank['seller_location'], 'a blank re-submit must NOT wipe the captured location');
        $this->assertSame('Head Office', $afterBlank['agent_location'], 'whitespace-only is treated as blank and must not clobber');

        // A genuinely new value still writes; a brand-new key is added; a first-time blank (no stored value) is allowed.
        $afterReal = $m->invoke($controller, $existing, ['seller_location' => 'Shelly Beach', 'seller_time' => '10:30', 'seller_2_location' => '']);
        $this->assertSame('Shelly Beach', $afterReal['seller_location'], 'a non-blank value overwrites');
        $this->assertSame('10:30', $afterReal['seller_time'], 'a new ceremony key is added');
        $this->assertArrayHasKey('seller_2_location', $afterReal, 'a first-time (no stored value) blank is still set');
    }
}
