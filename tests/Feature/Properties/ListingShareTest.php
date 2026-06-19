<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Listing share actions partial — copy / WhatsApp / email a public link.
 * Spec: .ai/specs/listing-share-link.md
 *
 * Verifies the gating logic: permission (properties.share) + a publicly-
 * shareable status, and that the rendered link is the canonical public_url.
 */
final class ListingShareTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Reset the permission seed flag so each test controls the system state.
        PermissionService::clearCache();

        $this->agency = Agency::create([
            'name' => 'Share Test Agency',
            'slug' => 'share-test-' . uniqid(),
        ]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'agent',
        ]);
    }

    private function makeProperty(string $status): Property
    {
        return Property::create([
            'title'     => 'Seaside Villa',
            'status'    => $status,
            'agency_id' => $this->agency->id,
            'agent_id'  => $this->user->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    private function renderShare(Property $p): string
    {
        $this->actingAs($this->user);

        return view('corex.properties.partials.share-actions', ['property' => $p])->render();
    }

    public function test_renders_share_with_public_url_for_active_listing(): void
    {
        // Unseeded role_permissions → permission granted (graceful test path).
        $p = $this->makeProperty('active');

        $html = $this->renderShare($p);

        $this->assertStringContainsString('Share', $html);
        // Share targets are wired (copy / WhatsApp / email).
        $this->assertStringContainsString('wa.me', $html);
        $this->assertStringContainsString('mailto:', $html);
        // The exact public_url is bound into the component (@js JSON-encodes it,
        // escaping slashes — assert the encoded form so the test is robust).
        $this->assertStringContainsString(trim(json_encode($p->public_url), '"'), $html);
    }

    public function test_hidden_for_non_shareable_status(): void
    {
        foreach (['draft', 'withdrawn', 'sold'] as $status) {
            $html = $this->renderShare($this->makeProperty($status));
            $this->assertStringNotContainsString('Share', $html, "draft/withdrawn/sold must not be shareable ({$status})");
        }
    }

    public function test_hidden_without_share_permission(): void
    {
        // Seed the permission table (flips PermissionService into enforcing mode)
        // with an agent role that lacks properties.share.
        RolePermission::create(['role' => 'agent', 'permission_key' => 'properties.view', 'scope' => 'own']);
        PermissionService::clearCache();

        $html = $this->renderShare($this->makeProperty('active'));

        $this->assertStringNotContainsString('Share', $html);
    }
}
