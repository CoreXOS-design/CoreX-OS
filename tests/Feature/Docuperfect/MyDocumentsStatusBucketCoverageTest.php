<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use ReflectionClass;
use Tests\TestCase;

/**
 * AT-445 (Johan, 2026-10-05) — "My E-Sign Documents" groups every SignatureTemplate the agent
 * created into named $groups buckets by status. Four times now a real status had no bucket
 * (AT-299 flagged, BUG-2 returned, AT-373 amendment_chain_review, and the expired-link-TTL
 * document a Staging investigation found: Maggie Venter's "16 Natspat" mandate, template #76,
 * status=expired, vanished from the list even though nothing was ever deleted) — fixing the
 * CLASS, not another one-off instance: myDocuments() now adds an 'expired' bucket AND a final
 * 'other' catch-all for any status this page hasn't been taught a named bucket for yet.
 *
 * This test iterates every SignatureTemplate::STATUS_* constant and asserts each one lands in
 * SOME rendered $groups entry — so a future unbucketed status degrades to the visible "Other"
 * section instead of silently disappearing again.
 */
final class MyDocumentsStatusBucketCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function agencyAgent(): User
    {
        $agency = Agency::create(['name' => 'Bucket Coverage Agency', 'slug' => 'bucket-cov-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Bucket Branch']);

        RolePermission::updateOrCreate(
            ['role' => 'agent', 'permission_key' => 'access_docuperfect', 'agency_id' => $agency->id],
            []
        );

        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'agent',
            'is_active' => true,
        ]);
    }

    /** Every SignatureTemplate::STATUS_* constant value, in declaration order. */
    private function allStatusValues(): array
    {
        $constants = (new ReflectionClass(SignatureTemplate::class))->getConstants();
        $statuses = [];
        foreach ($constants as $name => $value) {
            if (str_starts_with($name, 'STATUS_')) {
                $statuses[] = $value;
            }
        }
        return $statuses;
    }

    private function templateWithStatus(User $user, string $status): SignatureTemplate
    {
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Coverage tmpl ' . $status, 'render_type' => 'web', 'template_type' => 'cds',
            'category' => 'sales', 'signing_parties' => ['agent', 'seller'], 'field_mappings' => [],
            'owner_id' => $user->id,
        ]);
        $doc = Document::create([
            'name' => 'Coverage doc — ' . $status,
            'document_type' => 'mandate',
            'owner_id' => $user->id,
            'template_id' => $docTmpl->id,
            'property_address' => '1 Coverage Street',
            'web_template_data' => ['merged_html' => '<div class="corex-document-wrapper"></div>'],
        ]);

        return SignatureTemplate::create([
            'document_id' => $doc->id,
            'document_hash' => bin2hex(random_bytes(16)),
            'status' => $status,
            'created_by' => $user->id,
        ]);
    }

    public function test_every_known_status_lands_in_a_rendered_bucket(): void
    {
        $user = $this->agencyAgent();
        $this->actingAs($user);

        $statuses = $this->allStatusValues();
        $this->assertNotEmpty($statuses, 'precondition: SignatureTemplate declares at least one STATUS_* constant');

        $templatesByStatus = [];
        foreach ($statuses as $status) {
            $templatesByStatus[$status] = $this->templateWithStatus($user, $status);
        }

        $request = Request::create('/docuperfect/esign/my-documents', 'GET');
        $request->setUserResolver(fn () => $user);
        $view = app(ESignWizardController::class)->myDocuments($request);
        $groups = $view->getData()['groups'];

        foreach ($templatesByStatus as $status => $tpl) {
            $foundIn = null;
            foreach ($groups as $bucketName => $bucket) {
                if ($bucket->contains(fn ($t) => (int) $t->id === (int) $tpl->id)) {
                    $foundIn = $bucketName;
                    break;
                }
            }
            $this->assertNotNull(
                $foundIn,
                "status '{$status}' (template #{$tpl->id}) must appear in some rendered bucket — it vanished from every group"
            );
        }
    }

    public function test_expired_status_lands_specifically_in_the_expired_bucket(): void
    {
        $user = $this->agencyAgent();
        $this->actingAs($user);
        $tpl = $this->templateWithStatus($user, SignatureTemplate::STATUS_EXPIRED);

        $request = Request::create('/docuperfect/esign/my-documents', 'GET');
        $request->setUserResolver(fn () => $user);
        $view = app(ESignWizardController::class)->myDocuments($request);
        $data = $view->getData();

        $this->assertTrue(
            $data['groups']['expired']->contains(fn ($t) => (int) $t->id === (int) $tpl->id),
            'the expired document appears in the dedicated expired bucket, not the catch-all'
        );
        $this->assertSame(1, $data['counts']['expired']);
        $this->assertFalse($data['groups']['other']->contains(fn ($t) => (int) $t->id === (int) $tpl->id));
    }

    public function test_a_status_with_no_named_bucket_falls_into_other_not_nowhere(): void
    {
        $user = $this->agencyAgent();
        $this->actingAs($user);
        // STATUS_LAPSED has no dedicated bucket as of this fix — must land in the catch-all.
        $tpl = $this->templateWithStatus($user, SignatureTemplate::STATUS_LAPSED);

        $request = Request::create('/docuperfect/esign/my-documents', 'GET');
        $request->setUserResolver(fn () => $user);
        $view = app(ESignWizardController::class)->myDocuments($request);
        $data = $view->getData();

        $this->assertTrue(
            $data['groups']['other']->contains(fn ($t) => (int) $t->id === (int) $tpl->id),
            'an unbucketed status (lapsed) is caught by the "other" fallback'
        );
        $this->assertSame(1, $data['counts']['other']);
    }
}
