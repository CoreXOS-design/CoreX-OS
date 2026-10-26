<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * QA1 regression 2026-10-26: all four recipient signing links (Mode 2 and Mode 3, single document and pack) answered 500
 * "Attempt to read property "document" on null" for Johan. The envelopes were fine: he had a staff session switched into
 * another agency in the same browser, so AgencyScope applied that agency to the link's own template and hid it. A
 * recipient link is for someone who is not a CoreX user - it must open the same whoever else is logged in.
 */
final class SigningLinkOpensWithStaffSessionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    private function seller(string $mode, bool $pack): SignatureRequest
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);
        $session['signatureTemplate']->update(['signing_mode' => $mode]);
        $r = $this->recipient($session['recipients'], 'seller', 1)->fresh();

        if ($pack) {
            $a = DocuperfectTemplate::create(['name' => 'Sole Mandate', 'render_type' => 'web', 'blade_view' => 'x.y', 'template_type' => 'cds', 'category' => 'sales', 'owner_id' => $session['creator']->id]);
            $b = DocuperfectTemplate::create(['name' => 'Disclosure Form', 'render_type' => 'web', 'blade_view' => 'x.y', 'template_type' => 'cds', 'category' => 'sales', 'owner_id' => $session['creator']->id]);
            $marker = '<p data-marker-party="seller" data-marker-type="signature" data-recipient-identity="' . $r->attestationIdentity() . '">x</p>';
            $document = $session['document'];
            $data = $document->web_template_data ?? [];
            $data['canonical_html'] = '<div class="corex-document-wrapper">' . $marker . '</div><div class="corex-document-wrapper">' . $marker . '</div>';
            $data['template_ids'] = [$a->id, $b->id];
            $document->update(['web_template_data' => $data]);
        }

        return $r->fresh();
    }

    /** A staff user of a DIFFERENT agency, signed in with that agency switched on - exactly Johan's browser. */
    private function staffOfAnotherAgency(): User
    {
        $other = Agency::create(['name' => 'Another Agency', 'slug' => 'other-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $other->id, 'name' => 'Head Office']);

        $staff = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
        $this->actingAs($staff)->withSession(['active_agency_id' => $other->id]);

        return $staff;
    }

    public static function linkKinds(): array
    {
        return [
            'Mode 2, single document' => ['adopt_then_stamp', false],
            'Mode 3, single document' => ['adopt_and_fill_all', false],
            'Mode 2, pack'            => ['adopt_then_stamp', true],
            'Mode 3, pack'            => ['adopt_and_fill_all', true],
        ];
    }

    #[DataProvider('linkKinds')]
    public function test_the_link_opens_for_a_visitor_with_no_session(string $mode, bool $pack): void
    {
        $r = $this->seller($mode, $pack);

        $this->asRecipient($r)->assertOk()->assertSee('Sign Document', false);
    }

    #[DataProvider('linkKinds')]
    public function test_the_link_opens_the_same_when_a_staff_session_of_another_agency_is_open_in_the_browser(string $mode, bool $pack): void
    {
        $r = $this->seller($mode, $pack);
        $this->staffOfAnotherAgency();

        $this->asRecipient($r)->assertOk()->assertSee('Sign Document', false);
    }

    public function test_the_staff_session_is_untouched_by_opening_a_link(): void
    {
        $r = $this->seller('adopt_then_stamp', false);
        $staff = $this->staffOfAnotherAgency();

        $this->asRecipient($r)->assertOk();

        $this->assertAuthenticatedAs($staff); // the link ran as a visitor, but the browser's staff login is still there afterwards
    }
}
