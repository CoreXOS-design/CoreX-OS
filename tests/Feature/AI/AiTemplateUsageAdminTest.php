<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\AI\AiSpendGuardEvent;
use App\Models\Agency;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AT-456 Part B (deferred from Part A, spec §A.5): the AI usage page shows each agency's
 * template generations (used / limit this month) and AI templates saved, both sortable, and
 * the owner can set an agency's monthly limit — validated, owner-only, audited.
 */
class AiTemplateUsageAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        \App\Models\Role::clearCache();
        parent::tearDown();
    }

    public function test_the_list_shows_template_generations_and_ai_templates_saved_per_agency(): void
    {
        $busy  = $this->agency('Busy Co');
        $quiet = $this->agency('Quiet Co');
        DB::table('agencies')->where('id', $busy->id)->update(['ai_template_monthly_limit' => 35]);
        $user = $this->userIn($busy);
        $this->generations($busy, $user, 3);
        $this->generations($busy, $user, 2, ['status' => 'failed']);                    // never counted
        $this->generations($busy, $user, 4, ['created_at' => now()->subMonths(2)]);    // not this month
        $this->aiTemplates($busy, $user, 2, true);
        $this->aiTemplates($busy, $user, 1, false);                                    // hand made — not counted
        $this->aiTemplates($quiet, $this->userIn($quiet), 1, true);

        $res = $this->actingAs($this->owner())->get(route('admin.ai-usage.index'));

        $res->assertOk();
        $res->assertSee('Template generations');
        $res->assertSee('AI templates saved');
        $rows = collect($res->viewData('agencies')->items())->keyBy('name');
        $this->assertSame(3, $rows['Busy Co']['template_used']);
        $this->assertSame(35, $rows['Busy Co']['template_limit']);
        $this->assertSame(2, $rows['Busy Co']['templates_saved']);
        $this->assertSame(0, $rows['Quiet Co']['template_used']);
        $this->assertSame(20, $rows['Quiet Co']['template_limit']); // the default
        $this->assertSame(1, $rows['Quiet Co']['templates_saved']);
        $res->assertSee('3 / 35');
    }

    public function test_both_new_columns_sort_in_either_direction(): void
    {
        $a = $this->agency('Alpha'); $b = $this->agency('Bravo'); $c = $this->agency('Charlie');
        $this->generations($b, $this->userIn($b), 5);
        $this->generations($c, $this->userIn($c), 2);
        $this->aiTemplates($a, $this->userIn($a), 4, true);
        $this->aiTemplates($c, $this->userIn($c), 1, true);
        $owner = $this->owner();

        $names = fn (array $q) => collect($this->actingAs($owner)->get(route('admin.ai-usage.index', $q))->viewData('agencies')->items())->pluck('name')->all();

        $this->assertSame(['Bravo', 'Charlie', 'Alpha'], $names(['sort' => 'template_gens']));
        $this->assertSame(['Alpha', 'Charlie', 'Bravo'], $names(['sort' => 'template_gens', 'direction' => 'asc']));
        $this->assertSame(['Alpha', 'Charlie', 'Bravo'], $names(['sort' => 'templates_saved']));
        $this->assertSame(['Bravo', 'Charlie', 'Alpha'], $names(['sort' => 'templates_saved', 'direction' => 'asc']));
    }

    public function test_the_owner_sets_an_agencys_limit_and_it_is_audited(): void
    {
        $agency = $this->agency('Limit Co');
        $owner  = $this->owner();

        $this->actingAs($owner)
            ->post(route('admin.ai-usage.template-limit', ['agency' => $agency->id]), ['limit' => 35])
            ->assertRedirect();

        $this->assertSame(35, (int) DB::table('agencies')->where('id', $agency->id)->value('ai_template_monthly_limit'));
        $audit = AiSpendGuardEvent::where('agency_id', $agency->id)->where('type', AiSpendGuardEvent::TYPE_TEMPLATE_LIMIT)->sole();
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertEquals(['old' => 20, 'new' => 35], $audit->details);

        // Shown on the agency page (form + history).
        $page = $this->actingAs($owner)->get(route('admin.ai-usage.agency', ['agency' => $agency->id]));
        $page->assertOk()->assertSee('Generations per month')->assertSee('Template limit changed')->assertSee('20 → 35 a month');

        // Re-saving the same number writes nothing new.
        $this->actingAs($owner)->post(route('admin.ai-usage.template-limit', ['agency' => $agency->id]), ['limit' => 35]);
        $this->assertSame(1, AiSpendGuardEvent::where('type', AiSpendGuardEvent::TYPE_TEMPLATE_LIMIT)->count());
    }

    #[DataProvider('badLimits')]
    public function test_a_limit_must_be_a_whole_number_from_0_to_1000(mixed $bad): void
    {
        $agency = $this->agency('Strict Co');

        $this->actingAs($this->owner())
            ->from(route('admin.ai-usage.agency', ['agency' => $agency->id]))
            ->post(route('admin.ai-usage.template-limit', ['agency' => $agency->id]), ['limit' => $bad])
            ->assertSessionHasErrors('limit');

        $this->assertSame(20, (int) DB::table('agencies')->where('id', $agency->id)->value('ai_template_monthly_limit'));
        $this->assertSame(0, AiSpendGuardEvent::where('type', AiSpendGuardEvent::TYPE_TEMPLATE_LIMIT)->count());
    }

    public static function badLimits(): array
    {
        return [[-1], [1001], [5.5], ['lots'], [''], [null]];
    }

    public function test_zero_and_1000_are_accepted(): void
    {
        $agency = $this->agency('Edge Co');
        $owner  = $this->owner();

        $this->actingAs($owner)->post(route('admin.ai-usage.template-limit', ['agency' => $agency->id]), ['limit' => 0])->assertSessionHasNoErrors();
        $this->assertSame(0, (int) DB::table('agencies')->where('id', $agency->id)->value('ai_template_monthly_limit'));
        $this->actingAs($owner)->post(route('admin.ai-usage.template-limit', ['agency' => $agency->id]), ['limit' => 1000])->assertSessionHasNoErrors();
        $this->assertSame(1000, (int) DB::table('agencies')->where('id', $agency->id)->value('ai_template_monthly_limit'));
    }

    public function test_an_agency_admin_cannot_set_a_limit_not_even_their_own(): void
    {
        $agency = $this->agency('Mine Co');
        $admin  = $this->userIn($agency, 'admin');

        $this->actingAs($admin)
            ->post(route('admin.ai-usage.template-limit', ['agency' => $agency->id]), ['limit' => 999])
            ->assertForbidden();

        $this->assertSame(20, (int) DB::table('agencies')->where('id', $agency->id)->value('ai_template_monthly_limit'));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function agency(string $name): Agency
    {
        return Agency::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid()]);
    }

    private function userIn(Agency $agency, string $role = 'agent'): User
    {
        $branchId = DB::table('branches')->where('agency_id', $agency->id)->value('id')
            ?? (int) DB::table('branches')->insertGetId(['agency_id' => $agency->id, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);

        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branchId, 'role' => $role]);
    }

    private function owner(): User
    {
        $role = \App\Models\Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        \App\Models\Role::clearCache();

        return User::factory()->create(['agency_id' => null, 'role' => 'super_admin']);
    }

    private function generations(Agency $agency, User $user, int $n, array $attrs = []): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('ai_template_generations')->insert(array_merge([
                'agency_id' => $agency->id, 'user_id' => $user->id, 'idea' => 'x', 'ad_size' => 'facebook',
                'status' => 'success', 'created_at' => now(), 'updated_at' => now(),
            ], $attrs));
        }
    }

    private function aiTemplates(Agency $agency, User $user, int $n, bool $ai): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('property_ad_templates')->insert([
                'agency_id' => $agency->id, 'user_id' => $user->id, 'name' => 'T' . $i, 'layout_json' => '{}',
                'is_global' => 0, 'ai_generated' => $ai, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
