<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan/cc3, 2026-08-30 — the signing certificate (SignatureTemplate::
 * partyProgress(), rendered by audit-certificate.blade.php) reads a party's
 * name/email from parties_json, not the live SignatureRequest. Every OTHER
 * role gets name/email written to both at the same time it's known. The
 * candidate-flow authoriser is the one exception: their identity is
 * genuinely unknown at document-creation time (shared queue -- see
 * CandidateAuthoriserSurfaceInjector's own docblock), so parties_json is
 * seeded with a placeholder ("Authorised Practitioner") that
 * authoriseSigning() -- the endpoint that lets a full-status practitioner
 * CLAIM the queued item -- updated on the live SignatureRequest but never
 * synced back to parties_json. The certificate kept showing the
 * placeholder even after a real person had claimed and signed.
 *
 * Fix mirrors the exact pattern SignatureService::resumeDeferredSigning()
 * already uses for a deferred ordinary party: write the claimant's
 * name/email into parties_json at the same point the live request is
 * updated, so partyProgress() (and therefore the certificate) is correct
 * without needing a special-case reader.
 */
final class AuthoriserCertificateNameSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_claiming_the_authoriser_queue_item_syncs_parties_json_name_and_email(): void
    {
        [$template, $authoriser] = $this->seedAwaitingSupervisor();

        $this->assertSame(
            'Authorised Practitioner',
            collect($template->parties_json)->firstWhere('role', 'supervisor')['name'],
            'sanity check: the placeholder must be present before the claim'
        );

        $this->actingAs($authoriser)
            ->get(route('docuperfect.signatures.authoriseSigning', $template->document))
            ->assertRedirect();

        $template->refresh();
        $supervisorParty = collect($template->parties_json)->firstWhere('role', 'supervisor');

        $this->assertSame($authoriser->name, $supervisorParty['name'], 'certificate must show the real authoriser, not the placeholder');
        $this->assertSame($authoriser->email, $supervisorParty['email']);

        // The live SignatureRequest was already correct before this fix -- confirm it still is.
        $supReq = $template->requests()->where('party_role', 'supervisor')->first();
        $this->assertSame($authoriser->name, $supReq->signer_name);
        $this->assertSame($authoriser->email, $supReq->signer_email);
    }

    /** Regression guard: an ordinary party's parties_json entry must be completely untouched by this endpoint. */
    public function test_claiming_the_authoriser_queue_item_does_not_touch_other_parties_json_entries(): void
    {
        [$template, $authoriser] = $this->seedAwaitingSupervisor();

        $this->actingAs($authoriser)->get(route('docuperfect.signatures.authoriseSigning', $template->document));

        $template->refresh();
        $agentParty = collect($template->parties_json)->firstWhere('role', 'agent');
        $this->assertSame('Junior Candidate', $agentParty['name']);
        $this->assertSame('jnr@x.test', $agentParty['email']);
    }

    /**
     * @return array{0: SignatureTemplate, 1: User}
     */
    private function seedAwaitingSupervisor(): array
    {
        $agency = \App\Models\Agency::create(['name' => 'Cert Sync Test Agency', 'slug' => 'cert-sync-' . Str::random(8)]);
        $branch = \App\Models\Branch::create(['agency_id' => $agency->id, 'name' => 'Test Branch']);

        $juniorId = (int) DB::table('users')->insertGetId([
            'name' => 'Junior Candidate', 'email' => 'jnr-' . Str::random(6) . '@hfc.test',
            'password' => bcrypt('p'), 'role' => 'agent', 'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $authoriser = User::create([
            'name' => 'Real Authoriser', 'email' => 'authoriser@x.test',
            'password' => bcrypt('p'), 'role' => 'agent', 'agency_id' => $agency->id, 'branch_id' => $branch->id,
        ]);

        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Candidate mandate', 'render_type' => 'web', 'template_type' => 'cds',
            'category' => 'rentals', 'signing_parties' => ['agent', 'owner_party'],
            'field_mappings' => [], 'owner_id' => $juniorId,
        ]);
        $doc = Document::create([
            'name' => 'Candidate Doc', 'document_type' => 'mandate',
            'owner_id' => $authoriser->id, // simplest path through authorizeDocument()'s 'own' scope
            'template_id' => $docTmpl->id,
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'web_template_data' => ['merged_html' => '<div>body</div>', 'canonical_version' => 0],
        ]);
        $template = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_AWAITING_SUPERVISOR,
            'created_by' => $juniorId, 'is_candidate_flow' => true,
            'agency_id' => $agency->id,
            'parties_json' => [
                ['name' => 'Junior Candidate', 'role' => 'agent', 'email' => 'jnr@x.test', 'id_number' => '', 'role_label' => 'agent'],
                ['name' => 'Authorised Practitioner', 'role' => 'supervisor', 'email' => '', 'id_number' => '', 'role_label' => 'supervisor'],
            ],
        ]);

        SignatureRequest::create([
            'signature_template_id' => $template->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => 'Junior Candidate', 'signer_email' => 'jnr@x.test',
            'token' => Str::random(48), 'token_expires_at' => now()->addDays(30), 'status' => SignatureRequest::STATUS_COMPLETED,
            'completed_at' => now(), 'signing_order' => 1,
        ]);
        SignatureRequest::create([
            'signature_template_id' => $template->id, 'party_role' => 'supervisor', 'role_index' => 1,
            'signer_name' => 'Authorised Practitioner', 'signer_email' => '',
            'token' => Str::random(48), 'token_expires_at' => now()->addDays(30), 'status' => SignatureRequest::STATUS_WAITING,
            'signing_order' => 2,
        ]);

        return [$template, $authoriser];
    }
}
