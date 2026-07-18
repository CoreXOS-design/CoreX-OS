<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Contact;
use App\Models\Docuperfect\SignatureRequest;
use App\Services\Docuperfect\RoleBlockExpansionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-292 — couple's-mandate seller identity-drop.
 *
 * On the contract render path, per-recipient prefill re-sources identity fields
 * from the linked Contact. The pre-fix code `(string)`-cast an empty Contact
 * column to '' and overwrote the value the wizard baked into merged_html — so a
 * couple's second seller (commonly matched to an EXISTING Contact with a blank
 * id_number) rendered WITHOUT their ID. Fixes:
 *   A) resolveContactValue returns null (not '') for an empty column → the
 *      caller's `!== null` guard preserves the baked span.
 *   B) wizard fill-if-blank backfill (ESignWizardController — separate test path).
 *   C) `seller_cell` fields now re-source phone (missing switch case).
 *   + id_number fallback to SignatureRequest.signer_id_number on the block path.
 */
final class SellerIdentityPreservationTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test Agency', 'slug' => 'test-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Test Branch',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeContact(array $attrs): Contact
    {
        return Contact::create(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
        ], $attrs));
    }

    private function seller(int $idx, string $name, ?int $contactId, ?string $signerId = null): SignatureRequest
    {
        $r = new SignatureRequest();
        $r->party_role = 'seller';
        $r->role_index = $idx;
        $r->signer_name = $name;
        $r->contact_id = $contactId;
        $r->signer_id_number = $signerId;
        return $r;
    }

    /** A) The wizard-baked ID must SURVIVE when the linked Contact has no id_number. */
    public function test_baked_id_survives_when_contact_id_number_empty(): void
    {
        $contact = $this->makeContact([
            'first_name' => 'Thandeka', 'last_name' => 'Zulu',
            'email' => 'thandeka@x.test', 'phone' => '', 'id_number' => '', 'address' => '',
        ]);
        $html = '<div data-role-block="seller"><span data-field="seller_id_number">8801015800088</span></div>';

        $out = app(RoleBlockExpansionService::class)->expandWithLooping(
            null, $html, collect([$this->seller(1, 'Thandeka Zulu', $contact->id)]),
        );

        $this->assertStringContainsString('8801015800088', $out, 'Baked ID must not be wiped by an empty Contact column.');
    }

    /** id_number fallback — Contact blank AND span blank, but the signer typed an ID. */
    public function test_blank_span_prefills_from_signer_id_number_fallback(): void
    {
        $contact = $this->makeContact([
            'first_name' => 'Bongi', 'last_name' => 'Buyer',
            'email' => 'bongi@x.test', 'phone' => '', 'id_number' => '', 'address' => '',
        ]);
        $html = '<div data-role-block="seller"><span data-field="seller_id_number"></span></div>';

        $out = app(RoleBlockExpansionService::class)->expandWithLooping(
            null, $html, collect([$this->seller(1, 'Bongi Buyer', $contact->id, '9002026600087')]),
        );

        $this->assertStringContainsString('9002026600087', $out, 'Blank span must fill from SignatureRequest.signer_id_number.');
    }

    /** C) A `seller_cell` field re-sources the Contact phone (previously unmapped). */
    public function test_seller_cell_field_resolves_contact_phone(): void
    {
        $contact = $this->makeContact([
            'first_name' => 'Sipho', 'last_name' => 'Seller',
            'email' => 'sipho@x.test', 'phone' => '0821234567', 'id_number' => '7001015000081', 'address' => '',
        ]);
        $html = '<div data-role-block="seller"><span data-field="seller_cell">placeholder</span></div>';

        $out = app(RoleBlockExpansionService::class)->expandWithLooping(
            null, $html, collect([$this->seller(1, 'Sipho Seller', $contact->id)]),
        );

        $this->assertStringContainsString('0821234567', $out, 'seller_cell must re-source contact->phone.');
    }

    /** A/C unit — resolveContactValue returns null for empty columns and maps `cell`. */
    public function test_resolve_contact_value_blank_to_null_and_cell_case(): void
    {
        $svc = app(RoleBlockExpansionService::class);
        $m = new \ReflectionMethod(RoleBlockExpansionService::class, 'resolveContactValue');
        $m->setAccessible(true);

        $blank = new Contact(['first_name' => 'A', 'last_name' => 'B', 'id_number' => '', 'phone' => '']);
        $this->assertNull($m->invoke($svc, $blank, 'id_number'), 'empty id_number → null (preserves baked span)');
        $this->assertNull($m->invoke($svc, $blank, 'phone'), 'empty phone → null');

        $full = new Contact(['first_name' => 'A', 'last_name' => 'B', 'id_number' => '8801015800088', 'phone' => '0821234567']);
        $this->assertSame('8801015800088', $m->invoke($svc, $full, 'id_number'));
        $this->assertSame('0821234567', $m->invoke($svc, $full, 'cell'), '`cell` sub-name now maps to phone');
        $this->assertSame('0821234567', $m->invoke($svc, $full, 'phone'));
    }

    /** B) Wizard backfill helper fills a blank Contact id_number, never overwrites. */
    public function test_wizard_backfill_fills_blank_never_overwrites(): void
    {
        $blank = $this->makeContact(['first_name' => 'C', 'last_name' => 'D', 'id_number' => '', 'email' => 'c@x.test']);
        $set   = $this->makeContact(['first_name' => 'E', 'last_name' => 'F', 'id_number' => '6501015000080', 'email' => 'e@x.test']);

        $ctl = app(\App\Http\Controllers\Docuperfect\ESignWizardController::class);
        $m = new \ReflectionMethod(\App\Http\Controllers\Docuperfect\ESignWizardController::class, 'backfillContactIdNumber');
        $m->setAccessible(true);

        $m->invoke($ctl, $blank->id, '9002026600087');
        $m->invoke($ctl, $set->id, '0000000000000');

        $this->assertSame('9002026600087', $blank->fresh()->id_number, 'blank id_number filled from typed value');
        $this->assertSame('6501015000080', $set->fresh()->id_number, 'existing id_number never overwritten');
    }
}
