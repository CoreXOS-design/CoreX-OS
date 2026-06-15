<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-43 Fix 3 — the contact detail page surfaces that contact's linked archive
 * communications, gated by access_communication_archive.
 */
final class ContactCommunicationsTabTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'T ' . Str::random(5), 'slug' => 'tt-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'D',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function contactWithComm(): Contact
    {
        $contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Can', 'last_name' => 'Assurance',
            'phone' => '', 'email' => 'can.assurance@gmail.com',
        ]);

        $comm = Communication::create([
            'agency_id'               => $this->agencyId,
            'channel'                 => Communication::CHANNEL_EMAIL,
            'direction'               => Communication::DIRECTION_OUTBOUND,
            'external_id'             => '<' . Str::random(10) . '@x.test>',
            'thread_key'              => 'thread-abc',
            'from_identifier'         => 'johan@hfcoastal.co.za',
            'participant_identifiers' => ['can.assurance@gmail.com'],
            'occurred_at'             => now()->subHours(2),
            'captured_at'             => now()->subHours(2),
            'subject'                 => 'Re: Your enquiry about 12 Bairn Street',
            'body_text'               => 'Thanks for reaching out, here are the details.',
            'body_preview'            => 'Thanks for reaching out, here are the details.',
            'raw_path'                => 'communications/x/email/' . Str::random(8),
            'content_hash'            => hash('sha256', Str::random()),
            'has_attachments'         => false,
            'source_ref'              => 'mailbox:1',
        ]);

        CommunicationLink::create([
            'agency_id'        => $this->agencyId,
            'communication_id' => $comm->id,
            'linkable_type'    => Contact::class,
            'linkable_id'      => $contact->id,
            'link_method'      => CommunicationLink::METHOD_DETERMINISTIC,
            'confidence'       => 100,
            'confirmed_at'     => now(),
        ]);

        return $contact;
    }

    public function test_permitted_user_sees_the_linked_communication(): void
    {
        $owner = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin',
        ]);
        $contact = $this->contactWithComm();

        $resp = $this->actingAs($owner)->get(route('corex.contacts.show', $contact));

        $resp->assertOk();
        $this->assertTrue((bool) $resp->viewData('canViewComms'));
        $this->assertSame(1, $resp->viewData('contactComms')->count());
        $resp->assertSee('Communication Archive', false);
        $resp->assertSee('Re: Your enquiry about 12 Bairn Street', false);
        $resp->assertSee('Open thread', false);
    }

    public function test_user_without_archive_permission_does_not_get_the_comms(): void
    {
        // Seed the role_permissions table so the gate is ACTIVE (not the
        // empty-table "allow all" fallback), granting the agent an unrelated
        // permission only — never access_communication_archive.
        RolePermission::create(['role' => 'agent', 'permission_key' => 'contacts.view', 'scope' => null]);
        PermissionService::clearCache();

        $agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent',
        ]);
        $this->assertFalse($agent->hasPermission('access_communication_archive'), 'gate is active and agent lacks the perm');

        $contact = $this->contactWithComm();
        $contact->forceFill(['created_by_user_id' => $agent->id])->save();

        $resp = $this->actingAs($agent)->get(route('corex.contacts.show', $contact));

        if ($resp->status() === 200) {
            $this->assertFalse((bool) $resp->viewData('canViewComms'));
            $this->assertSame(0, $resp->viewData('contactComms')->count());
            $resp->assertDontSee('id="tab-communications"', false);
        } else {
            // Route denied / not-visible for this role is an equally valid
            // "no archive comms reach this user" outcome.
            $this->assertContains($resp->status(), [302, 403, 404]);
        }
    }
}
