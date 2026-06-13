<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\PropertyAdTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ad Manager — custom template visibility + edit-rights invariants.
 * Spec: .ai/specs/ad-manager.md §5–§6.
 *
 * Guards the two behaviours that previously leaked / were ambiguous:
 *  1. Custom templates are visible to the WHOLE agency that built them, and
 *     NEVER to another agency (the old `->orWhere('is_global', true)` query
 *     leaked global templates across agencies via operator precedence).
 *  2. Only the creator — or a member with `properties.ad_templates.manage` —
 *     can edit/delete a template.
 */
final class AdTemplateAgencyScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_templates_are_visible_agency_wide_but_not_across_agencies(): void
    {
        $agencyA = $this->makeAgency();
        $agencyB = $this->makeAgency();

        $a1 = $this->agencyUser($agencyA, 'agent');
        $a2 = $this->agencyUser($agencyA, 'agent');
        $b1 = $this->agencyUser($agencyB, 'agent');

        $this->actingAs($a1);
        PropertyAdTemplate::create(['user_id' => $a1->id, 'name' => 'A1 tpl', 'layout_json' => ['elements' => []], 'is_global' => false]);

        $this->actingAs($a2);
        PropertyAdTemplate::create(['user_id' => $a2->id, 'name' => 'A2 tpl', 'layout_json' => ['elements' => []], 'is_global' => false]);

        $this->actingAs($b1);
        PropertyAdTemplate::create(['user_id' => $b1->id, 'name' => 'B1 tpl', 'layout_json' => ['elements' => []], 'is_global' => false]);

        // a1 sees BOTH agency-A templates (incl. a2's) and NEVER the agency-B one.
        // This mirrors the PropertyController@ad query exactly.
        $this->actingAs($a1);
        $names = PropertyAdTemplate::orderByDesc('updated_at')->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['A1 tpl', 'A2 tpl'], $names);
        $this->assertNotContains('B1 tpl', $names);
    }

    public function test_edit_rights_are_creator_or_manage_permission(): void
    {
        $agency  = $this->makeAgency();
        $creator = $this->agencyUser($agency, 'agent');
        $peer    = $this->agencyUser($agency, 'agent');   // no manage permission
        $manager = $this->agencyUser($agency, 'admin');   // has properties.ad_templates.manage

        $this->actingAs($creator);
        $tpl = PropertyAdTemplate::create(['user_id' => $creator->id, 'name' => 'Tpl', 'layout_json' => ['elements' => []], 'is_global' => false]);

        $this->assertTrue($tpl->canBeManagedBy($creator), 'creator can always manage their own template');
        $this->assertFalse($tpl->canBeManagedBy($peer), 'a peer agent without the manage permission cannot');
        $this->assertTrue($tpl->canBeManagedBy($manager), 'a member with properties.ad_templates.manage can');
    }

    private function agencyUser(int $agencyId, string $role): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => $role,
        ]);
    }

    private function makeAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Test ' . Str::random(6),
            'slug'       => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id'         => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $agencyId;
    }
}
