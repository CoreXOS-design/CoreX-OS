<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserNavFavourite;
use App\Services\Navigation\NavFavouriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sidebar Favourites — the server contract for a user's own pinned pages.
 * Spec: .ai/specs/sidebar-favourites.md §10
 *
 * Deliberate split of concerns: this file proves the SERVER contract — what is
 * stored, what is refused, and what one user can never do to another. Whether
 * the control a human clicks actually works (panel opens upwards, capped at half
 * the sidebar, tick/reorder/save round-trips) is proven separately in a real
 * browser, because PHPUnit cannot see a panel or a disabled button
 * (STANDARDS Standard −1f).
 */
final class SidebarFavouritesTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTIES = 'p:/corex/properties';   // permission:access_properties
    private const CONTACTS   = 'p:/corex/contacts';     // permission:access_contacts
    private const EARNINGS   = 'p:/corex/my-earnings';  // no permission middleware

    // ── Happy path ───────────────────────────────────────────────────────

    public function test_a_user_pins_three_pages_and_they_come_back_in_the_chosen_order(): void
    {
        [, $user] = $this->agencyWithUser();

        $this->actingAs($user)->put(route('agent.portal.favourites.update'), [
            'auto_open' => '0',
            'favourites' => [
                ['key' => self::EARNINGS,   'label' => 'My Earnings'],
                ['key' => self::PROPERTIES, 'label' => 'Properties'],
                ['key' => self::CONTACTS,   'label' => 'Contacts'],
            ],
        ])->assertRedirect();

        $this->assertSame(
            ['My Earnings', 'Properties', 'Contacts'],
            $this->favourites($user)->pluck('label')->all()
        );
    }

    /** The minimum legal input: one page, nothing else touched. */
    public function test_the_lazy_but_valid_shortcut_of_a_single_page_works_end_to_end(): void
    {
        [, $user] = $this->agencyWithUser();

        $this->actingAs($user)->put(route('agent.portal.favourites.update'), [
            'favourites' => [['key' => self::PROPERTIES, 'label' => 'Properties']],
        ])->assertRedirect();

        $this->assertSame(['Properties'], $this->favourites($user)->pluck('label')->all());
    }

    // ── Each optional field omitted, individually ────────────────────────

    public function test_a_post_with_no_label_still_stores_a_readable_name(): void
    {
        [, $user] = $this->agencyWithUser();

        $this->actingAs($user)->put(route('agent.portal.favourites.update'), [
            'favourites' => [['key' => self::PROPERTIES]],
        ])->assertRedirect();

        // label is NOT NULL — it always gets a value, for every input combination.
        $this->assertSame('Properties', UserNavFavourite::where('user_id', $user->id)->value('label'));
    }

    public function test_a_post_with_no_auto_open_field_leaves_that_setting_untouched(): void
    {
        [, $user] = $this->agencyWithUser();
        $user->forceFill(['nav_favourites_autoopen' => true])->save();

        $this->actingAs($user)->put(route('agent.portal.favourites.update'), [
            'favourites' => [['key' => self::PROPERTIES, 'label' => 'Properties']],
        ])->assertRedirect();

        $this->assertTrue((bool) $user->fresh()->nav_favourites_autoopen,
            'a post that never rendered the control must not silently wipe the setting');
    }

    public function test_the_auto_open_setting_persists_both_ways(): void
    {
        [, $user] = $this->agencyWithUser();

        $this->actingAs($user)->put(route('agent.portal.favourites.update'), ['auto_open' => '1']);
        $this->assertTrue((bool) $user->fresh()->nav_favourites_autoopen);

        $this->actingAs($user)->put(route('agent.portal.favourites.update'), ['auto_open' => '0']);
        $this->assertFalse((bool) $user->fresh()->nav_favourites_autoopen);
    }

    // ── Empty / clearing ─────────────────────────────────────────────────

    public function test_saving_with_nothing_ticked_clears_the_list_without_an_error(): void
    {
        [, $user] = $this->agencyWithUser();
        $this->pin($user, [self::PROPERTIES => 'Properties', self::CONTACTS => 'Contacts']);

        $this->actingAs($user)->put(route('agent.portal.favourites.update'), ['favourites' => []])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertCount(0, $this->favourites($user));
        $this->assertSame(2, UserNavFavourite::onlyTrashed()->where('user_id', $user->id)->count(),
            'clearing archives the rows, it never hard-deletes them');
    }

    // ── Archive / restore (the UNIQUE + SoftDeletes collision) ───────────

    public function test_un_pinning_archives_the_row_rather_than_deleting_it(): void
    {
        [, $user] = $this->agencyWithUser();
        $this->pin($user, [self::PROPERTIES => 'Properties', self::CONTACTS => 'Contacts']);

        $this->actingAs($user)->put(route('agent.portal.favourites.update'), [
            'favourites' => [['key' => self::CONTACTS, 'label' => 'Contacts']],
        ]);

        $this->assertSame(['Contacts'], $this->favourites($user)->pluck('label')->all());
        $this->assertDatabaseHas('user_nav_favourites', ['user_id' => $user->id, 'nav_key' => self::PROPERTIES]);
        $this->assertNotNull(
            UserNavFavourite::withTrashed()->where('user_id', $user->id)->where('nav_key', self::PROPERTIES)->value('deleted_at')
        );
    }

    /**
     * The documented trap: MySQL's unique index has no soft-delete awareness,
     * and Eloquent's default scope hides the trashed row from the query that
     * would find it. A naive create() here throws a raw duplicate-key error at
     * the user the second time they pin the same page. BUILD_STANDARD §5a.
     */
    public function test_re_pinning_a_previously_un_pinned_page_restores_it_with_no_duplicate_row(): void
    {
        [, $user] = $this->agencyWithUser();

        $this->pin($user, [self::PROPERTIES => 'Properties']);
        $this->actingAs($user)->put(route('agent.portal.favourites.update'), ['favourites' => []]);
        $this->pin($user, [self::PROPERTIES => 'Properties']);

        $this->assertSame(['Properties'], $this->favourites($user)->pluck('label')->all());
        $this->assertSame(1, UserNavFavourite::withTrashed()
            ->where('user_id', $user->id)->where('nav_key', self::PROPERTIES)->count(),
            'create -> archive -> recreate leaves exactly one row, restored, not a second one');
    }

    public function test_reordering_persists(): void
    {
        [, $user] = $this->agencyWithUser();
        $this->pin($user, [self::PROPERTIES => 'Properties', self::CONTACTS => 'Contacts']);

        $this->actingAs($user)->put(route('agent.portal.favourites.update'), [
            'favourites' => [
                ['key' => self::CONTACTS,   'label' => 'Contacts'],
                ['key' => self::PROPERTIES, 'label' => 'Properties'],
            ],
        ]);

        $this->assertSame(['Contacts', 'Properties'], $this->favourites($user)->pluck('label')->all());
    }

    // ── Refusals (prevent, never crash) ──────────────────────────────────

    public function test_a_page_the_user_may_not_open_is_refused_while_the_rest_still_save(): void
    {
        [$agencyId, $user] = $this->agencyWithUser(role: 'agent');
        // Grant contacts only. Seeding SOME grants disables the unseeded allow-all
        // fallback, so access_properties is genuinely absent for this role.
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_contacts', 'agency_id' => $agencyId]);

        $this->actingAs($user)->put(route('agent.portal.favourites.update'), [
            'favourites' => [
                ['key' => self::PROPERTIES, 'label' => 'Properties'],
                ['key' => self::CONTACTS,   'label' => 'Contacts'],
            ],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(['Contacts'], $this->favourites($user)->pluck('label')->all(),
            'the page they cannot open is dropped; the one they can still saves');
        $this->assertDatabaseMissing('user_nav_favourites', ['user_id' => $user->id, 'nav_key' => self::PROPERTIES]);
    }

    public function test_a_path_that_matches_no_page_is_refused(): void
    {
        [, $user] = $this->agencyWithUser();

        $this->actingAs($user)->put(route('agent.portal.favourites.update'), [
            'favourites' => [['key' => 'p:/corex/this-page-does-not-exist', 'label' => 'Nope']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertCount(0, $this->favourites($user));
    }

    /** @dataProvider malformedKeys */
    public function test_a_malformed_key_is_refused(string $key): void
    {
        [, $user] = $this->agencyWithUser();

        $this->actingAs($user)->put(route('agent.portal.favourites.update'), [
            'favourites' => [['key' => $key, 'label' => 'Bad']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, UserNavFavourite::where('user_id', $user->id)->count(), $key);
    }

    public static function malformedKeys(): array
    {
        return [
            'external host' => ['https://evil.example/corex/properties'],
            'protocol relative' => ['//evil.example/x'],
            'javascript scheme' => ['javascript:alert(1)'],
            'traversal' => ['p:/../../etc/passwd'],
            'relative path' => ['corex/properties'],
            'empty' => [''],
            'whitespace only' => ['   '],
        ];
    }

    public function test_more_than_the_maximum_is_refused_with_a_clear_message_and_nothing_is_saved(): void
    {
        [, $user] = $this->agencyWithUser();

        $tooMany = [];
        for ($i = 0; $i <= UserNavFavourite::MAX_PER_USER; $i++) {
            $tooMany[] = ['key' => 'p:/corex/page-'.$i, 'label' => 'Page '.$i];
        }

        $this->actingAs($user)->put(route('agent.portal.favourites.update'), ['favourites' => $tooMany])
            ->assertSessionHasErrors('favourites');

        $this->assertSame(0, UserNavFavourite::where('user_id', $user->id)->count(),
            'a refused save writes nothing at all — no silent truncation');
    }

    public function test_the_same_page_ticked_twice_is_absorbed_as_one_favourite(): void
    {
        [, $user] = $this->agencyWithUser();

        $this->actingAs($user)->put(route('agent.portal.favourites.update'), [
            'favourites' => [
                ['key' => self::PROPERTIES,        'label' => 'Properties'],
                ['key' => '/corex/properties/',    'label' => 'Properties'],
                ['key' => ' p:/corex/properties ', 'label' => 'Properties'],
            ],
        ])->assertRedirect();

        $this->assertSame(1, UserNavFavourite::where('user_id', $user->id)->count());
    }

    // ── Scoping ──────────────────────────────────────────────────────────

    public function test_one_user_never_sees_or_touches_another_users_favourites(): void
    {
        [$agencyId, $alice] = $this->agencyWithUser();
        $bob = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin']);

        $this->pin($alice, [self::PROPERTIES => 'Properties']);

        $this->assertCount(0, $this->favourites($bob), "Bob sees none of Alice's favourites");

        // There is no id in the payload to substitute — Bob's own save can only
        // ever write Bob's rows, whatever he posts.
        $this->actingAs($bob)->put(route('agent.portal.favourites.update'), [
            'favourites' => [['key' => self::CONTACTS, 'label' => 'Contacts']],
        ]);

        $this->assertSame(['Properties'], $this->favourites($alice)->pluck('label')->all(),
            "Alice's list is untouched by Bob's save");
        $this->assertSame(['Contacts'], $this->favourites($bob)->pluck('label')->all());
    }

    public function test_a_guest_cannot_save_favourites(): void
    {
        $this->put(route('agent.portal.favourites.update'), [
            'favourites' => [['key' => self::PROPERTIES, 'label' => 'Properties']],
        ])->assertRedirect(route('login'));

        $this->assertSame(0, UserNavFavourite::count());
    }

    // ── Graceful degradation ─────────────────────────────────────────────

    public function test_a_favourite_whose_page_no_longer_exists_is_skipped_rather_than_shown_as_a_dead_link(): void
    {
        [, $user] = $this->agencyWithUser();
        $this->pin($user, [self::PROPERTIES => 'Properties']);

        // Simulate a page renamed or removed since it was pinned.
        UserNavFavourite::create([
            'user_id' => $user->id,
            'nav_key' => 'p:/corex/a-page-that-was-removed',
            'label' => 'Old Page',
            'sort_order' => 9,
        ]);

        $live = $this->favourites($user);

        $this->assertSame(['Properties'], $live->pluck('label')->all(),
            'the stale pin is dropped from the panel, not rendered as a broken link');
        $this->assertDatabaseHas('user_nav_favourites', ['nav_key' => 'p:/corex/a-page-that-was-removed'],
            'the row survives — a page can come back, and we never hard-delete');
    }

    public function test_the_favourites_tab_renders_on_my_profile(): void
    {
        [, $user] = $this->agencyWithUser();
        $this->pin($user, [self::PROPERTIES => 'Properties']);

        $this->actingAs($user)->get(route('agent.portal'))
            ->assertOk()
            ->assertSee('Favourites')
            ->assertSee('All pages you can open')
            ->assertSee('Open my Favourites automatically when I sign in');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function agencyWithUser(string $role = 'admin'): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Favourites '.Str::random(6),
            'slug' => 'fav-'.Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Shelly Beach',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$agencyId, User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => $role,
        ])];
    }

    /** @param array<string,string> $keyToLabel */
    private function pin(User $user, array $keyToLabel): void
    {
        $payload = [];
        foreach ($keyToLabel as $key => $label) {
            $payload[] = ['key' => $key, 'label' => $label];
        }

        $this->actingAs($user)->put(route('agent.portal.favourites.update'), ['favourites' => $payload]);
    }

    private function favourites(User $user)
    {
        // A fresh service per read — the per-request route/permission memo must
        // never leak an earlier user's answer into a later assertion.
        return (new NavFavouriteService())->forUser($user->fresh());
    }
}
