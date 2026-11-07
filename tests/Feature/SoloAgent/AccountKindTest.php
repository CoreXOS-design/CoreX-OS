<?php

namespace Tests\Feature\SoloAgent;

use App\Models\Agency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Solo Agent Accounts, build step 1 - .ai/specs/solo-agent-accounts.md sections 2 and 9.1 */
class AccountKindTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_column_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('agencies', 'account_kind'));
    }

    public function test_every_agency_is_a_normal_agency_by_default(): void
    {
        $agency = Agency::create(['name' => 'Ordinary Realty', 'slug' => 'ordinary-realty']);

        $this->assertSame('agency', $agency->fresh()->account_kind);
        $this->assertFalse($agency->fresh()->isSolo());
    }

    public function test_account_kind_cannot_be_set_by_mass_assignment(): void
    {
        $agency = Agency::create(['name' => 'Sneaky Co', 'slug' => 'sneaky-co', 'account_kind' => 'solo']);

        $this->assertSame('agency', $agency->fresh()->account_kind);
        $this->assertFalse($agency->fresh()->isSolo());
    }

    public function test_only_an_explicit_owner_side_write_makes_an_agency_solo(): void
    {
        $agency = Agency::create(['name' => 'One Person Co', 'slug' => 'one-person-co']);
        $agency->forceFill(['account_kind' => Agency::ACCOUNT_KIND_SOLO])->save();

        $this->assertTrue($agency->fresh()->isSolo());
    }
}
