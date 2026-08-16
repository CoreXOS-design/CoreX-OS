<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\SignatureRequest;
use App\Services\Docuperfect\RoleBlockExpansionService;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * AT-300 — collective ("_full") role blocks must render ONCE, not per recipient.
 *
 * Johan's CDS mandate binds a single joined "seller_full" field ("I / We Anine
 * … and Andre …") but marks the clause data-role-block="seller". expandViaContract
 * looped it per recipient → the shared I/We clause duplicated (one "Seller - Name"
 * headed block per seller). Fix: a role whose document carries a collective
 * "<role>_full" field renders once, no per-recipient header. Loop-templates
 * (indexed / per-attribute fields, no "_full") are unaffected.
 */
final class CollectiveRoleRenderTest extends TestCase
{
    /** @param list<array{int,string}> $rows */
    private function sellers(array $rows): Collection
    {
        return collect($rows)->map(function ($r) {
            $s = new SignatureRequest();
            $s->party_role = 'seller';
            $s->role_index = $r[0];
            $s->signer_name = $r[1];
            $s->contact_id = null;
            return $s;
        });
    }

    public function test_collective_full_field_clause_renders_once(): void
    {
        $html = '<div data-role-block="seller" class="corex-clause">'
              . '<p>I / We <span data-field="seller_full">Anine and Andre</span> the undersigned...</p>'
              . '</div>';

        $out = app(RoleBlockExpansionService::class)->expandWithLooping(
            null, $html, $this->sellers([[1, 'Anine'], [2, 'Andre']]),
        );

        $this->assertSame(1, substr_count($out, 'I / We'), 'collective I/We clause must render exactly once');
        $this->assertSame(0, substr_count($out, 'recipient-block-header'), 'no per-recipient headers for a collective role');
    }

    public function test_collective_clause_renders_once_while_detail_blocks_loop(): void
    {
        // The real shape: a collective I/We clause (seller_full — both names)
        // PLUS a per-seller detail block (seller_address). The clause must render
        // ONCE (both names kept); the detail block must loop per seller.
        $html = '<div data-role-block="seller" class="corex-clause">'
              . '<p>I / We <span data-field="seller_full">Anine and Andre</span> the undersigned...</p>'
              . '</div>'
              . '<p>spacer</p>'
              . '<div data-role-block="seller" class="corex-clause">'
              . '<p>Domicilium: <span data-field="seller_address">addr</span></p>'
              . '</div>';

        $out = app(RoleBlockExpansionService::class)->expandWithLooping(
            null, $html, $this->sellers([[1, 'Anine'], [2, 'Andre']]),
        );

        $this->assertSame(1, substr_count($out, 'I / We'), 'collective clause once');
        $this->assertSame(2, substr_count($out, 'data-field="seller_address'), 'per-seller detail block loops per recipient');
        $this->assertStringContainsString('data-recipient-identity="seller_1"', $out);
        $this->assertStringContainsString('data-recipient-identity="seller_2"', $out, 'seller 2 detail must NOT vanish');
    }

    public function test_non_collective_indexed_role_still_loops(): void
    {
        // No "_full" field → per-recipient loop preserved.
        $html = '<div data-role-block="seller" class="corex-clause">'
              . '<p>Name: <span data-field="seller_first_name">P</span></p>'
              . '</div>';

        $out = app(RoleBlockExpansionService::class)->expandWithLooping(
            null, $html, $this->sellers([[1, 'Alice'], [2, 'Bob']]),
        );

        $this->assertStringContainsString('data-recipient-identity="seller_1"', $out);
        $this->assertStringContainsString('data-recipient-identity="seller_2"', $out, 'loop-templates still expand per recipient');
    }
}
