<?php

declare(strict_types=1);

namespace Tests\Feature\RecipientLoop;

use App\Models\Contact;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\Docuperfect\RoleBlockDetectionService;
use App\Services\Docuperfect\RoleBlockExpansionService;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Recipient Loop Engine — B2 role-block loop renderer.
 *
 * Detection: parses field names to recover role-base + instance-index.
 * Expansion: stamps data-recipient-identity + data-role-token on each
 * data-field tag in the rendered HTML body. Marks orphan fields (idx >
 * recipient count) so downstream code can hide/no-op them.
 */
final class BlockRendererTest extends TestCase
{
    use RefreshDatabase;

    // ── Detection (parseFieldName) ──

    public function test_parses_role_idx_sub_pattern(): void
    {
        $svc = app(RoleBlockDetectionService::class);
        $p = $svc->parseFieldName('seller_2_phone');
        $this->assertSame('seller', $p['role_base']);
        $this->assertSame(2, $p['instance_index']);
        $this->assertSame('phone', $p['sub_name']);
        $this->assertSame('role_idx_sub', $p['pattern']);
    }

    public function test_parses_role_sub_idx_pattern(): void
    {
        $svc = app(RoleBlockDetectionService::class);
        $p = $svc->parseFieldName('seller_address_3');
        $this->assertSame('seller', $p['role_base']);
        $this->assertSame(3, $p['instance_index']);
        $this->assertSame('address', $p['sub_name']);
        $this->assertSame('role_sub_idx', $p['pattern']);
    }

    public function test_parses_role_sub_singleton(): void
    {
        $svc = app(RoleBlockDetectionService::class);
        $p = $svc->parseFieldName('seller_first_name');
        $this->assertSame('seller', $p['role_base']);
        $this->assertSame(1, $p['instance_index']);
        $this->assertSame('first_name', $p['sub_name']);
        $this->assertSame('role_sub', $p['pattern']);
    }

    public function test_multiword_role_base_wins_over_shorter_prefix(): void
    {
        // owner_party must NOT be mis-parsed as role=owner with sub=party.
        $svc = app(RoleBlockDetectionService::class);
        $p = $svc->parseFieldName('owner_party_2_phone');
        $this->assertSame('owner_party', $p['role_base']);
        $this->assertSame(2, $p['instance_index']);
        $this->assertSame('phone', $p['sub_name']);
    }

    public function test_unrecognised_field_returns_null_role(): void
    {
        $svc = app(RoleBlockDetectionService::class);
        $p = $svc->parseFieldName('purchase_price');
        $this->assertNull($p['role_base']);
        $this->assertSame('none', $p['pattern']);
    }

    // ── Expansion (stampIdentities) ──

    public function test_stamps_role_1_when_single_recipient(): void
    {
        $html = '<p>Hello <span class="x" data-field="seller_first_name">Alice</span></p>';
        $recipients = $this->fakeRecipients(['seller']);
        $out = app(RoleBlockExpansionService::class)->stampIdentities($html, $recipients);

        $this->assertStringContainsString('data-recipient-identity="seller_1"', $out);
        $this->assertStringContainsString('data-role-token="seller"', $out);
        $this->assertStringNotContainsString('data-orphan-recipient', $out);
    }

    public function test_stamps_correct_identities_for_multi_recipient(): void
    {
        $html = '<span data-field="seller_address_1">A</span>'
              . '<span data-field="seller_address_2">B</span>'
              . '<span data-field="seller_1_phone">P1</span>'
              . '<span data-field="seller_2_phone">P2</span>';
        $recipients = $this->fakeRecipients(['seller', 'seller']);
        $out = app(RoleBlockExpansionService::class)->stampIdentities($html, $recipients);

        $this->assertSame(2, substr_count($out, 'data-recipient-identity="seller_1"'));
        $this->assertSame(2, substr_count($out, 'data-recipient-identity="seller_2"'));
        $this->assertSame(4, substr_count($out, 'data-role-token="seller"'));
        $this->assertStringNotContainsString('data-orphan-recipient', $out);
    }

    public function test_marks_orphan_when_index_exceeds_recipient_count(): void
    {
        // Template has hardcoded fields for 4 sellers but only 2 recipients
        // exist on the document — fields 3 and 4 must be flagged orphan.
        $html = '<span data-field="seller_address_1">1</span>'
              . '<span data-field="seller_address_2">2</span>'
              . '<span data-field="seller_address_3">3</span>'
              . '<span data-field="seller_address_4">4</span>';
        $recipients = $this->fakeRecipients(['seller', 'seller']);
        $out = app(RoleBlockExpansionService::class)->stampIdentities($html, $recipients);

        $this->assertSame(2, substr_count($out, 'data-orphan-recipient="1"'));
        $this->assertStringContainsString('data-recipient-identity="seller_3" data-role-token="seller" data-orphan-recipient="1"', $out);
        $this->assertStringContainsString('data-recipient-identity="seller_4" data-role-token="seller" data-orphan-recipient="1"', $out);
    }

    public function test_leaves_unknown_field_names_untouched(): void
    {
        // purchase_price / additional_information don't anchor on a role base
        // → stamping should NOT inject identity attrs (they're not recipient
        // surfaces, they belong to the document body).
        $html = '<span data-field="purchase_price">R1m</span>'
              . '<span data-field="additional_information">notes</span>';
        $recipients = $this->fakeRecipients(['seller']);
        $out = app(RoleBlockExpansionService::class)->stampIdentities($html, $recipients);

        $this->assertStringNotContainsString('data-recipient-identity', $out);
        $this->assertStringNotContainsString('data-role-token', $out);
    }

    public function test_canonical_twin_resolves_recipient_count(): void
    {
        // Template field uses wizard token "seller_2_phone" but the document
        // stored its 2 recipients as canonical "owner_party". The expansion
        // must NOT flag the seller_2 field as orphan because owner_party=2.
        $html = '<span data-field="seller_2_phone">x</span>';
        $recipients = $this->fakeRecipients(['owner_party', 'owner_party']);
        $out = app(RoleBlockExpansionService::class)->stampIdentities($html, $recipients);

        $this->assertStringContainsString('data-recipient-identity="seller_2"', $out);
        $this->assertStringNotContainsString('data-orphan-recipient', $out);
    }

    public function test_detect_from_html_returns_collection_with_offsets(): void
    {
        $html = '<span data-field="seller_1_phone">a</span>'
              . '<span data-field="agent">b</span>';
        $detected = app(RoleBlockDetectionService::class)->detectFromHtml($html);

        $this->assertCount(2, $detected);
        $this->assertSame('seller', $detected[0]['role_base']);
        $this->assertSame(1, $detected[0]['instance_index']);
        $this->assertSame('agent', $detected[1]['role_base']);
        $this->assertSame(1, $detected[1]['instance_index']);
        $this->assertSame(null, $detected[1]['sub_name']);
    }

    public function test_empty_html_returns_empty_string_unchanged(): void
    {
        $out = app(RoleBlockExpansionService::class)->stampIdentities('', collect());
        $this->assertSame('', $out);
    }

    // ── B2.5 — expandWithLooping pipeline ──

    public function test_case_a_single_block_duplicates_for_n_recipients(): void
    {
        // Single seller block + 3 sellers → 3 blocks rendered with sequential
        // identities and per-instance section headers.
        $html = '<div class="contract">'
              . '<div class="seller-section">'
              . '<p>Name: <span data-field="seller_first_name">P</span></p>'
              . '<p>Phone: <span data-field="seller_phone">P</span></p>'
              . '</div>'
              . '</div>';

        $recipients = $this->fakeRecipientsWithNames([
            ['party_role' => 'seller', 'signer_name' => 'Alice'],
            ['party_role' => 'seller', 'signer_name' => 'Bob'],
            ['party_role' => 'seller', 'signer_name' => 'Charlie'],
        ]);
        $out = app(RoleBlockExpansionService::class)->expandWithLooping(null, $html, $recipients);

        $this->assertStringContainsString('data-recipient-identity="seller_1"', $out);
        $this->assertStringContainsString('data-recipient-identity="seller_2"', $out);
        $this->assertStringContainsString('data-recipient-identity="seller_3"', $out);
        $this->assertStringNotContainsString('data-recipient-identity="seller_4"', $out);
        // Contract-driven renderer (968d5338): numberless "Seller - {name}" headers.
        $this->assertStringContainsString('Seller - Alice', $out);
        $this->assertStringContainsString('Seller - Bob', $out);
        $this->assertStringContainsString('Seller - Charlie', $out);
    }

    public function test_case_c_single_block_single_recipient_no_index_header(): void
    {
        // 1 recipient → no header at all (Case C just stamps).
        $html = '<div class="seller-section">'
              . '<span data-field="seller_first_name">P</span>'
              . '</div>';
        $recipients = $this->fakeRecipientsWithNames([
            ['party_role' => 'seller', 'signer_name' => 'Alice'],
        ]);
        $out = app(RoleBlockExpansionService::class)->expandWithLooping(null, $html, $recipients);

        $this->assertStringContainsString('data-recipient-identity="seller_1"', $out);
        $this->assertStringNotContainsString('data-recipient-identity="seller_2"', $out);
        $this->assertStringNotContainsString('recipient-block-header', $out);
    }

    public function test_case_b_hardcoded_multi_block_template_unchanged(): void
    {
        // 4 hardcoded blocks + 4 recipients — pure B2 stamping, no clones.
        $html = '<div class="contract">';
        for ($i = 1; $i <= 4; $i++) {
            $html .= '<div class="seller-block">'
                  . '<span data-field="seller_' . $i . '_phone">P</span>'
                  . '<span data-field="seller_' . $i . '_email">E</span>'
                  . '</div>';
        }
        $html .= '</div>';

        $recipients = $this->fakeRecipientsWithNames([
            ['party_role' => 'seller', 'signer_name' => 'A'],
            ['party_role' => 'seller', 'signer_name' => 'B'],
            ['party_role' => 'seller', 'signer_name' => 'C'],
            ['party_role' => 'seller', 'signer_name' => 'D'],
        ]);
        $out = app(RoleBlockExpansionService::class)->expandWithLooping(null, $html, $recipients);

        for ($i = 1; $i <= 4; $i++) {
            $this->assertSame(2, substr_count($out, 'data-recipient-identity="seller_' . $i . '"'));
        }
        $this->assertStringNotContainsString('data-orphan-recipient', $out);
        $this->assertStringNotContainsString('recipient-block-header', $out);
    }

    public function test_case_d1_hardcoded_4_blocks_with_2_recipients_orphans_3_4(): void
    {
        $html = '<div class="contract">';
        for ($i = 1; $i <= 4; $i++) {
            $html .= '<div class="seller-block">'
                  . '<span data-field="seller_' . $i . '_phone">P</span>'
                  . '<span data-field="seller_' . $i . '_email">E</span>'
                  . '</div>';
        }
        $html .= '</div>';
        $recipients = $this->fakeRecipientsWithNames([
            ['party_role' => 'seller', 'signer_name' => 'A'],
            ['party_role' => 'seller', 'signer_name' => 'B'],
        ]);
        $out = app(RoleBlockExpansionService::class)->expandWithLooping(null, $html, $recipients);

        $this->assertSame(4, substr_count($out, 'data-orphan-recipient="1"'));
        $this->assertSame(2, substr_count($out, 'data-recipient-identity="seller_1"'));
        $this->assertSame(2, substr_count($out, 'data-recipient-identity="seller_2"'));
        $this->assertStringNotContainsString('recipient-block-header', $out);
    }

    public function test_case_d2_hardcoded_2_blocks_with_4_recipients_auto_fills_last(): void
    {
        // Each idx=k field sits inside its own .seller-block container so
        // the per-instance LCA isolation succeeds.
        $html = '<div class="contract">'
              . '<div class="seller-block"><span data-field="seller_1_phone">P1</span></div>'
              . '<div class="seller-block"><span data-field="seller_2_phone">P2</span></div>'
              . '</div>';

        $recipients = $this->fakeRecipientsWithNames([
            ['party_role' => 'seller', 'signer_name' => 'A'],
            ['party_role' => 'seller', 'signer_name' => 'B'],
            ['party_role' => 'seller', 'signer_name' => 'C'],
            ['party_role' => 'seller', 'signer_name' => 'D'],
        ]);
        $out = app(RoleBlockExpansionService::class)->expandWithLooping(null, $html, $recipients);

        $this->assertStringContainsString('data-recipient-identity="seller_3"', $out);
        $this->assertStringContainsString('data-recipient-identity="seller_4"', $out);
        $this->assertStringContainsString('Seller - C', $out);
        $this->assertStringContainsString('Seller - D', $out);
    }

    public function test_case_a_dom_uniqueness_data_field_suffix(): void
    {
        // Every data-field in the rendered body must be unique (no
        // collisions from cloning) — the __r{n} suffix achieves that.
        // Fields wrapped in labelled <p> so the renderer takes the per-recipient
        // CLONING path (bare inline spans are now rendered as an opening-paragraph
        // composite per e46f9689 — a different feature this test does not target).
        $html = '<div class="seller-section">'
              . '<p>Name: <span data-field="seller_first_name">P</span></p>'
              . '<p>Address: <span data-field="seller_address">P</span></p>'
              . '</div>';
        $recipients = $this->fakeRecipientsWithNames([
            ['party_role' => 'seller', 'signer_name' => 'A'],
            ['party_role' => 'seller', 'signer_name' => 'B'],
            ['party_role' => 'seller', 'signer_name' => 'C'],
        ]);
        $out = app(RoleBlockExpansionService::class)->expandWithLooping(null, $html, $recipients);

        preg_match_all('/data-field="([^"]+)"/i', $out, $m);
        $names = $m[1];
        $this->assertSame(count($names), count(array_unique($names)), 'data-field attributes must be unique across clones');
        // Spot-check the suffix convention.
        $this->assertContains('seller_first_name__r2', $names);
        $this->assertContains('seller_address__r3', $names);
    }

    public function test_case_a_pre_fill_uses_per_recipient_contact(): void
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test Agency', 'slug' => 'test-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Test Branch',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Seed two real contacts and assign each as one recipient's contact.
        $contactA = Contact::create([
            'agency_id' => $agencyId, 'branch_id' => $branchId,
            'first_name' => 'Alice', 'last_name' => 'Apple',
            'email' => 'alice@example.test', 'phone' => '+27000000001',
            'address' => '1 Apple Ave', 'id_number' => 'A1',
        ]);
        $contactB = Contact::create([
            'agency_id' => $agencyId, 'branch_id' => $branchId,
            'first_name' => 'Bob', 'last_name' => 'Banana',
            'email' => 'bob@example.test', 'phone' => '+27000000002',
            'address' => '2 Banana Blvd', 'id_number' => 'B2',
        ]);

        // Labelled <p> wrappers → per-recipient cloning path (bare inline spans
        // now render as an opening-paragraph composite per e46f9689).
        $html = '<div class="seller-section">'
              . '<p>Name: <span data-field="seller_first_name">P</span></p>'
              . '<p>Email: <span data-field="seller_email">P</span></p>'
              . '<p>Address: <span data-field="seller_address">P</span></p>'
              . '</div>';

        $r1 = new SignatureRequest();
        $r1->party_role = 'seller'; $r1->role_index = 1;
        $r1->signer_name = 'Alice Apple'; $r1->contact_id = $contactA->id;

        $r2 = new SignatureRequest();
        $r2->party_role = 'seller'; $r2->role_index = 2;
        $r2->signer_name = 'Bob Banana'; $r2->contact_id = $contactB->id;

        $out = app(RoleBlockExpansionService::class)->expandWithLooping(null, $html, collect([$r1, $r2]));

        // Instance 1 spans hold contactA's data.
        $this->assertStringContainsString('data-field="seller_first_name__r1"', $out);
        $this->assertMatchesRegularExpression('/data-field="seller_first_name__r1"[^>]*>Alice</', $out);
        $this->assertMatchesRegularExpression('/data-field="seller_email__r1"[^>]*>alice@example\.test</', $out);
        $this->assertMatchesRegularExpression('/data-field="seller_address__r1"[^>]*>1 Apple Ave</', $out);

        // Instance 2 spans hold contactB's data.
        $this->assertMatchesRegularExpression('/data-field="seller_first_name__r2"[^>]*>Bob</', $out);
        $this->assertMatchesRegularExpression('/data-field="seller_email__r2"[^>]*>bob@example\.test</', $out);
        $this->assertMatchesRegularExpression('/data-field="seller_address__r2"[^>]*>2 Banana Blvd</', $out);
    }

    public function test_mixed_roles_only_target_role_duplicates(): void
    {
        // 1 seller block + 1 buyer block + 1 agent block in document.
        // 2 seller recipients + 1 buyer + 1 agent. Only the seller block
        // duplicates; buyer + agent render unchanged.
        $html = '<div class="contract">'
              . '<div class="seller-section"><span data-field="seller_first_name">P</span></div>'
              . '<div class="buyer-section"><span data-field="buyer_first_name">P</span></div>'
              . '<div class="agent-section"><span data-field="agent_name">P</span></div>'
              . '</div>';
        $recipients = $this->fakeRecipientsWithNames([
            ['party_role' => 'seller', 'signer_name' => 'S1'],
            ['party_role' => 'seller', 'signer_name' => 'S2'],
            ['party_role' => 'buyer',  'signer_name' => 'B1'],
            ['party_role' => 'agent',  'signer_name' => 'A1'],
        ]);
        $out = app(RoleBlockExpansionService::class)->expandWithLooping(null, $html, $recipients);

        $this->assertSame(2, substr_count($out, 'data-role-token="seller"'));
        // buyer + agent render once each, no duplication.
        $this->assertSame(1, substr_count($out, 'data-role-token="buyer"'));
        $this->assertSame(1, substr_count($out, 'data-role-token="agent"'));
        $this->assertStringContainsString('Seller - S1', $out);
        $this->assertStringContainsString('Seller - S2', $out);
        $this->assertStringNotContainsString('Buyer 1', $out);
    }

    public function test_rentals_lessor_block_uses_lessor_labels(): void
    {
        // Labelled <p> wrappers → per-recipient cloning path (bare inline spans
        // now render as an opening-paragraph composite per e46f9689).
        $html = '<div class="lessor-section">'
              . '<p>Name: <span data-field="lessor_first_name">P</span></p>'
              . '<p>Phone: <span data-field="lessor_phone">P</span></p>'
              . '</div>';
        $recipients = $this->fakeRecipientsWithNames([
            ['party_role' => 'lessor', 'signer_name' => 'Liam'],
            ['party_role' => 'lessor', 'signer_name' => 'Mary'],
        ]);
        // Rentals template — pass null and the service defaults to sales
        // ("Seller"). To exercise the rentals path we use a real Template
        // factory; but the rentals branch is exercised via roleDisplayLabel
        // already, and roleDisplayLabel('lessor', false, …) → "Lessor N".
        $svc = app(RoleBlockExpansionService::class);
        // Build a template fixture flagged as rental.
        $template = $this->buildRentalTemplate();
        $out = $svc->expandWithLooping($template, $html, $recipients);

        $this->assertStringContainsString('Lessor - Liam', $out);
        $this->assertStringContainsString('Lessor - Mary', $out);
    }

    // ── B2.5 helpers ──

    /**
     * @param  list<array{party_role:string,signer_name:string}> $rows
     * @return Collection<int, SignatureRequest>
     */
    private function fakeRecipientsWithNames(array $rows): Collection
    {
        $out = collect();
        $counts = [];
        foreach ($rows as $row) {
            $role = $row['party_role'];
            $counts[$role] = ($counts[$role] ?? 0) + 1;
            $r = new SignatureRequest();
            $r->party_role  = $role;
            $r->role_index  = $counts[$role];
            $r->signer_name = $row['signer_name'];
            $r->contact_id  = null;
            $out->push($r);
        }
        return $out;
    }

    private function buildRentalTemplate(): \App\Models\Docuperfect\Template
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name'       => 'Test User',
            'email'      => 'test-' . Str::random(8) . '@example.test',
            'password'   => bcrypt('password'),
            'role'       => 'agent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return \App\Models\Docuperfect\Template::create([
            'name'           => 'Rental Test',
            'render_type'    => 'web',
            'template_type'  => 'cds',
            'category'       => 'rentals',
            'signing_parties'=> ['owner_party', 'acquiring_party', 'agent'],
            'owner_id'       => $userId,
        ]);
    }

    /**
     * @param  list<string>                      $roles  e.g. ['seller','seller','agent']
     * @return Collection<int, SignatureRequest>
     */
    private function fakeRecipients(array $roles): Collection
    {
        $out = collect();
        $counts = [];
        foreach ($roles as $role) {
            $counts[$role] = ($counts[$role] ?? 0) + 1;
            $req = new SignatureRequest();
            $req->party_role = $role;
            $req->role_index = $counts[$role];
            $out->push($req);
        }
        return $out;
    }
}
