<?php

declare(strict_types=1);

namespace Tests\Feature\Desk;

use App\Events\Desk\DeskBroadcast;
use App\Models\DeskSession;
use App\Models\User;
use App\Services\Desk\DeskSessionService;
use App\Support\Desk\DeskAuditText;
use App\Support\Desk\DeskSupporterLabel;
use Illuminate\Support\Facades\Event;

/**
 * CoreX Desk - the support person is identified properly everywhere the agent (and the audit) sees them:
 *   platform staff  "Johan Reichel — CoreX Support"      agency staff  "Elize Reichel — Home Finders Coastal, Principal"
 * Designation is the user's own record field; the display string is frozen on the session at join time (spec section 4c).
 */
final class DeskSupporterIdentityTest extends DeskTestCase
{
    private DeskSessionService $desk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->desk = app(DeskSessionService::class);
        Event::fake([DeskBroadcast::class]);
    }

    private function named(User $u, string $name, ?string $designation): User
    {
        $u->forceFill(['name' => $name, 'designation' => $designation])->save();

        return $u->fresh();
    }

    public function test_platform_staff_read_corex_plus_their_designation_defaulting_to_support(): void
    {
        $this->assertSame('Johan Reichel — CoreX Support', DeskSupporterLabel::for($this->named($this->owner(), 'Johan Reichel', null)));
        $this->assertSame('Johan Reichel — CoreX Support', DeskSupporterLabel::for($this->named($this->owner(), 'Johan Reichel', '  ')));
        $this->assertSame('Andre Roets — CoreX Director', DeskSupporterLabel::for($this->named($this->owner(), 'Andre Roets', 'Director')), 'the designation comes from the record, never hardcoded');
    }

    public function test_agency_staff_read_their_agencys_display_name_and_designation_with_the_role_as_the_fallback(): void
    {
        $this->agencyA->forceFill(['name' => 'Home Finders Coastal'])->save();
        $elize = $this->named($this->agentIn($this->agencyA, 'admin'), 'Elize Reichel', 'Principal');
        $this->assertSame('Elize Reichel — Home Finders Coastal, Principal', DeskSupporterLabel::for($elize));

        $noTitle = $this->named($this->agentIn($this->agencyA, 'admin'), 'Pieter Botha', null);
        $label = DeskSupporterLabel::for($noTitle);
        $this->assertStringStartsWith('Pieter Botha — Home Finders Coastal, ', $label);
        $this->assertSame('Pieter Botha — Home Finders Coastal, Administrator', $label, 'no designation: the role\'s own label');

        // another agency reads its own name - nothing is HFC's by assumption
        $this->agencyB->forceFill(['name' => 'Cape Coast Rentals'])->save();
        $this->assertSame('Thandi Nkosi — Cape Coast Rentals, Rental Manager', DeskSupporterLabel::for($this->named($this->agentIn($this->agencyB, 'admin'), 'Thandi Nkosi', 'Rental Manager')));
    }

    public function test_the_display_is_frozen_on_the_session_at_join_time_and_shown_to_the_agent_everywhere(): void
    {
        $agent = $this->agentIn($this->agencyA);
        $support = $this->named($this->owner(), 'Johan Reichel', null);
        [$s, $code] = $this->desk->start($agent);

        $s = $this->desk->join($support, $code);

        $this->assertSame('Johan Reichel — CoreX Support', $s->support_display);
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.joined' && $e->payload['support_name'] === 'Johan Reichel — CoreX Support');
        $this->assertSame('Johan Reichel — CoreX Support', $s->toClientArray()['support_name'], 'the red bar, the consent prompt and the page-change restore all read this');
        $this->assertSame('Johan Reichel — CoreX Support', $s->events()->where('type', 'joined')->first()->payload['support_name']);
        $this->actingAs($agent)->getJson('/api/v1/desk/sessions/current')->assertJsonPath('session.support_name', 'Johan Reichel — CoreX Support');

        // the consent prompt and the in-control wording carry the same identification
        $this->desk->requestControl($s, $support, 'main');
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.control_requested' && $e->payload['support_name'] === 'Johan Reichel — CoreX Support');

        // a later change of title does not rewrite what the audit says happened
        $support->forceFill(['designation' => 'Director'])->save();
        $this->assertSame('Johan Reichel — CoreX Support', $s->fresh()->support_display);
        $this->assertSame('Johan Reichel — CoreX Support', $s->fresh()->toClientArray()['support_name']);
        $this->assertSame('Johan Reichel — CoreX Director', DeskSupporterLabel::for($support->fresh()), 'but a NEW session would read the new title');
    }

    public function test_a_second_support_person_has_their_own_frozen_label_and_the_audit_names_each_correctly(): void
    {
        $this->agencyA->forceFill(['name' => 'Home Finders Coastal'])->save();
        $agent = $this->agentIn($this->agencyA);
        $first = $this->named($this->owner(), 'Johan Reichel', null);
        \App\Models\RolePermission::firstOrCreate(['role' => 'admin', 'permission_key' => 'desk.control', 'agency_id' => $this->agencyA->id], ['scope' => 'all']);
        \App\Services\PermissionService::clearCache();
        $second = $this->named($this->adminWithSupport($this->agencyA), 'Elize Reichel', 'Principal');
        [$s, $code] = $this->desk->start($agent);
        $this->desk->join($first, $code);

        $this->actingAs($second)->postJson("/corex/desk/sessions/{$s->id}/attach")->assertOk();
        $this->desk->requestControl($s->fresh(), $first, 'main');
        $this->desk->respondControl($s->fresh(), $agent, true, $this->rid($s->fresh()));
        $this->desk->endControlBySupport($s->fresh(), $first);
        $this->desk->requestControl($s->fresh(), $second, 'main');
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.control_requested' && $e->payload['support_name'] === 'Elize Reichel — Home Finders Coastal, Principal');

        $s = $s->fresh()->load('events');
        $byFirst = $s->events->where('type', 'control_requested')->first();
        $bySecond = $s->events->where('type', 'control_requested')->last();
        $this->assertSame('Johan Reichel — CoreX Support', DeskAuditText::actor($byFirst, $s));
        $this->assertSame('Elize Reichel — Home Finders Coastal, Principal', DeskAuditText::actor($bySecond, $s));
        $this->assertSame($agent->name, DeskAuditText::actor($s->events->where('type', 'control_granted')->first(), $s), 'the agent is just named');
    }

    public function test_the_audit_pages_show_the_stored_identification(): void
    {
        $this->agencyA->forceFill(['name' => 'Home Finders Coastal'])->save();
        $agent = $this->agentIn($this->agencyA);
        $support = $this->named($this->owner(), 'Johan Reichel', null);
        $adminA = $this->agentIn($this->agencyA, 'admin');
        [$s, $code] = $this->desk->start($agent);
        $this->desk->join($support, $code);
        $support->forceFill(['designation' => 'Director'])->save();

        $list = $this->actingAs($adminA)->get('/corex/desk-log')->assertOk();
        $list->assertSee('Johan Reichel — CoreX Support');
        $list->assertDontSee('CoreX Director');
        $show = $this->actingAs($adminA)->get('/corex/desk-log/' . $s->id)->assertOk();
        $show->assertSee('Johan Reichel — CoreX Support');
        $show->assertDontSee('CoreX Director');
    }

    public function test_the_console_shows_who_you_are_and_every_agent_side_surface_uses_the_label(): void
    {
        $support = $this->named($this->owner(), 'Johan Reichel', null);
        $html = $this->actingAs($support)->get('/corex/desk')->assertOk()->getContent();
        $this->assertStringContainsString('id="cdc-support"', $html, 'the console session bar names the supporter');
        $this->assertStringContainsString('Johan Reichel \\u2014 CoreX Support', $html, 'the console knows its own label (JSON-escaped dash)');

        $js = fn ($f) => (string) file_get_contents(resource_path('js/desk/' . $f));
        $this->assertStringContainsString('who: con.me', $js('console.js'), 'the pointer carries who is pointing');
        $this->assertStringContainsString("'Support').slice(0, 90)", $js('agent.js'), 'the CoreX page labels the pointer with it');
        $this->assertStringContainsString('st.session.support_name', $js('agent.js'), 'and the highlight popover');
        $ext = (string) file_get_contents(public_path('chrome-extension/portal-capture/desk-content.js'));
        $this->assertStringContainsString("S.support || 'Support'", $ext);
        $this->assertStringContainsString("S.support || 'CoreX support'", $ext);
        $this->assertStringContainsString('who: m.data.who', (string) file_get_contents(public_path('chrome-extension/portal-capture/desk-ext.js')));
    }

    public function test_the_designation_is_a_field_on_the_users_record_not_a_constant(): void
    {
        $this->assertContains('designation', (new User())->getFillable(), 'users.designation is the user\'s own, editable field');
        $edit = (string) file_get_contents(resource_path('views/admin/users/create-edit.blade.php'));
        $this->assertStringContainsString('name="designation"', $edit, 'edited on the user record (Admin > Users)');
        $code = preg_replace(['/\/\*.*?\*\//s', '/\/\/[^\n]*/'], '', (string) file_get_contents(app_path('Support/Desk/DeskSupporterLabel.php')));
        $this->assertDoesNotMatchRegularExpression('/[\'"](Principal|Director|Support staff)[\'"]/', $code, 'no designation is hardcoded');
        $this->assertStringNotContainsString('Home Finders', $code, 'nothing is HFC\'s by assumption');
        $this->assertStringContainsString("'Support'", $code, 'the one default: platform staff with no designation read Support');
    }
}
