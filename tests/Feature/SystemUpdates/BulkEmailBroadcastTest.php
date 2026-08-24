<?php

declare(strict_types=1);

namespace Tests\Feature\SystemUpdates;

use App\Mail\BulkAnnouncementMail;
use App\Models\Agency;
use App\Models\BulkEmailBroadcast;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Bulk Email — spec §14, §15.
 */
final class BulkEmailBroadcastTest extends SystemUpdateTestCase
{
    public function test_owner_can_send_to_all_corex_users(): void
    {
        Mail::fake();

        $secondAgency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
        $otherAgencyAgent = User::factory()->create(['agency_id' => $secondAgency->id, 'role' => 'agent', 'is_active' => true]);

        $response = $this->actingAs($this->owner)->post(route('admin.system-updates.bulk-email.send'), [
            'subject'     => 'CoreX maintenance tonight at 22:00',
            'body'        => "CoreX will be briefly unavailable tonight while we roll out an update.\nExpect about 10 minutes of downtime.",
            'target_type' => 'all',
        ]);

        $response->assertRedirect(route('admin.system-updates.bulk-email.create'));

        Mail::assertQueued(BulkAnnouncementMail::class, function ($mail) {
            return $mail->hasTo($this->agent->email);
        });
        Mail::assertQueued(BulkAnnouncementMail::class, function ($mail) use ($otherAgencyAgent) {
            return $mail->hasTo($otherAgencyAgent->email);
        });

        $broadcast = BulkEmailBroadcast::firstOrFail();
        $this->assertSame('all', $broadcast->target_type);
        $this->assertNull($broadcast->target_agency_id);
        $this->assertSame($this->owner->id, $broadcast->sent_by_user_id);
        // owner + admin + agent + otherAgencyAgent, all active with emails.
        $this->assertSame(4, $broadcast->recipient_count);
    }

    public function test_owner_can_send_to_one_specific_agency_only(): void
    {
        Mail::fake();

        $secondAgency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
        $otherAgencyAgent = User::factory()->create(['agency_id' => $secondAgency->id, 'role' => 'agent', 'is_active' => true]);

        $this->actingAs($this->owner)->post(route('admin.system-updates.bulk-email.send'), [
            'subject'          => 'Welcome to CoreX, Home Finders Coastal',
            'body'             => 'A note just for your agency.',
            'target_type'      => 'agency',
            'target_agency_id' => $this->agency->id,
        ])->assertRedirect();

        Mail::assertQueued(BulkAnnouncementMail::class, function ($mail) {
            return $mail->hasTo($this->agent->email);
        });
        Mail::assertNotQueued(BulkAnnouncementMail::class, function ($mail) use ($otherAgencyAgent) {
            return $mail->hasTo($otherAgencyAgent->email);
        });

        $broadcast = BulkEmailBroadcast::firstOrFail();
        $this->assertSame('agency', $broadcast->target_type);
        $this->assertSame($this->agency->id, $broadcast->target_agency_id);
        // admin + agent in this agency.
        $this->assertSame(2, $broadcast->recipient_count);
    }

    public function test_confirmation_is_required_by_ui_but_server_recomputes_count_regardless(): void
    {
        Mail::fake();

        // A tampered/stale client count must never be trusted (spec §9.1) — the
        // route accepts no recipient_count input at all, so there is nothing to tamper.
        $this->actingAs($this->owner)->post(route('admin.system-updates.bulk-email.send'), [
            'subject'     => 'Test',
            'body'        => 'Body',
            'target_type' => 'all',
        ])->assertRedirect();

        $broadcast = BulkEmailBroadcast::firstOrFail();
        $this->assertSame(3, $broadcast->recipient_count); // owner + admin + agent
    }

    public function test_deactivated_users_never_receive_a_bulk_email(): void
    {
        Mail::fake();

        $deactivated = User::factory()->create([
            'agency_id' => $this->agency->id,
            'role'      => 'agent',
            'is_active' => false,
        ]);

        $this->actingAs($this->owner)->post(route('admin.system-updates.bulk-email.send'), [
            'subject'     => 'CoreX maintenance tonight at 22:00',
            'body'        => 'Body',
            'target_type' => 'all',
        ])->assertRedirect();

        Mail::assertNotQueued(BulkAnnouncementMail::class, function ($mail) use ($deactivated) {
            return $mail->hasTo($deactivated->email);
        });

        $broadcast = BulkEmailBroadcast::firstOrFail();
        // owner + admin + agent — the deactivated user is not counted either.
        $this->assertSame(3, $broadcast->recipient_count);
    }

    public function test_soft_deleted_users_never_receive_a_bulk_email(): void
    {
        Mail::fake();

        $deleted = User::factory()->create([
            'agency_id' => $this->agency->id,
            'role'      => 'agent',
            'is_active' => true,
        ]);
        $deletedEmail = $deleted->email;
        $deleted->delete();

        $this->actingAs($this->owner)->post(route('admin.system-updates.bulk-email.send'), [
            'subject'     => 'CoreX maintenance tonight at 22:00',
            'body'        => 'Body',
            'target_type' => 'all',
        ])->assertRedirect();

        Mail::assertNotQueued(BulkAnnouncementMail::class, function ($mail) use ($deletedEmail) {
            return $mail->hasTo($deletedEmail);
        });

        $broadcast = BulkEmailBroadcast::firstOrFail();
        // owner + admin + agent — the soft-deleted user is not counted either.
        $this->assertSame(3, $broadcast->recipient_count);
    }

    public function test_a_plain_agent_is_forbidden(): void
    {
        $this->actingAs($this->agent)->get(route('admin.system-updates.bulk-email.create'))->assertForbidden();
        $this->actingAs($this->agent)->post(route('admin.system-updates.bulk-email.send'), [
            'subject' => 'x', 'body' => 'y', 'target_type' => 'all',
        ])->assertForbidden();
    }

    public function test_an_agency_admin_is_forbidden(): void
    {
        $this->actingAs($this->admin)->get(route('admin.system-updates.bulk-email.create'))->assertForbidden();
    }

    public function test_empty_subject_and_body_are_rejected(): void
    {
        Mail::fake();

        $this->actingAs($this->owner)->post(route('admin.system-updates.bulk-email.send'), [
            'subject'     => '',
            'body'        => '',
            'target_type' => 'all',
        ])->assertSessionHasErrors(['subject', 'body']);

        Mail::assertNothingQueued();
        $this->assertSame(0, BulkEmailBroadcast::count());
    }

    public function test_agency_target_without_an_agency_id_is_rejected(): void
    {
        $this->actingAs($this->owner)->post(route('admin.system-updates.bulk-email.send'), [
            'subject'     => 'x',
            'body'        => 'y',
            'target_type' => 'agency',
        ])->assertSessionHasErrors(['target_agency_id']);
    }

    public function test_tampered_target_type_is_rejected(): void
    {
        $this->actingAs($this->owner)->post(route('admin.system-updates.bulk-email.send'), [
            'subject'     => 'x',
            'body'        => 'y',
            'target_type' => 'everyone-ever',
        ])->assertSessionHasErrors(['target_type']);
    }

    public function test_an_agency_with_zero_active_users_sends_nothing_and_logs_nothing(): void
    {
        Mail::fake();

        $emptyAgency = Agency::create(['name' => 'Sunrise Estates', 'slug' => 'sunrise-' . uniqid()]);

        $this->actingAs($this->owner)->post(route('admin.system-updates.bulk-email.send'), [
            'subject'          => 'x',
            'body'             => 'y',
            'target_type'      => 'agency',
            'target_agency_id' => $emptyAgency->id,
        ])->assertRedirect();

        Mail::assertNothingQueued();
        $this->assertSame(0, BulkEmailBroadcast::count());
    }

    public function test_a_script_tag_in_the_body_renders_as_text_never_executes(): void
    {
        $mail = new BulkAnnouncementMail('Subject', "Hello <script>alert(1)</script> world", 'Jane Agent');
        $html = $mail->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_recent_broadcasts_table_lists_newest_first(): void
    {
        Mail::fake();

        $this->actingAs($this->owner)->post(route('admin.system-updates.bulk-email.send'), [
            'subject' => 'First', 'body' => 'x', 'target_type' => 'all',
        ]);
        $this->actingAs($this->owner)->post(route('admin.system-updates.bulk-email.send'), [
            'subject' => 'Second', 'body' => 'x', 'target_type' => 'all',
        ]);

        $this->actingAs($this->owner)
            ->get(route('admin.system-updates.bulk-email.create'))
            ->assertOk()
            ->assertSeeInOrder(['Second', 'First']);
    }
}
