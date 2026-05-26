<?php

declare(strict_types=1);

namespace Tests\Feature\RecipientLoop;

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

    // ── Helpers ──

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
