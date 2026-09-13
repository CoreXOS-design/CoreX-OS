<?php

declare(strict_types=1);

namespace Tests\Feature\ContactProperty;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use App\Services\CommandCenter\PropertyHealthCalculator;
use App\Services\Compliance\MarketingReadinessService;
use App\Services\Deal\DealPropertyOwnerGate;
use App\Services\Property\PropertyOwnershipGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression coverage for cc4's contact_property read-site fixes (AT-398,
 * .ai/specs/rental-applications.md — "contact_property hard-delete fix",
 * 2026-09-13). Every read site listed there was patched to exclude
 * soft-deleted pivot rows; each test below invokes the actual production
 * method directly (never inferred from another method's identical query
 * shape) and proves a trashed-only link is excluded.
 *
 * `contact_property.deleted_at` does not exist in the committed schema
 * snapshot yet — cc3 owns that migration in stage 1. setUp() bolts the
 * column on if it's missing so this suite runs correctly both before and
 * after that migration lands; once it lands, Schema::hasColumn() short-
 * circuits and this becomes a no-op.
 *
 * Methods proved directly, and how:
 * - PropertyHealthCalculator::calculate() — real call, raw DB::table site.
 * - DealPropertyOwnerGate::sellerSideContactIds() — real call, wherePivotIn+wherePivotNull.
 * - PropertyOwnershipGuard::assertCanUnlink()/currentRole() — real call.
 * - MarketingReadinessService::sellerContactIds() — private; invoked via
 *   ReflectionMethod directly (not inferred from the DealPropertyOwnerGate
 *   result above), since reaching it through the public isMarketable()/
 *   statusFor() surface would require unrelated agency-doc-type fixtures.
 *
 * Neither Property nor Contact has a model factory in this codebase —
 * fixtures are built with ::create() and explicit required fields, matching
 * the established pattern in tests/Feature/Compliance/MarketingReadinessDriveGateTest.php.
 */
class ContactPropertyDeletedAtReadSiteTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('contact_property', 'deleted_at')) {
            Schema::table('contact_property', function ($table) {
                $table->timestamp('deleted_at')->nullable();
            });
        }

        $this->agency = Agency::create(['name' => 'CP Test Agency', 'slug' => 'cp-test-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    private function makeProperty(): Property
    {
        return Property::create([
            'title'         => 'CP Test Listing',
            'agency_id'     => $this->agency->id,
            'agent_id'      => $this->user->id,
            'branch_id'     => $this->branch->id,
            'listing_type'  => 'sale',
            'address'       => '1 Test Street',
            'street_name'   => 'Test Street',
            'suburb'        => 'Mtunzini',
            'town'          => 'Mtunzini',
            'province'      => 'KwaZulu-Natal',
            'price'         => 1000000,
            'property_type' => 'House',
        ]);
    }

    private function makeContact(string $firstName): Contact
    {
        return Contact::create([
            'agency_id'          => $this->agency->id,
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $this->user->id,
            'first_name'         => $firstName,
            'last_name'          => 'Test',
            'phone'              => '083' . random_int(1000000, 9999999),
        ]);
    }

    public function test_property_health_calculator_owner_factor_excludes_soft_deleted_row(): void
    {
        $property = $this->makeProperty();
        $activeSeller = $this->makeContact('ActiveSeller');
        $trashedSeller = $this->makeContact('TrashedSeller');

        DB::table('contact_property')->insert([
            'contact_id' => $activeSeller->id,
            'property_id' => $property->id,
            'role' => 'seller',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
        DB::table('contact_property')->insert([
            'contact_id' => $trashedSeller->id,
            'property_id' => $property->id,
            'role' => 'seller',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);

        $score = (new PropertyHealthCalculator())->calculate($property->fresh());
        $this->assertSame('good', $score->factors['owner']['status']);

        DB::table('contact_property')->where('contact_id', $activeSeller->id)->delete();
        $score2 = (new PropertyHealthCalculator())->calculate($property->fresh());
        $this->assertSame('critical', $score2->factors['owner']['status'], 'a trashed-only seller link must not count as an owner');
    }

    public function test_deal_property_owner_gate_seller_side_contact_ids_excludes_soft_deleted_row(): void
    {
        $property = $this->makeProperty();
        $activeOwner = $this->makeContact('ActiveOwner');
        $trashedOwner = $this->makeContact('TrashedOwner');

        DB::table('contact_property')->insert([
            ['contact_id' => $activeOwner->id, 'property_id' => $property->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null],
            ['contact_id' => $trashedOwner->id, 'property_id' => $property->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => now()],
        ]);

        $gate = new DealPropertyOwnerGate();
        $ids = $gate->sellerSideContactIds($property->fresh());

        $this->assertContains($activeOwner->id, $ids);
        $this->assertNotContains($trashedOwner->id, $ids);
    }

    public function test_property_ownership_guard_current_role_ignores_trashed_link(): void
    {
        $property = $this->makeProperty();
        $contact = $this->makeContact('TrashedOnly');

        DB::table('contact_property')->insert([
            'contact_id' => $contact->id,
            'property_id' => $property->id,
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);

        $guard = new PropertyOwnershipGuard();

        // assertCanUnlink resolves currentRole() internally — a trashed-only
        // link must read as "no current role" (not seller-side), so unlink
        // is a no-op allow, not a false-positive ownership lock.
        $guard->assertCanUnlink($property->fresh(), $contact->id);
        $this->assertTrue(true, 'no OwnershipLockedException thrown for a trashed-only link');
    }

    public function test_marketing_readiness_seller_contact_ids_excludes_soft_deleted_row(): void
    {
        $property = $this->makeProperty();
        $activeSeller = $this->makeContact('ActiveSeller2');
        $trashedSeller = $this->makeContact('TrashedSeller2');

        DB::table('contact_property')->insert([
            ['contact_id' => $activeSeller->id, 'property_id' => $property->id, 'role' => 'seller', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null],
            ['contact_id' => $trashedSeller->id, 'property_id' => $property->id, 'role' => 'seller', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => now()],
        ]);

        $service = app(MarketingReadinessService::class);
        $method = new ReflectionMethod(MarketingReadinessService::class, 'sellerContactIds');
        $method->setAccessible(true);
        $ids = $method->invoke($service, $property->fresh())->all();

        $this->assertContains($activeSeller->id, $ids);
        $this->assertNotContains($trashedSeller->id, $ids, 'a trashed-only seller link must not be returned by the private sellerContactIds() lookup');
    }
}
