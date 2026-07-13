<?php

namespace Tests\Feature\DealV2;

use App\Models\Agency;
use App\Models\DealV2\DealStageDocumentRule;
use App\Models\DocumentType;
use App\Models\Scopes\AgencyScope;
use App\Services\DealV2\DocumentDistributionMatrix;
use Database\Seeders\DocumentDistributionMatrixSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentDistributionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private DocumentDistributionMatrix $matrix;
    private int $otp;
    private int $ids;
    private int $por;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matrix = app(DocumentDistributionMatrix::class);
        $this->otp = $this->type('otp', 'Signed OTP');
        $this->ids = $this->type('ids', 'IDs');
        $this->por = $this->type('por', 'Proof of Residence');
    }

    private function type(string $slug, string $label): int
    {
        return DocumentType::create([
            'slug' => $slug . '-' . Str::random(4), 'label' => $label,
            'sort_order' => 1, 'is_active' => true,
        ])->id;
    }

    private function agency(): int
    {
        return Agency::create(['name' => 'A', 'slug' => 'a-' . uniqid()])->id;
    }

    public function test_set_and_read_type_distribution_roundtrip(): void
    {
        $a = $this->agency();
        $this->matrix->setTypeDistribution($a, $this->otp, ['seller', 'buyer']);

        $this->assertTrue($this->matrix->isDistributable($a, $this->otp));
        $this->assertEqualsCanonicalizing(['seller', 'buyer'], $this->matrix->partyRolesForType($a, $this->otp));
        $this->assertEqualsCanonicalizing(['seller', 'buyer'], $this->matrix->matrix($a)[$this->otp] ?? []);
    }

    public function test_consumer_queries_return_the_configured_types(): void
    {
        $a = $this->agency();
        $this->matrix->setTypeDistribution($a, $this->otp, ['seller', 'buyer', 'bond_originator']);
        $this->matrix->setTypeDistribution($a, $this->ids, ['bond_originator']);

        // Consumer (AT-228 / m6): "what goes to the bond originator?"
        $typeIds = $this->matrix->typesForParty($a, 'bond_originator')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->otp, $this->ids], $typeIds);

        // Seller only gets the OTP.
        $this->assertSame([$this->otp], $this->matrix->typesForParty($a, 'seller')->pluck('id')->all());

        // Raw rules carry a delivery_mode for AT-228.
        $rules = $this->matrix->rulesForParty($a, 'bond_originator');
        $this->assertNotEmpty($rules);
        $this->assertSame('secure_link', $rules->first()->delivery_mode);
    }

    public function test_removing_a_role_soft_deletes_and_does_not_reappear(): void
    {
        $a = $this->agency();
        $this->matrix->setTypeDistribution($a, $this->otp, ['seller', 'buyer']);
        $this->matrix->setTypeDistribution($a, $this->otp, ['seller']);  // drop buyer

        $this->assertSame(['seller'], $this->matrix->partyRolesForType($a, $this->otp));
        // buyer row soft-deleted, not hard-deleted (no hard deletes)
        $this->assertDatabaseHas('deal_stage_document_rules', [
            'agency_id' => $a, 'document_type_id' => $this->otp, 'party_role' => 'buyer',
        ]);
        // re-adding restores the same row (idempotent, no duplicate)
        $this->matrix->setTypeDistribution($a, $this->otp, ['seller', 'buyer']);
        $count = DealStageDocumentRule::withoutGlobalScope(AgencyScope::class)->withTrashed()
            ->where('agency_id', $a)->where('document_type_id', $this->otp)->where('party_role', 'buyer')->count();
        $this->assertSame(1, $count, 'restore, not duplicate');
    }

    public function test_matrix_is_agency_scoped(): void
    {
        $a = $this->agency();
        $b = $this->agency();
        $this->matrix->setTypeDistribution($a, $this->otp, ['seller']);

        $this->assertTrue($this->matrix->isDistributable($a, $this->otp));
        $this->assertFalse($this->matrix->isDistributable($b, $this->otp), 'agency B does not inherit A');
        $this->assertSame([], $this->matrix->typesForParty($b, 'seller')->all());
    }

    public function test_type_level_rules_do_not_collide_with_stage_rules(): void
    {
        $a = $this->agency();
        // A stage-level rule (pipeline_step_id set) for the same type+role must be ignored by the
        // matrix. FK checks off — the step target is irrelevant; we only need a NON-NULL stage.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DealStageDocumentRule::create([
            'agency_id' => $a, 'pipeline_step_id' => 999001, 'document_type_id' => $this->otp,
            'party_role' => 'seller', 'delivery_mode' => 'secure_link', 'is_active' => true,
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->assertFalse($this->matrix->isDistributable($a, $this->otp), 'stage rule is not a type-level rule');

        $this->matrix->setTypeDistribution($a, $this->otp, ['seller']);
        $this->assertTrue($this->matrix->isDistributable($a, $this->otp));
    }

    public function test_locked_example_defaults_seeder(): void
    {
        // Rename my test types to the canonical slugs the seeder expects.
        DocumentType::where('id', $this->otp)->update(['slug' => 'otp']);
        DocumentType::where('id', $this->ids)->update(['slug' => 'ids']);
        DocumentType::where('id', $this->por)->update(['slug' => 'por']);
        $a = $this->agency();

        (new DocumentDistributionMatrixSeeder())->run();

        // Johan-locked examples reproducible:
        $this->assertEqualsCanonicalizing(['seller', 'buyer', 'bond_originator', 'transfer_attorney'],
            $this->matrix->partyRolesForType($a, $this->otp), 'OTP → all four parties');
        $this->assertEqualsCanonicalizing(['bond_originator', 'transfer_attorney'],
            $this->matrix->partyRolesForType($a, $this->ids), 'ID → originator + attorney');
        // Seller receives the signed OTP (and, once proforma is present, the proforma_invoice
        // type default too — so assert membership, not an exact-only set).
        $this->assertContains($this->otp, $this->matrix->typesForParty($a, 'seller')->pluck('id')->all(),
            'seller gets the signed OTP');
    }
}
