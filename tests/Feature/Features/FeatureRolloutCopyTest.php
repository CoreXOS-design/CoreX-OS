<?php

namespace Tests\Feature\Features;

use App\Models\Agency;
use App\Models\FeatureRollout\FeatureRolloutFeatureCopy;
use App\Models\Role;
use App\Models\User;
use App\Services\FeatureRollout\FeatureRolloutCopy;
use App\Services\FeatureRollout\FeatureRolloutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-478 §19.4 — Dev Settings → Feature wording & videos: owner-only CRUD (edit, go back to the default = archive,
 * restore), safe links, and the wording reaching FeatureRolloutCopy.
 */
final class FeatureRolloutCopyTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'branch_id' => null, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    public function test_every_feature_a_group_can_hold_has_a_default_full_explanation(): void
    {
        $missing = array_diff(array_keys(app(FeatureRolloutService::class)->waveableFeatures()), array_keys((array) config('feature-rollout-copy.descriptions')));
        $this->assertSame([], array_values($missing));
    }

    public function test_owner_lists_edits_reverts_and_restores_the_wording(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->get(route('admin.feature-rollout-defaults.index'))->assertOk()->assertSee('Feature wording &amp; videos', false);
        $this->get(route('admin.feature-rollout-defaults.copy.index'))->assertOk()->assertSee('data-copy-row="docuperfect"', false);
        $this->get(route('admin.feature-rollout-defaults.copy.index', ['q' => 'e-signature']))->assertOk()->assertSee('data-copy-row="docuperfect"', false)->assertDontSee('data-copy-row="billing"', false);
        $this->get(route('admin.feature-rollout-defaults.copy.edit', 'docuperfect'))->assertOk()->assertSee('data-copy-form', false);

        $this->put(route('admin.feature-rollout-defaults.copy.update', 'docuperfect'), [
            'summary' => 'Documents and signatures.',
            'description' => "One.\n\nTwo.",
            'video_url' => 'https://videos.example.test/v1',
            'links' => [['label' => 'Guide', 'url' => '/corex/training'], ['label' => '', 'url' => '']],
        ])->assertRedirect(route('admin.feature-rollout-defaults.copy.edit', 'docuperfect'));

        $copy = app(FeatureRolloutCopy::class)->for('docuperfect');
        $this->assertSame('Documents and signatures.', $copy['summary']);
        $this->assertSame(['One.', 'Two.'], $copy['paragraphs']);
        $this->assertSame('https://videos.example.test/v1', $copy['video_url']);
        $this->assertEquals([['label' => 'Guide', 'url' => '/corex/training']], $copy['links']); // MySQL re-orders JSON keys
        $this->get(route('admin.feature-rollout-defaults.copy.index', ['show' => 'video']))->assertSee('data-copy-row="docuperfect"', false)->assertDontSee('data-copy-row="billing"', false);

        // Go back to the default = archive (soft), never a hard delete.
        $this->delete(route('admin.feature-rollout-defaults.copy.destroy', 'docuperfect'))->assertRedirect();
        $this->assertSoftDeleted('feature_rollout_feature_copy', ['feature_key' => 'docuperfect']);
        $this->assertNull(app(FeatureRolloutCopy::class)->for('docuperfect')['video_url']);
        $this->get(route('admin.feature-rollout-defaults.copy.index', ['show' => 'reverted']))->assertSee('data-copy-row="docuperfect"', false);

        $this->post(route('admin.feature-rollout-defaults.copy.restore', 'docuperfect'))->assertRedirect();
        $this->assertSame('https://videos.example.test/v1', app(FeatureRolloutCopy::class)->for('docuperfect')['video_url']);
    }

    public function test_unsafe_links_are_refused_and_default_wording_is_not_frozen(): void
    {
        $this->actingAs($this->owner());
        $this->put(route('admin.feature-rollout-defaults.copy.update', 'billing'), ['video_url' => 'javascript:alert(1)'])->assertSessionHasErrors('video_url');
        $this->put(route('admin.feature-rollout-defaults.copy.update', 'billing'), ['video_url' => 'http://insecure.example.test'])->assertSessionHasErrors('video_url');
        $this->put(route('admin.feature-rollout-defaults.copy.update', 'billing'), ['links' => [['label' => 'x', 'url' => '//evil.example.test']]])->assertSessionHasErrors('links.0.url');
        $this->put(route('admin.feature-rollout-defaults.copy.update', 'billing'), ['links' => [['label' => '', 'url' => 'https://ok.example.test']]])->assertSessionHasErrors('links.0.label');
        $this->assertSame(0, FeatureRolloutFeatureCopy::count());

        // Saving the default text unchanged stores nothing, so later default improvements still arrive.
        $this->put(route('admin.feature-rollout-defaults.copy.update', 'billing'), [
            'description' => (string) config('feature-rollout-copy.descriptions.billing'),
            'video_url' => 'https://videos.example.test/billing',
        ])->assertRedirect();
        $this->assertNull(FeatureRolloutFeatureCopy::where('feature_key', 'billing')->value('description'));
    }

    public function test_only_the_system_owner_can_open_or_change_it(): void
    {
        $agency = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'other-' . uniqid()]);
        $admin = User::factory()->create(['role' => 'admin', 'agency_id' => $agency->id, 'is_active' => true]);
        $this->actingAs($admin)->get(route('admin.feature-rollout-defaults.copy.index'))->assertForbidden();
        $this->put(route('admin.feature-rollout-defaults.copy.update', 'billing'), ['summary' => 'x'])->assertForbidden();
        $this->assertSame(0, FeatureRolloutFeatureCopy::count());

        $this->actingAs($this->owner())->get(route('admin.feature-rollout-defaults.copy.edit', 'not-a-feature'))->assertNotFound();
    }
}
