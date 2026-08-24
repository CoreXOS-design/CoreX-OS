<?php

declare(strict_types=1);

namespace Tests\Feature\SystemUpdates;

use App\Models\SystemUpdate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The whole input space — spec §9.1, §9.2 and BUILD_STANDARD §2.
 *
 * Every optional field is omitted individually, the lazy-but-valid shortcut is
 * proven end to end, and every malformed input is rejected with a message rather
 * than a 500.
 */
final class SystemUpdateValidationTest extends SystemUpdateTestCase
{
    private function base(array $overrides = []): array
    {
        return array_merge([
            'title'    => 'Contacts now show every phone number on one card',
            'body'     => 'All numbers for a contact appear together, with the primary one first.',
            'type'     => 'improvement',
        ], $overrides);
    }

    // ── Required-but-empty → prevented ──────────────────────────────────────

    public function test_an_empty_title_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.system-updates.store'), $this->base(['title' => '']))
            ->assertSessionHasErrors('title');

        $this->assertDatabaseCount('system_updates', 0);
    }

    public function test_an_empty_body_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.system-updates.store'), $this->base(['body' => '']))
            ->assertSessionHasErrors('body');
    }

    /** Whitespace is trimmed before validation, so a spaces-only title is empty. */
    public function test_a_whitespace_only_title_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.system-updates.store'), $this->base(['title' => '     ']))
            ->assertSessionHasErrors('title');
    }

    public function test_an_over_length_title_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.system-updates.store'), $this->base(['title' => str_repeat('a', 161)]))
            ->assertSessionHasErrors('title');
    }

    public function test_an_over_length_body_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.system-updates.store'), $this->base(['body' => str_repeat('a', 5001)]))
            ->assertSessionHasErrors('body');
    }

    // ── The lazy-but-valid shortcut — a first-class path ────────────────────

    public function test_type_title_and_body_alone_succeed_end_to_end(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());

        $this->actingAs($this->owner)
            ->post(route('admin.system-updates.store'), $this->base(['publish_now' => '1']))
            ->assertRedirect();

        $update = SystemUpdate::firstOrFail();

        $this->assertNull($update->link_url);
        $this->assertNull($update->link_label);
        $this->assertNull($update->image_path);
        $this->assertFalse($update->hasLink());
        $this->assertCount(1, $this->service()->pendingFor($this->agent));

        // And it renders — an absent link/image must not break the modal.
        $this->actingAs($this->agent)->get(route('corex.whats-new.index'))
            ->assertOk()
            ->assertSee($update->title);
    }

    // ── Optional-and-empty, each individually → absorbed ────────────────────

    public function test_a_label_with_no_url_renders_no_button(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.system-updates.store'), $this->base(['link_label' => 'Go look', 'link_url' => '']))
            ->assertSessionHasNoErrors();

        $this->assertFalse(SystemUpdate::firstOrFail()->hasLink());
    }

    public function test_a_url_with_no_label_defaults_the_button_text(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.system-updates.store'), $this->base(['link_url' => '/corex/properties', 'link_label' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Take me there', SystemUpdate::firstOrFail()->linkLabelOrDefault());
    }

    // ── Malformed → prevented ───────────────────────────────────────────────

    public function test_a_javascript_url_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.system-updates.store'), $this->base(['link_url' => 'javascript:alert(1)']))
            ->assertSessionHasErrors('link_url');

        $this->assertDatabaseCount('system_updates', 0);
    }

    public function test_a_data_uri_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.system-updates.store'), $this->base(['link_url' => 'data:text/html;base64,PHNjcmlwdD4=']))
            ->assertSessionHasErrors('link_url');
    }

    public function test_a_protocol_relative_url_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.system-updates.store'), $this->base(['link_url' => '//evil.example.com']))
            ->assertSessionHasErrors('link_url');
    }

    public function test_an_internal_path_and_an_https_url_are_both_accepted(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.system-updates.store'), $this->base(['link_url' => '/corex/properties']))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)
            ->post(route('admin.system-updates.store'), $this->base(['link_url' => 'https://corexos.co.za/help']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('system_updates', 2);
    }

    // ── Tampered vocabulary → rejected, never stored ────────────────────────

    public function test_a_tampered_type_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.system-updates.store'), $this->base(['type' => 'catastrophe']))
            ->assertSessionHasErrors('type');
    }

    /** An absent type is rejected rather than silently defaulting. */
    public function test_a_missing_type_is_rejected(): void
    {
        $payload = $this->base();
        unset($payload['type']);

        $this->actingAs($this->owner)
            ->post(route('admin.system-updates.store'), $payload)
            ->assertSessionHasErrors('type');
    }

    // ── Image ───────────────────────────────────────────────────────────────

    public function test_a_valid_image_is_stored(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)
            ->post(route('admin.system-updates.store'), $this->base([
                'image' => UploadedFile::fake()->image('viewing-packs.png', 800, 500),
            ]))
            ->assertSessionHasNoErrors();

        $update = SystemUpdate::firstOrFail();
        $this->assertNotNull($update->image_path);
        Storage::disk('public')->assertExists($update->image_path);
    }

    public function test_an_oversize_image_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)
            ->post(route('admin.system-updates.store'), $this->base([
                'image' => UploadedFile::fake()->create('huge.png', 5000, 'image/png'),
            ]))
            ->assertSessionHasErrors('image');
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)
            ->post(route('admin.system-updates.store'), $this->base([
                'image' => UploadedFile::fake()->create('mandate.pdf', 100, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('image');
    }
}
