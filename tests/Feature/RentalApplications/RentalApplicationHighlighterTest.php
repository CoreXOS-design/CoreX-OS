<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalApplicationHighlighter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Highlighter collection expansion, 2026-09-09 — Johan: "we allow an
 * agency to set up which highlighters they want... as many as they want,
 * each with their own label." Covers: seeding, the never-double-seed
 * guard, full CRUD (create/update/archive/restore/reorder), archived
 * highlighters staying resolvable, and role-scope filtering.
 */
final class RentalApplicationHighlighterTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
    }

    private function owner(): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    public function test_seed_defaults_creates_the_six_starting_highlighters(): void
    {
        RentalApplicationHighlighter::seedDefaultsFor($this->agency->id);

        $rows = RentalApplicationHighlighter::where('agency_id', $this->agency->id)->get();
        $this->assertCount(6, $rows);
        $this->assertSame(3, $rows->where('role_scope', 'agent')->count());
        $this->assertSame(3, $rows->where('role_scope', 'authoriser')->count());
        $this->assertEqualsCanonicalizing(['Income', 'Expense', 'Unpaid'], $rows->where('role_scope', 'agent')->pluck('label')->all());
    }

    public function test_seed_defaults_is_idempotent_and_never_double_seeds(): void
    {
        RentalApplicationHighlighter::seedDefaultsFor($this->agency->id);
        RentalApplicationHighlighter::seedDefaultsFor($this->agency->id);

        $this->assertSame(6, RentalApplicationHighlighter::where('agency_id', $this->agency->id)->count());
    }

    public function test_seed_defaults_preserves_already_customised_colours(): void
    {
        RentalApplicationHighlighter::seedDefaultsFor($this->agency->id, [
            'agent' => ['income' => '#111111', 'expense' => '#222222', 'unpaid' => '#333333'],
            'authoriser' => ['income' => '#444444', 'expense' => '#555555', 'unpaid' => '#666666'],
        ]);

        $income = RentalApplicationHighlighter::where('agency_id', $this->agency->id)
            ->where('label', 'Income')->where('role_scope', 'agent')->firstOrFail();
        $this->assertSame('#111111', $income->color);
    }

    public function test_picker_for_excludes_archived_and_the_other_roles_own_exclusive_highlighters(): void
    {
        RentalApplicationHighlighter::seedDefaultsFor($this->agency->id);
        $agentIncome = RentalApplicationHighlighter::where('agency_id', $this->agency->id)->where('label', 'Income')->where('role_scope', 'agent')->firstOrFail();
        $agentIncome->delete(); // archived

        $agentPicker = RentalApplicationHighlighter::pickerFor($this->agency->id, 'agent');
        $authoriserPicker = RentalApplicationHighlighter::pickerFor($this->agency->id, 'authoriser');

        $this->assertCount(2, $agentPicker, 'archived Income must not appear in the agent picker');
        $this->assertFalse($agentPicker->contains('label', 'Income'));
        $this->assertCount(3, $authoriserPicker, 'the authoriser picker is unaffected by an agent-scope archive');
    }

    public function test_a_both_scope_highlighter_appears_in_either_roles_picker(): void
    {
        RentalApplicationHighlighter::create([
            'agency_id' => $this->agency->id, 'label' => 'Deposit', 'color' => '#2d6cdf', 'role_scope' => 'both', 'sort_order' => 0,
        ]);

        $this->assertTrue(RentalApplicationHighlighter::pickerFor($this->agency->id, 'agent')->contains('label', 'Deposit'));
        $this->assertTrue(RentalApplicationHighlighter::pickerFor($this->agency->id, 'authoriser')->contains('label', 'Deposit'));
    }

    public function test_all_for_includes_archived_highlighters_for_colour_resolution(): void
    {
        $h = RentalApplicationHighlighter::create([
            'agency_id' => $this->agency->id, 'label' => 'Deposit', 'color' => '#2d6cdf', 'role_scope' => 'agent', 'sort_order' => 0,
        ]);
        $h->delete();

        $all = RentalApplicationHighlighter::allFor($this->agency->id);
        $this->assertCount(1, $all);
        $this->assertSame('#2d6cdf', $all->first()->color, 'an archived highlighter must still resolve its own colour');
    }

    public function test_full_crud_via_the_settings_screen_controller(): void
    {
        $owner = $this->owner();

        // Create.
        $this->actingAs($owner)->post(route('corex.settings.rental-applications.highlighters.store'), [
            'label' => 'Deposit Proof', 'color' => '#2d6cdf', 'role_scope' => 'agent',
        ])->assertSessionDoesntHaveErrors();
        $h = RentalApplicationHighlighter::where('agency_id', $this->agency->id)->where('label', 'Deposit Proof')->firstOrFail();

        // Update — recolour.
        $this->actingAs($owner)->put(route('corex.settings.rental-applications.highlighters.update', $h), [
            'label' => 'Deposit Proof', 'color' => '#ff0000', 'role_scope' => 'both',
        ])->assertSessionDoesntHaveErrors();
        $h->refresh();
        $this->assertSame('#ff0000', $h->color);
        $this->assertSame('both', $h->role_scope);

        // Archive.
        $this->actingAs($owner)->post(route('corex.settings.rental-applications.highlighters.archive', $h))
            ->assertSessionDoesntHaveErrors();
        $this->assertTrue($h->fresh()->trashed());
        $this->assertCount(0, RentalApplicationHighlighter::pickerFor($this->agency->id, 'agent')->where('id', $h->id));

        // Restore.
        $this->actingAs($owner)->post(route('corex.settings.rental-applications.highlighters.restore', $h->id))
            ->assertSessionDoesntHaveErrors();
        $this->assertFalse($h->fresh()->trashed());
    }

    /**
     * created_by, 2026-09-09 — Johan asked who created "ZZ Deposit Verify"
     * and the table had no answer at all. Set going forward from the
     * authenticated user, never backfilled for the rows created before it
     * existed.
     */
    public function test_store_records_the_authenticated_user_as_creator(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.highlighters.store'), [
            'label' => 'Pet Deposit', 'color' => '#2d6cdf', 'role_scope' => 'agent',
        ])->assertSessionDoesntHaveErrors();

        $h = RentalApplicationHighlighter::where('agency_id', $this->agency->id)->where('label', 'Pet Deposit')->firstOrFail();
        $this->assertSame($owner->id, $h->created_by);
        $this->assertTrue($h->creator->is($owner));
    }

    public function test_an_invalid_colour_is_rejected(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.highlighters.store'), [
            'label' => 'Bad', 'color' => 'not-a-colour', 'role_scope' => 'agent',
        ])->assertSessionHasErrors('color');

        $this->assertSame(0, RentalApplicationHighlighter::where('agency_id', $this->agency->id)->count());
    }

    /**
     * BelongsToAgency's own AgencyScope global scope already hides a
     * cross-agency row from implicit route-model-binding for a scoped
     * (non-owner-role) user — the row simply doesn't resolve, so this 404s
     * before RentalApplicationHighlighterController::authorizeAgency() ever
     * runs. That's the same existence-hiding behaviour every other
     * agency-scoped model + implicit route binding gets in this codebase;
     * authorizeAgency() remains as defense-in-depth for the owner-role
     * cross-agency-bypass case (see AgencyScope's own docblock) and for
     * restore(), which explicitly uses withTrashed().
     */
    public function test_a_user_cannot_update_another_agencys_highlighter(): void
    {
        $owner = $this->owner();
        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $foreign = RentalApplicationHighlighter::create([
            'agency_id' => $otherAgency->id, 'label' => 'Not Yours', 'color' => '#000000', 'role_scope' => 'agent', 'sort_order' => 0,
        ]);

        $this->actingAs($owner)->put(route('corex.settings.rental-applications.highlighters.update', $foreign), [
            'label' => 'Hijacked', 'color' => '#ffffff', 'role_scope' => 'agent',
        ])->assertNotFound();

        $this->assertSame('Not Yours', $foreign->fresh()->label);
    }

    public function test_reorder_persists_the_new_sort_order(): void
    {
        $owner = $this->owner();
        RentalApplicationHighlighter::seedDefaultsFor($this->agency->id);
        $agentRows = RentalApplicationHighlighter::where('agency_id', $this->agency->id)->where('role_scope', 'agent')->orderBy('sort_order')->get();
        $reversed = $agentRows->pluck('id')->reverse()->values()->all();

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.highlighters.reorder'), [
            'order' => $reversed,
        ])->assertSessionDoesntHaveErrors();

        $reloaded = RentalApplicationHighlighter::where('agency_id', $this->agency->id)->where('role_scope', 'agent')->orderBy('sort_order')->pluck('id')->all();
        $this->assertSame($reversed, $reloaded);
    }
}
