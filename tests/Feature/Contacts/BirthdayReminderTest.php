<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\User;
use App\Services\CommandCenter\Calendar\Sources\PeopleCalendarSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Birthday reminders are opt-in per contact (contacts.birthday_reminder).
 * Default off — no agent gets unsolicited birthday emails/notifications.
 * Turning it on surfaces the birthday on the calendar AND fires an in-app
 * reminder on the day.
 */
final class BirthdayReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_toggle_turns_reminder_on_then_off(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();
        $contact = $this->makeContact($agencyId, $user->id, '1990-06-08');

        $this->assertFalse($contact->birthday_reminder, 'defaults off');

        // ON
        $this->actingAs($user)
            ->from(route('corex.contacts.show', $contact))
            ->post(route('corex.contacts.birthday-reminder.toggle', $contact))
            ->assertRedirect(route('corex.contacts.show', $contact))
            ->assertSessionHas('success');

        $this->assertTrue($contact->fresh()->birthday_reminder);

        // OFF
        $this->actingAs($user)
            ->from(route('corex.contacts.show', $contact))
            ->post(route('corex.contacts.birthday-reminder.toggle', $contact));

        $this->assertFalse($contact->fresh()->birthday_reminder);
    }

    public function test_toggle_blocked_when_no_birthday(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();
        $contact = $this->makeContact($agencyId, $user->id, null);

        $this->actingAs($user)
            ->from(route('corex.contacts.show', $contact))
            ->post(route('corex.contacts.birthday-reminder.toggle', $contact))
            ->assertSessionHas('error');

        $this->assertFalse($contact->fresh()->birthday_reminder);
    }

    public function test_calendar_source_only_includes_opted_in_birthdays(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();
        $optedIn  = $this->makeContact($agencyId, $user->id, '1985-03-15', true);
        $optedOut = $this->makeContact($agencyId, $user->id, '1992-09-20', false);

        $events = (new PeopleCalendarSource())->syncAll()
            ->where('category', 'contact_birthday');

        $contactIds = $events->pluck('contact_id')->all();

        $this->assertContains($optedIn->id, $contactIds, 'opted-in birthday shows on calendar');
        $this->assertNotContains($optedOut->id, $contactIds, 'opted-out birthday hidden');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedAgencyUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);

        return [$agencyId, $user];
    }

    private function makeContact(int $agencyId, int $userId, ?string $birthday, bool $reminder = false): Contact
    {
        $id = (int) DB::table('contacts')->insertGetId([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'created_by_user_id' => $userId,
            'first_name' => 'Birthday',
            'last_name'  => Str::random(5),
            'phone'      => '0821234567',
            'birthday'   => $birthday,
            'birthday_reminder' => $reminder,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Contact::withoutGlobalScopes()->findOrFail($id);
    }
}
