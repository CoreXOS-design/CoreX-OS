<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Agency;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template;
use App\Models\SalesDocumentSend;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-08-15 (Johan, HFC tenant-isolation fix, Wave 2, #7) — locks in the
 * BelongsToAgency fix on the DocuPerfect document subsystem.
 *
 * These tests target the ROOT mechanism (AgencyScope, applied via
 * BelongsToAgency) rather than re-deriving scopeVisibleTo()'s permission
 * plumbing — once a plain Document::find($id) is proven agency-scoped for
 * an authenticated user, every caller built on top of it (guardDocument(),
 * PageImageController::showDocumentPage(), ESignWizardController::
 * needsAuthorisation, scopeVisibleTo()'s 'all' branch) is safe by
 * construction, exactly as the class docblocks on each model explain.
 *
 * Template is tested separately for its is_global-aware exception — it
 * deliberately does NOT use BelongsToAgency (see its class docblock).
 */
class DocumentAgencyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function agency(string $name): Agency
    {
        return Model::withoutEvents(fn () => Agency::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid()]));
    }

    private function user(Agency $agency): User
    {
        return User::factory()->create(['agency_id' => $agency->id]);
    }

    public function test_document_find_is_agency_scoped(): void
    {
        $agencyA = $this->agency('Agency A');
        $agencyB = $this->agency('Agency B');
        $userA = $this->user($agencyA);
        $userB = $this->user($agencyB);

        $template = Model::withoutEvents(fn () => Template::create(['name' => 'T', 'owner_id' => $userA->id]));

        $this->actingAs($userA);
        $docA = Document::create(['name' => 'Doc A', 'template_id' => $template->id, 'owner_id' => $userA->id]);
        $this->assertSame($agencyA->id, $docA->agency_id, 'creating() auto-stamps the acting user\'s agency');

        // Agent A can see their own document.
        $this->assertNotNull(Document::find($docA->id));

        // Agent B cannot see it at all — a plain find(), not just a
        // permission-scoped query, correctly returns null.
        $this->actingAs($userB);
        $this->assertNull(Document::find($docA->id), 'AgencyScope must hide another agency\'s document from a plain find()');
    }

    public function test_signature_template_is_agency_scoped(): void
    {
        $agencyA = $this->agency('Agency A');
        $agencyB = $this->agency('Agency B');
        $userA = $this->user($agencyA);
        $userB = $this->user($agencyB);

        $template = Model::withoutEvents(fn () => Template::create(['name' => 'T', 'owner_id' => $userA->id]));
        $this->actingAs($userA);
        $doc = Document::create(['name' => 'Doc', 'template_id' => $template->id, 'owner_id' => $userA->id]);
        $sig = SignatureTemplate::create(['document_id' => $doc->id, 'created_by' => $userA->id]);
        $this->assertSame($agencyA->id, $sig->agency_id);

        $this->actingAs($userB);
        $this->assertNull(SignatureTemplate::find($sig->id));
    }

    public function test_sales_document_send_is_agency_scoped(): void
    {
        $agencyA = $this->agency('Agency A');
        $agencyB = $this->agency('Agency B');
        $userA = $this->user($agencyA);
        $userB = $this->user($agencyB);

        $this->actingAs($userA);
        $send = SalesDocumentSend::create(['document_name' => 'Doc.pdf', 'sent_by' => $userA->id]);
        $this->assertSame($agencyA->id, $send->agency_id);

        $this->actingAs($userB);
        $this->assertNull(SalesDocumentSend::find($send->id));
    }

    public function test_template_agency_owned_is_hidden_cross_agency(): void
    {
        $agencyA = $this->agency('Agency A');
        $userA = $this->user($agencyA);
        $userB = $this->user($this->agency('Agency B'));

        $template = Model::withoutEvents(fn () => Template::create([
            'name' => 'Agency A custom template', 'owner_id' => $userA->id,
            'agency_id' => $agencyA->id, 'is_global' => false,
        ]));

        $this->assertFalse($template->isVisibleToAgency($userB->agency_id), 'a non-global template owned by another agency must not be visible');
        $this->assertTrue($template->isVisibleToAgency($userA->id ? $agencyA->id : null));
    }

    public function test_template_is_global_stays_visible_to_every_agency(): void
    {
        $agencyA = $this->agency('Agency A');
        $agencyB = $this->agency('Agency B');

        $template = Model::withoutEvents(fn () => Template::create([
            'name' => 'Shared CoreX template', 'agency_id' => null, 'is_global' => true,
        ]));

        $this->assertTrue($template->isVisibleToAgency($agencyA->id));
        $this->assertTrue($template->isVisibleToAgency($agencyB->id));
        $this->assertTrue($template->isVisibleToAgency(null), 'is_global must survive even with no agency context');
    }

    /**
     * 2026-09-01 (Johan) — the shape that actually leaked, and the one neither
     * test above covered: is_global=true on a template that IS owned by an
     * agency.
     *
     * The two tests above pin the ends of the range — a non-global agency
     * template (hidden) and an ownerless global (shared) — and both passed
     * throughout. Nothing exercised the middle, so `is_global` was free to be
     * read as "the whole platform" regardless of agency_id. On production two
     * of HFC's own templates carry that exact combination, and every other
     * agency on CoreX was shown them on /docuperfect/create and
     * /docuperfect/templates.
     *
     * The rule this pins: `is_global` widens across BRANCHES, never across
     * AGENCIES. Only agency_id IS NULL crosses an agency boundary.
     */
    public function test_agency_owned_global_template_never_leaks_to_another_agency(): void
    {
        $agencyA = $this->agency('Agency A');
        $agencyB = $this->agency('Agency B');
        $userA = $this->user($agencyA);

        $ownedGlobal = Model::withoutEvents(fn () => Template::create([
            'name' => 'Agency A firm-wide template', 'owner_id' => $userA->id,
            'agency_id' => $agencyA->id, 'is_global' => true, 'page_count' => 1,
        ]));
        $platformGlobal = Model::withoutEvents(fn () => Template::create([
            'name' => 'CoreX platform template', 'agency_id' => null,
            'is_global' => true, 'page_count' => 1,
        ]));

        // The direct-open guard (TemplateController::webPreview, assertAccessibleBy).
        $this->assertTrue(
            $ownedGlobal->isVisibleToAgency($agencyA->id),
            'its own agency must keep seeing it — is_global still means "all my branches"'
        );
        $this->assertFalse(
            $ownedGlobal->isVisibleToAgency($agencyB->id),
            'is_global must NOT carry an agency-owned template across an agency boundary'
        );
        $this->assertFalse(
            $ownedGlobal->isVisibleToAgency(null),
            'a platform user with no agency has no claim on an agency-owned template'
        );

        // The listing query — the one definition every visibility path shares.
        $visibleToB = Template::query()
            ->where(fn ($q) => Template::applySharedWith($q, $agencyB->id))
            ->pluck('id');

        $this->assertNotContains($ownedGlobal->id, $visibleToB->all(), 'agency A\'s global template must not list for agency B');
        $this->assertContains($platformGlobal->id, $visibleToB->all(), 'a genuinely ownerless global template must still list for everyone');

        $visibleToA = Template::query()
            ->where(fn ($q) => Template::applySharedWith($q, $agencyA->id))
            ->pluck('id');

        $this->assertContains($ownedGlobal->id, $visibleToA->all(), 'agency A must not lose its own firm-wide template');
        $this->assertContains($platformGlobal->id, $visibleToA->all());
    }
}
