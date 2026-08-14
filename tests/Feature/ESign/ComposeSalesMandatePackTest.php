<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Models\Agency;
use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\WebPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HD-3b — the Sales Mandate Pack composition command builds Johan's D-1 pack from REAL templates,
 * and reports (never fabricates) the slots it cannot fill.
 *
 * The command runs on qa1 where the real content lives; this proves its composition logic against
 * fixtures — the same document_type resolution, the same slot plan.
 */
final class ComposeSalesMandatePackTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'super_admin']);
    }

    private function docType(string $slug, string $label): int
    {
        return DocumentType::create(['slug' => $slug, 'label' => $label, 'sort_order' => 0, 'is_active' => true])->id;
    }

    private function template(string $name, int $docTypeId): Template
    {
        return Template::create([
            'name' => $name, 'template_type' => 'sales', 'render_type' => 'web',
            'blade_view' => 'x.' . str()->slug($name), 'is_esign' => true, 'fields_json' => [],
            'document_type_id' => $docTypeId,
        ]);
    }

    private function compose(array $opts = []): int
    {
        return $this->artisan('esign:compose-sales-mandate-pack', array_merge(['--agency' => $this->agency->id], $opts))->run();
    }

    /** The full D-1 pack: mandate one-of, disclosure required, FICA one-of — in the right slots. */
    public function test_it_composes_the_full_pack_when_every_member_exists(): void
    {
        $mandate = $this->docType('mandate', 'Mandate');
        $disc    = $this->docType('disclosure', 'Disclosure');
        $fica    = $this->docType('fica', 'FICA');

        $this->template('Exclusive Authority to Sell', $mandate);
        $this->template('Open Mandate', $mandate);
        $this->template('Sales Mandatory Disclosure', $disc);
        $this->template('FICA — Natural Person', $fica);
        $this->template('FICA — Company', $fica);

        $this->assertSame(0, $this->compose(['--apply' => true]));

        $pack = WebPack::withoutGlobalScopes()->where('name', 'Sales Mandate Pack (CANDIDATE)')->with('items')->first();
        $this->assertNotNull($pack);

        $bySlot = $pack->items->groupBy('slot_type');
        // Two mandate variants + two FICA variants are selectable; the disclosure is required.
        $this->assertCount(4, $bySlot->get('selectable'));
        $this->assertCount(1, $bySlot->get('required'));

        $mandateGroup = $pack->items->where('slot_label', 'Mandate type');
        $ficaGroup    = $pack->items->where('slot_label', 'FICA');
        $this->assertCount(2, $mandateGroup);
        $this->assertTrue($mandateGroup->every(fn ($i) => $i->slot_group === 1));
        $this->assertCount(2, $ficaGroup);
        $this->assertTrue($ficaGroup->every(fn ($i) => $i->slot_group === 2));
    }

    /** FICA missing (its real state today — it is a compliance module) → pack composes WITHOUT a FICA slot. */
    public function test_it_composes_without_fica_and_does_not_invent_it(): void
    {
        $mandate = $this->docType('mandate', 'Mandate');
        $disc    = $this->docType('disclosure', 'Disclosure');
        $this->template('Exclusive Authority to Sell', $mandate);
        $this->template('Sales Mandatory Disclosure', $disc);

        $this->assertSame(0, $this->compose(['--apply' => true]));

        $pack = WebPack::withoutGlobalScopes()->where('name', 'Sales Mandate Pack (CANDIDATE)')->with('items')->first();
        $this->assertNotNull($pack);
        $this->assertCount(2, $pack->items, 'Exclusive mandate + disclosure only — no fabricated FICA.');
        $this->assertFalse($pack->items->contains('slot_label', 'FICA'));
    }

    /** A mandate pack with no mandate is meaningless — refuse and write nothing. */
    public function test_it_refuses_when_no_mandate_exists(): void
    {
        $disc = $this->docType('disclosure', 'Disclosure');
        $this->template('Sales Mandatory Disclosure', $disc);

        $this->assertSame(1, $this->compose(['--apply' => true]));
        $this->assertNull(WebPack::withoutGlobalScopes()->where('name', 'Sales Mandate Pack (CANDIDATE)')->first());
    }

    /** Dry-run writes nothing. */
    public function test_dry_run_writes_nothing(): void
    {
        $mandate = $this->docType('mandate', 'Mandate');
        $this->template('Exclusive Authority to Sell', $mandate);

        $this->assertSame(0, $this->compose());
        $this->assertNull(WebPack::withoutGlobalScopes()->where('name', 'Sales Mandate Pack (CANDIDATE)')->first());
    }

    /** Idempotent — re-running folds in a newly-added variant without duplicating the pack. */
    public function test_rerun_is_idempotent_and_absorbs_new_variants(): void
    {
        $mandate = $this->docType('mandate', 'Mandate');
        $this->template('Exclusive Authority to Sell', $mandate);
        $this->compose(['--apply' => true]);

        $this->template('Open Mandate', $mandate); // Johan adds the Open variant later
        $this->compose(['--apply' => true]);

        $packs = WebPack::withoutGlobalScopes()->where('name', 'Sales Mandate Pack (CANDIDATE)')->get();
        $this->assertCount(1, $packs, 'One pack, not duplicated.');
        $this->assertCount(2, $packs->first()->items()->get(), 'Both mandate variants now present.');
    }

    /**
     * Tonight's belt: Johan imports the EATS and it lands with NO document_type (classifier missed the
     * name). The name-fallback still wires it into the Mandate slot — the pack composes with his import.
     */
    public function test_a_mandate_with_no_document_type_is_wired_by_name_fallback(): void
    {
        // No document_type at all — just a real e-signable web template named like an EATS.
        Template::create([
            'name' => 'Exclusive Authority to Sell (13/13 charset)', 'template_type' => 'sales',
            'render_type' => 'web', 'blade_view' => 'x.eats', 'is_esign' => true, 'fields_json' => [],
            'document_type_id' => null,
        ]);

        $this->assertSame(0, $this->compose(['--apply' => true]));

        $pack = WebPack::withoutGlobalScopes()->where('name', 'Sales Mandate Pack (CANDIDATE)')->with('items')->first();
        $this->assertNotNull($pack, 'The pack composes off Johan\'s untyped EATS import via the name fallback.');
        $this->assertTrue($pack->items->contains(fn ($i) => str_contains($i->template->name, 'Exclusive Authority to Sell')));
    }

    /** The name fallback must NOT drag in a mandatory DISCLOSURE (word-boundary: "mandatory" ≠ "mandate"). */
    public function test_the_name_fallback_does_not_capture_a_disclosure_as_a_mandate(): void
    {
        Template::create([
            'name' => 'Exclusive Authority to Sell', 'template_type' => 'sales', 'render_type' => 'web',
            'blade_view' => 'x.eats', 'is_esign' => true, 'fields_json' => [], 'document_type_id' => null,
        ]);
        Template::create([
            'name' => 'Sales Mandatory Disclosure', 'template_type' => 'sales', 'render_type' => 'web',
            'blade_view' => 'x.disc', 'is_esign' => true, 'fields_json' => [], 'document_type_id' => null,
        ]);

        $this->compose(['--apply' => true]);

        $pack = WebPack::withoutGlobalScopes()->where('name', 'Sales Mandate Pack (CANDIDATE)')->with('items')->first();
        $mandateItems = $pack->items->where('slot_label', 'Mandate type');
        $this->assertCount(1, $mandateItems, 'Only the EATS is a mandate; "Mandatory Disclosure" must not be pulled in by name.');
    }

    /** A legally-blocked template (an OTP mis-typed as mandate) is never wired into a candidate pack. */
    public function test_an_esign_blocked_template_is_excluded(): void
    {
        $mandate = $this->docType('mandate', 'Mandate');
        $this->template('Exclusive Authority to Sell', $mandate);
        // Name trips the alienation block regardless of its document_type.
        $this->template('Offer to Purchase', $mandate);

        $this->compose(['--apply' => true]);

        $pack = WebPack::withoutGlobalScopes()->where('name', 'Sales Mandate Pack (CANDIDATE)')->with('items')->first();
        $this->assertFalse($pack->items->contains(fn ($i) => str_contains($i->template->name, 'Offer to Purchase')));
    }
}
