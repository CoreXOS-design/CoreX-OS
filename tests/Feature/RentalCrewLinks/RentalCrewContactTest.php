<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrewLinks;

use App\Models\RentalCrew;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §14.27.6 item 1 / §14.28 — a crew's email and
 * contact number: create, update, validation (email:rfc max 191; phone
 * ^[0-9+()\- .]{5,30}$), the list's Contact column and its search over
 * name / email / phone, archive and restore.
 */
final class RentalCrewContactTest extends TestCase
{
    use BuildsCrewLinkFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrewLinkWorld('Crew Contact');
    }

    public function test_create_saves_email_and_phone(): void
    {
        $this->actingAs($this->admin)->post(route('corex.rental-crews.store'), [
            'name' => 'Painters', 'email' => 'painters@example.invalid', 'phone' => '+27 82 555 0100',
        ])->assertRedirect();

        $crew = RentalCrew::firstWhere('name', 'Painters');
        $this->assertSame('painters@example.invalid', $crew->email);
        $this->assertSame('+27 82 555 0100', $crew->phone);
    }

    public function test_both_fields_are_optional(): void
    {
        $this->actingAs($this->admin)->post(route('corex.rental-crews.store'), ['name' => 'No Contact Crew'])->assertRedirect();

        $crew = RentalCrew::firstWhere('name', 'No Contact Crew');
        $this->assertNull($crew->email);
        $this->assertNull($crew->phone);
    }

    public function test_update_changes_and_blank_clears_to_null(): void
    {
        $this->actingAs($this->admin)->put(route('corex.rental-crews.update', $this->crew), [
            'name' => 'Team 1', 'email' => 'new@example.invalid', 'phone' => '031-555-0199', 'is_active' => '1',
        ])->assertRedirect();
        $this->assertSame('new@example.invalid', $this->crew->fresh()->email);
        $this->assertSame('031-555-0199', $this->crew->fresh()->phone);

        $this->actingAs($this->admin)->put(route('corex.rental-crews.update', $this->crew), [
            'name' => 'Team 1', 'email' => '', 'phone' => '  ', 'is_active' => '1',
        ])->assertRedirect();
        $this->assertNull($this->crew->fresh()->email);
        $this->assertNull($this->crew->fresh()->phone);
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('corex.rental-crews.store'), ['name' => 'Bad Mail', 'email' => 'not-an-email'])
            ->assertSessionHasErrors('email');
        $this->assertNull(RentalCrew::firstWhere('name', 'Bad Mail'));
    }

    public function test_email_longer_than_191_is_rejected(): void
    {
        $long = str_repeat('a', 185) . '@example.invalid';
        $this->actingAs($this->admin)->post(route('corex.rental-crews.store'), ['name' => 'Long Mail', 'email' => $long])
            ->assertSessionHasErrors('email');
    }

    public function test_invalid_phone_is_rejected(): void
    {
        foreach (['abc', '12', 'call me maybe', str_repeat('1', 31)] as $bad) {
            $this->actingAs($this->admin)->post(route('corex.rental-crews.store'), ['name' => 'Bad Phone', 'phone' => $bad])
                ->assertSessionHasErrors('phone');
        }
        $this->assertNull(RentalCrew::firstWhere('name', 'Bad Phone'));
    }

    public function test_list_shows_contact_and_searches_name_email_and_phone(): void
    {
        RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Electricians', 'email' => 'sparks@example.invalid', 'phone' => '039 999 0000', 'created_by_user_id' => $this->admin->id]);

        $this->actingAs($this->admin)->get(route('corex.rental-crews.index'))
            ->assertOk()->assertSee('team1@example.invalid')->assertSee('082 123 4567')->assertSee('Contact');

        $this->actingAs($this->admin)->get(route('corex.rental-crews.index', ['q' => 'sparks@']))
            ->assertOk()->assertSee('Electricians')->assertDontSee('Team 1');
        $this->actingAs($this->admin)->get(route('corex.rental-crews.index', ['q' => '039 999']))
            ->assertOk()->assertSee('Electricians')->assertDontSee('Team 1');
        $this->actingAs($this->admin)->get(route('corex.rental-crews.index', ['q' => 'Team 1']))
            ->assertOk()->assertSee('Team 1')->assertDontSee('Electricians');
    }

    public function test_archive_and_restore_keep_the_contact_details(): void
    {
        $this->actingAs($this->admin)->delete(route('corex.rental-crews.archive', $this->crew))->assertRedirect();
        $this->assertSoftDeleted($this->crew);

        $this->actingAs($this->admin)->post(route('corex.rental-crews.restore', $this->crew->id))->assertRedirect();
        $restored = $this->crew->fresh();
        $this->assertSame('team1@example.invalid', $restored->email);
        $this->assertSame('082 123 4567', $restored->phone);
    }

    public function test_edit_and_create_forms_carry_the_two_fields(): void
    {
        $this->actingAs($this->admin)->get(route('corex.rental-crews.edit', $this->crew))
            ->assertOk()->assertSee('name="email"', false)->assertSee('name="phone"', false)->assertSee('team1@example.invalid');
        $this->actingAs($this->admin)->get(route('corex.rental-crews.create'))
            ->assertOk()->assertSee('name="email"', false)->assertSee('name="phone"', false);
    }
}
