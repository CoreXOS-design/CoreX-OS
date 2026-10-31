<?php

namespace Tests\Feature\TrainingVideos;

use App\Models\Role;
use App\Models\TrainingVideo;
use App\Models\TrainingVideoGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Training Videos — owner admin + the public /training-videos page.
 *
 * Spec: .ai/specs/training-videos.md §7 (acceptance criteria)
 *
 * Every screen is requested for real rather than asserted to exist — a compiled Blade
 * file is not proof that it renders (same reasoning as WebinarAdminTest).
 */
class TrainingVideoTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/corex/admin/integrations/training-videos';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // owner_only reads the ROLE ROW's is_owner flag, which is not fillable.
        $ownerRole = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $ownerRole->is_owner = true;
        $ownerRole->save();
        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Agency Admin', 'sort_order' => 2]);
        Role::clearCache();

        $this->owner = User::factory()->create(['role' => 'super_admin', 'agency_id' => null]);
    }

    private function video(array $attrs = []): TrainingVideo
    {
        return TrainingVideo::create($attrs + [
            'title'            => 'Loading a listing',
            'youtube_url'      => 'https://youtu.be/dQw4w9WgXcQ',
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);
    }

    // ---- YouTube link parsing (D4) ------------------------------------------

    public function test_every_youtube_link_form_yields_the_video_id(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtube.com/watch?v=dQw4w9WgXcQ&t=42s&list=PL123',
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ?si=abc123',
            'youtu.be/dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'https://www.youtube.com/live/dQw4w9WgXcQ?feature=share',
            '  https://youtu.be/dQw4w9WgXcQ  ',
        ] as $url) {
            $this->assertSame('dQw4w9WgXcQ', TrainingVideo::extractYoutubeId($url), $url);
        }
    }

    public function test_non_youtube_links_are_rejected(): void
    {
        foreach ([
            '',
            'not a link',
            'https://vimeo.com/123456789',
            'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com/watch?v=short',
            'https://www.youtube.com/channel/UC1234567890',
            'https://www.youtube.com/watch',
        ] as $url) {
            $this->assertNull(TrainingVideo::extractYoutubeId($url), $url);
        }
    }

    // ---- The gate ------------------------------------------------------------

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(self::BASE)->assertRedirect();
    }

    public function test_an_agency_admin_is_refused_everywhere_including_direct_urls(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $video = $this->video();
        $group = TrainingVideoGroup::create(['name' => 'Basics']);

        $this->actingAs($admin);
        $this->get(self::BASE)->assertForbidden();
        $this->get(self::BASE . '/create')->assertForbidden();
        $this->post(self::BASE, ['title' => 'x', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertForbidden();
        $this->get(self::BASE . "/{$video->id}/edit")->assertForbidden();
        $this->put(self::BASE . "/{$video->id}", ['title' => 'x'])->assertForbidden();
        $this->delete(self::BASE . "/{$video->id}")->assertForbidden();
        $this->get(self::BASE . '/groups')->assertForbidden();
        $this->get(self::BASE . "/groups/{$group->id}/edit")->assertForbidden();
        $this->post(self::BASE . '/groups', ['name' => 'x'])->assertForbidden();
        $this->delete(self::BASE . "/groups/{$group->id}")->assertForbidden();

        $this->assertSame(1, TrainingVideo::count());
        $this->assertFalse($video->fresh()->trashed());
    }

    // ---- Screens ---------------------------------------------------------------

    public function test_every_admin_screen_renders_including_empty_states(): void
    {
        $this->actingAs($this->owner);

        $this->get(self::BASE)->assertOk()->assertSee('No training videos yet');
        $this->get(self::BASE . '/groups')->assertOk()->assertSee('No groups yet');

        $group = TrainingVideoGroup::create(['name' => 'Basics']);
        $video = $this->video(['training_video_group_id' => $group->id]);

        $this->get(self::BASE)->assertOk()->assertSee('Loading a listing')->assertSee('Basics');
        $this->get(self::BASE . '/create')->assertOk();
        $this->get(self::BASE . "/{$video->id}/edit")->assertOk()->assertSee('youtube-nocookie.com/embed/dQw4w9WgXcQ', false);
        $this->get(self::BASE . '/groups')->assertOk()->assertSee('Basics');
        $this->get(self::BASE . '/groups/create')->assertOk();
        $this->get(self::BASE . "/groups/{$group->id}/edit")->assertOk();
        $this->get(self::BASE . '?q=nothing-matches')->assertOk()->assertSee('No videos match these filters');
        $this->get(self::BASE . '/groups?q=nothing-matches')->assertOk()->assertSee('No groups match these filters');
    }

    public function test_every_sort_and_filter_renders(): void
    {
        $this->actingAs($this->owner);
        $group = TrainingVideoGroup::create(['name' => 'Basics']);
        $this->video(['training_video_group_id' => $group->id]);

        foreach (['title', 'group', 'created', 'updated'] as $sort) {
            foreach (['asc', 'desc'] as $dir) {
                $this->get(self::BASE . "?sort={$sort}&dir={$dir}")->assertOk()->assertSee('Loading a listing');
            }
        }
        foreach (['name', 'videos', 'created'] as $sort) {
            $this->get(self::BASE . "/groups?sort={$sort}&dir=desc")->assertOk()->assertSee('Basics');
        }

        $this->get(self::BASE . "?group={$group->id}&status=all&from=2000-01-01&to=2999-12-31&q=basics")
             ->assertOk()->assertSee('Loading a listing');
        $this->get(self::BASE . '?group=none')->assertOk()->assertDontSee('Loading a listing');
        $this->get(self::BASE . '?to=2000-01-01')->assertOk()->assertDontSee('Loading a listing');
    }

    // ---- Video CRUD ------------------------------------------------------------

    public function test_owner_adds_edits_archives_and_restores_a_video(): void
    {
        $this->actingAs($this->owner);
        $group = TrainingVideoGroup::create(['name' => 'Basics']);

        $this->post(self::BASE, [
            'title'                   => 'Signing a mandate',
            'youtube_url'             => 'https://www.youtube.com/shorts/abcdefghijk',
            'training_video_group_id' => $group->id,
            'description'             => 'Step by step.',
        ])->assertRedirect(self::BASE)->assertSessionHasNoErrors();

        $video = TrainingVideo::firstOrFail();
        $this->assertSame('abcdefghijk', $video->youtube_video_id);
        $this->assertSame($group->id, $video->training_video_group_id);
        $this->assertSame($this->owner->id, $video->created_by);

        $this->put(self::BASE . "/{$video->id}", [
            'title'       => 'Signing a sole mandate',
            'youtube_url' => 'https://youtu.be/zyxwvutsrqp',
            'description' => '',
        ])->assertRedirect(self::BASE);

        $video->refresh();
        $this->assertSame('Signing a sole mandate', $video->title);
        $this->assertSame('zyxwvutsrqp', $video->youtube_video_id);
        $this->assertNull($video->training_video_group_id);

        $this->delete(self::BASE . "/{$video->id}");
        $this->assertSoftDeleted('training_videos', ['id' => $video->id]);
        $this->get(self::BASE . '?status=archived')->assertSee('Signing a sole mandate');

        $this->post(self::BASE . "/{$video->id}/restore");
        $this->assertFalse($video->fresh()->trashed());
    }

    public function test_a_bad_link_is_rejected_with_a_plain_message_and_nothing_saved(): void
    {
        $this->actingAs($this->owner)
             ->post(self::BASE, ['title' => 'x', 'youtube_url' => 'https://vimeo.com/123'])
             ->assertSessionHasErrors(['youtube_url' => "That doesn't look like a YouTube link. Paste the link from the Share button on YouTube."]);

        $this->assertSame(0, TrainingVideo::withTrashed()->count());
    }

    public function test_an_archived_group_cannot_be_picked_but_a_video_already_in_it_keeps_it(): void
    {
        $this->actingAs($this->owner);
        $group = TrainingVideoGroup::create(['name' => 'Old']);
        $video = $this->video(['training_video_group_id' => $group->id]);
        $group->delete();

        $this->post(self::BASE, ['title' => 'New', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ', 'training_video_group_id' => $group->id])
             ->assertSessionHasErrors('training_video_group_id');

        $this->put(self::BASE . "/{$video->id}", ['title' => 'Renamed', 'youtube_url' => $video->youtube_url, 'training_video_group_id' => $group->id])
             ->assertSessionHasNoErrors();
        $this->assertSame($group->id, $video->fresh()->training_video_group_id);
    }

    // ---- Group CRUD ------------------------------------------------------------

    public function test_owner_creates_renames_archives_and_restores_a_group(): void
    {
        $this->actingAs($this->owner);

        $this->post(self::BASE . '/groups', ['name' => '  Getting started  '])->assertSessionHasNoErrors();
        $group = TrainingVideoGroup::firstOrFail();
        $this->assertSame('Getting started', $group->name);

        $this->post(self::BASE . '/groups', ['name' => 'Getting started'])->assertSessionHasErrors('name');

        $this->put(self::BASE . "/groups/{$group->id}", ['name' => 'Start here'])->assertSessionHasNoErrors();
        $this->assertSame('Start here', $group->fresh()->name);

        $this->delete(self::BASE . "/groups/{$group->id}");
        $this->assertSoftDeleted('training_video_groups', ['id' => $group->id]);

        // While archived, its name is free — but restoring must not create a duplicate.
        TrainingVideoGroup::create(['name' => 'Start here']);
        $this->post(self::BASE . "/groups/{$group->id}/restore")->assertSessionHasErrors('name');
        $this->assertTrue($group->fresh()->trashed());
    }

    // ---- Public page -----------------------------------------------------------

    public function test_public_page_works_logged_out_with_an_empty_state(): void
    {
        $this->get('/training-videos')->assertOk()->assertSee('Training videos are coming soon.');
    }

    public function test_public_page_shows_sections_alphabetically_then_ungrouped_and_hides_archived(): void
    {
        $zebra = TrainingVideoGroup::create(['name' => 'Zebra topics']);
        $alpha = TrainingVideoGroup::create(['name' => 'Alpha topics']);
        $gone  = TrainingVideoGroup::create(['name' => 'Gone group']);

        $this->video(['title' => 'In zebra', 'training_video_group_id' => $zebra->id]);
        $this->video(['title' => 'In alpha', 'training_video_group_id' => $alpha->id]);
        $this->video(['title' => 'No group video']);
        $this->video(['title' => 'In gone group', 'training_video_group_id' => $gone->id]);
        $this->video(['title' => 'Archived video'])->delete();
        $gone->delete();

        $this->get('/training-videos')
             ->assertOk()
             ->assertSeeInOrder(['Alpha topics', 'In alpha', 'Zebra topics', 'In zebra', 'More videos', 'No group video'])
             ->assertSee('youtube-nocookie.com/embed/dQw4w9WgXcQ', false)
             ->assertDontSee('In gone group')
             ->assertDontSee('Gone group')
             ->assertDontSee('Archived video');

        // Restoring the group brings its videos back (D10).
        $gone->restore();
        $this->get('/training-videos')->assertSee('In gone group');
    }

    public function test_public_search_and_no_match_state(): void
    {
        $this->video(['title' => 'Loading a listing']);
        $this->video(['title' => 'Signing a mandate']);

        $this->get('/training-videos?q=mandate')->assertOk()->assertSee('Signing a mandate')->assertDontSee('Loading a listing');
        $this->get('/training-videos?q=zzz')->assertOk()->assertSee('No videos match');
    }

    public function test_description_html_is_escaped_on_the_public_page(): void
    {
        $this->video(['description' => '<script>alert(1)</script>']);

        $this->get('/training-videos')
             ->assertOk()
             ->assertDontSee('<script>alert(1)</script>', false)
             ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
