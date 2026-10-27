<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Mail\Compliance\RmcpBoardApprovalMail;
use App\Mail\OtpMail;
use App\Models\Compliance\AgencyBoardMember;
use App\Models\Compliance\RmcpBoardAuditLog;
use App\Models\Compliance\RmcpBoardLetter;
use App\Models\Compliance\RmcpBoardSignature;
use App\Models\Compliance\RmcpSection;
use App\Models\Compliance\RmcpVersion;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Compliance\CompliancePdf;
use App\Services\Compliance\RmcpBoardApprovalService;
use App\Services\Compliance\RmcpVariableResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Compliance\Policy\PolicyTestHelpers;
use Tests\TestCase;

/**
 * RMCP board approval letter: the board list, the letter sent for signature (secure link + emailed code), the signature
 * pad step, the signed PDF, and the Approve gate that reads it.
 *
 * Mail is faked and never leaves the process; every address is the can.assurance mailbox and the sender is johan@hfcoastal.co.za.
 * The PDF printer (Node + Chromium) is swapped for a stand-in in all tests but one, which skips without Node.
 */
final class RmcpBoardApprovalTest extends TestCase
{
    use RefreshDatabase, PolicyTestHelpers;

    private const FROM = 'johan@hfcoastal.co.za';
    private const A = 'can.assurance+boarda@gmail.com';
    private const B = 'can.assurance+boardb@gmail.com';

    private int $agencyId;
    private User $admin;
    private RmcpVersion $draft;

    protected function setUp(): void
    {
        parent::setUp();
        Auth::logout();
        Storage::fake('local');
        Mail::fake();
        RateLimiter::clear('x');
        config(['mail.non_production_redirect' => 'can.assurance@gmail.com']);

        // The printer is infrastructure: a stand-in returns PDF-looking bytes so the flow is tested without Chromium.
        $this->app->bind(CompliancePdf::class, fn () => new class extends CompliancePdf {
            public function render(string $html, string $footerLabel, string $tag, ?string $pageWord = null, ?string $footerSuffix = null): ?string
            {
                return "%PDF-1.4\n% stand-in for " . $tag . "\n" . strlen($html);
            }
        });

        $this->agencyId = $this->seedAgency();
        DB::table('agencies')->where('id', $this->agencyId)->update(['name' => 'Ramsgate Realty', 'reg_no' => '2001/123456/07']);
        $this->admin = $this->makeUser($this->agencyId);
        $this->admin->forceFill(['name' => 'Johan Reichel', 'email' => self::FROM, 'designation' => 'CEO'])->save();

        $this->draft = $this->makeDraft($this->agencyId);
    }

    private function makeDraft(int $agencyId, int $number = 2): RmcpVersion
    {
        $v = RmcpVersion::withoutGlobalScopes()->create(['agency_id' => $agencyId, 'version_number' => $number, 'title' => 'RMCP', 'status' => 'draft']);
        RmcpSection::withoutGlobalScopes()->create(['agency_id' => $agencyId, 'rmcp_version_id' => $v->id, 'section_type' => 'section', 'display_order' => 1,
            'section_number' => '1', 'title' => 'Purpose', 'body_html' => '<p>Why we have this.</p>']);
        RmcpSection::withoutGlobalScopes()->create(['agency_id' => $agencyId, 'rmcp_version_id' => $v->id, 'section_type' => 'section', 'display_order' => 2,
            'section_number' => 'Approval', 'title' => 'Approval',
            'body_html' => '<table><tbody><tr><th>Approved by</th><td>{{rmcp.approved_by_name}}</td></tr><tr><th>Title</th><td>{{rmcp.approved_by_title}}</td></tr><tr><th>Approved on</th><td>{{rmcp.approved_on}}</td></tr></tbody></table>']);

        return $v;
    }

    private function board(?int $agencyId = null): array
    {
        $agencyId ??= $this->agencyId;
        $a = AgencyBoardMember::withoutGlobalScopes()->create(['agency_id' => $agencyId, 'name' => 'Elize Reichel', 'title' => 'Principal Practitioner', 'email' => self::A, 'sort_order' => 1]);
        $b = AgencyBoardMember::withoutGlobalScopes()->create(['agency_id' => $agencyId, 'name' => 'Johan Reichel', 'title' => 'CEO', 'email' => self::B, 'user_id' => $this->admin->id, 'sort_order' => 2]);

        return [$a, $b];
    }

    /** The list is started from the agency's admins the first time it is looked at; archiving every row leaves it empty for good. */
    private function emptyTheBoard(): void
    {
        app(RmcpBoardApprovalService::class)->seedDefaultsIfNeverConfigured($this->agencyId);
        AgencyBoardMember::withoutGlobalScopes()->where('agency_id', $this->agencyId)->get()->each->delete();
    }

    private function send(?RmcpVersion $v = null): RmcpBoardLetter
    {
        $v ??= $this->draft;
        $this->actingAs($this->admin)->post(route('compliance.rmcp.board.send', $v), [
            'effective_from' => now()->addDays(3)->toDateString(), 'next_review_due' => now()->addYear()->toDateString(),
        ])->assertRedirect();

        return RmcpBoardLetter::withoutGlobalScopes()->where('rmcp_version_id', $v->id)->orderByDesc('round')->firstOrFail();
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(240, 80);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imageline($im, 10, 60, 120, 20, imagecolorallocate($im, 10, 20, 90));
        imageline($im, 120, 20, 230, 60, imagecolorallocate($im, 10, 20, 90));
        ob_start();
        imagepng($im);

        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }

    /** Open the emailed link as the member: ask for the code, read it from the (faked) mail, confirm it. */
    private function verifyLink(RmcpBoardSignature $sig): void
    {
        $this->post(route('rmcp-board.code', $sig->token))->assertRedirect();
        $code = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });
        $this->post(route('rmcp-board.verify', $sig->token), ['code' => $code])->assertRedirect(route('rmcp-board.show', $sig->token));
    }

    private function signViaLink(RmcpBoardSignature $sig, string $name = 'Signed Name'): void
    {
        $this->verifyLink($sig);
        $this->post(route('rmcp-board.sign', $sig->token), [
            'signature_type' => 'drawn', 'signature_data' => $this->png(), 'declaration_acknowledged' => '1',
        ])->assertRedirect(route('rmcp-board.show', $sig->token));
    }

    // ── The board list ───────────────────────────────────────────────────

    public function test_board_list_starts_from_top_role_users_once_and_an_archived_member_does_not_come_back(): void
    {
        $second = $this->makeUser($this->agencyId);
        $second->forceFill(['name' => 'Elize Reichel', 'designation' => null])->save();
        $this->makeUser($this->agencyId, 'agent');
        $service = app(RmcpBoardApprovalService::class);

        $service->seedDefaultsIfNeverConfigured($this->agencyId);
        $members = $service->members($this->agencyId);
        self::assertCount(2, $members, 'the two admins, not the agent');
        self::assertSame('CEO', $members->firstWhere('user_id', $this->admin->id)->title);
        self::assertSame('Director', $members->firstWhere('user_id', $second->id)->title, 'no designation -> a plain starting title');

        $members->first()->delete();
        $service->seedDefaultsIfNeverConfigured($this->agencyId);
        self::assertCount(1, $service->members($this->agencyId), 'archived stays archived; nothing is re-added');
        self::assertSame(2, AgencyBoardMember::withoutGlobalScopes()->withTrashed()->where('agency_id', $this->agencyId)->count());
    }

    public function test_board_list_crud_archives_instead_of_deleting_and_restores(): void
    {
        $agency = \App\Models\Agency::findOrFail($this->agencyId);
        $stranger = $this->makeUser($this->seedAgency(), 'agent');

        $this->actingAs($this->admin)->post(route('admin.company-settings.board-members.store', $agency), [
            'name' => 'Pat Director', 'title' => 'Director', 'email' => self::A, 'user_id' => $stranger->id,
        ])->assertRedirect();
        $m = AgencyBoardMember::withoutGlobalScopes()->where('agency_id', $this->agencyId)->where('name', 'Pat Director')->firstOrFail();
        self::assertNull($m->user_id, "a user from another agency is never linked");

        $this->actingAs($this->admin)->put(route('admin.company-settings.board-members.update', [$agency, $m->id]), [
            'name' => 'Pat Director', 'title' => 'Chair', 'email' => self::B, 'user_id' => $this->admin->id,
        ])->assertRedirect();
        self::assertSame('Chair', $m->fresh()->title);
        self::assertSame($this->admin->id, $m->fresh()->user_id);

        $this->actingAs($this->admin)->delete(route('admin.company-settings.board-members.destroy', [$agency, $m->id]))->assertRedirect();
        self::assertNotNull(AgencyBoardMember::withoutGlobalScopes()->withTrashed()->find($m->id)->deleted_at, 'soft delete only');

        $this->actingAs($this->admin)->post(route('admin.company-settings.board-members.restore', [$agency, $m->id]))->assertRedirect();
        self::assertNull(AgencyBoardMember::withoutGlobalScopes()->find($m->id)->deleted_at);
    }

    public function test_board_list_is_scoped_to_the_agency_and_needs_the_permission(): void
    {
        $other = $this->seedAgency();
        $otherAdmin = $this->makeUser($other);
        $agency = \App\Models\Agency::findOrFail($this->agencyId);

        // Another agency's admin cannot touch this agency's list, by agency or by member id.
        $this->actingAs($otherAdmin)->post(route('admin.company-settings.board-members.store', $agency), ['name' => 'X', 'title' => 'Y', 'email' => self::A])->assertForbidden();
        Auth::logout();
        [$a] = $this->board();
        $this->actingAs($otherAdmin)->delete(route('admin.company-settings.board-members.destroy', [\App\Models\Agency::findOrFail($other), $a->id]))->assertNotFound();
        self::assertNull($a->fresh()->deleted_at);

        // Once grants exist, an agent without the key is refused.
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_rmcp', 'scope' => 'all', 'agency_id' => $this->agencyId]);
        $agent = $this->makeUser($this->agencyId, 'agent');
        \App\Services\PermissionService::clearCache();
        $this->actingAs($agent)->post(route('admin.company-settings.board-members.store', $agency), ['name' => 'Refused Person', 'title' => 'Y', 'email' => self::A])->assertForbidden();
        self::assertFalse(AgencyBoardMember::withoutGlobalScopes()->withTrashed()->where('name', 'Refused Person')->exists(), 'the permission middleware stops it before anything is saved');
        self::assertContains('manage_rmcp_board_approval', array_column(config('corex-permissions.permissions'), 'key'), 'the key is in the Role Manager catalogue');
    }

    // ── Sending ──────────────────────────────────────────────────────────

    public function test_send_creates_a_numbered_round_with_a_frozen_letter_and_emails_each_member_only_their_own_link(): void
    {
        $this->board();
        $letter = $this->send();

        self::assertSame(1, $letter->round);
        self::assertSame('sent', $letter->status);
        self::assertSame(2, $letter->signatures()->count());
        self::assertStringContainsString('section 42(2A)', $letter->letter_html);
        self::assertStringContainsString('Ramsgate Realty', $letter->letter_html);
        self::assertStringContainsString('2001/123456/07', $letter->letter_html);
        self::assertStringContainsString('version 2', $letter->letter_html);
        self::assertStringContainsString(now()->addDays(3)->format('d F Y'), $letter->letter_html);

        Mail::assertSent(RmcpBoardApprovalMail::class, 2);
        Mail::assertSent(RmcpBoardApprovalMail::class, fn ($m) => $m->hasTo(self::A) && ! $m->hasTo(self::B)
            && str_contains($m->url, RmcpBoardSignature::withoutGlobalScopes()->where('member_email', self::A)->value('token')));
        Mail::assertSent(RmcpBoardApprovalMail::class, fn ($m) => $m->hasTo(self::B) && ! $m->hasTo(self::A));

        self::assertSame(['sent'], RmcpBoardAuditLog::withoutGlobalScopes()->where('letter_id', $letter->id)->pluck('event_type')->all());
        self::assertSame('sent', RmcpBoardSignature::withoutGlobalScopes()->where('member_email', self::A)->value('status'));
    }

    public function test_send_is_refused_for_no_board_a_bad_email_a_past_date_and_an_approved_version(): void
    {
        $this->emptyTheBoard();
        $this->actingAs($this->admin)->post(route('compliance.rmcp.board.send', $this->draft), ['effective_from' => now()->addDay()->toDateString()])
            ->assertRedirect()->assertSessionHas('error');
        self::assertSame(0, RmcpBoardLetter::withoutGlobalScopes()->count());

        [$a] = $this->board();
        $this->actingAs($this->admin)->post(route('compliance.rmcp.board.send', $this->draft), ['effective_from' => now()->subDay()->toDateString()])
            ->assertSessionHasErrors('effective_from');

        $a->forceFill(['email' => 'not-an-email'])->save();
        $this->actingAs($this->admin)->post(route('compliance.rmcp.board.send', $this->draft), ['effective_from' => now()->addDay()->toDateString()])
            ->assertSessionHas('error');

        $a->forceFill(['email' => self::A])->save();
        $this->draft->update(['status' => 'active']);
        $this->actingAs($this->admin)->post(route('compliance.rmcp.board.send', $this->draft), ['effective_from' => now()->addDay()->toDateString()])
            ->assertSessionHas('error');
        self::assertSame(0, RmcpBoardLetter::withoutGlobalScopes()->count());
        Mail::assertNothingSent();
    }

    public function test_sending_again_supersedes_the_open_round_and_its_old_link_stops_working(): void
    {
        $this->board();
        $first = $this->send();
        $oldToken = RmcpBoardSignature::withoutGlobalScopes()->where('letter_id', $first->id)->value('token');

        $second = $this->send();
        self::assertSame(2, $second->round);
        self::assertSame('superseded', $first->fresh()->status, 'kept, never deleted');
        $this->get(route('rmcp-board.show', $oldToken))->assertStatus(410);
        self::assertTrue(RmcpBoardAuditLog::withoutGlobalScopes()->where('letter_id', $first->id)->where('event_type', 'superseded')->exists());
    }

    public function test_resend_goes_to_the_same_person_and_a_signed_line_cannot_be_resent(): void
    {
        $this->board();
        $letter = $this->send();
        $sig = RmcpBoardSignature::withoutGlobalScopes()->where('member_email', self::A)->firstOrFail();

        $this->actingAs($this->admin)->post(route('compliance.rmcp.board.resend', [$this->draft, $sig]))->assertRedirect()->assertSessionHas('success');
        self::assertSame(2, $sig->fresh()->send_count);
        Mail::assertSent(RmcpBoardApprovalMail::class, fn ($m) => $m->hasTo(self::A) && $m->isReminder);

        $sig->forceFill(['status' => 'signed', 'signed_at' => now()])->save();
        $this->actingAs($this->admin)->post(route('compliance.rmcp.board.resend', [$this->draft, $sig]))->assertSessionHas('error');
        self::assertSame(2, $sig->fresh()->send_count);
    }

    public function test_cancel_keeps_the_letter_closes_the_link_and_allows_a_new_round(): void
    {
        $this->board();
        $letter = $this->send();
        $token = RmcpBoardSignature::withoutGlobalScopes()->where('letter_id', $letter->id)->value('token');

        $this->actingAs($this->admin)->post(route('compliance.rmcp.board.cancel', [$this->draft, $letter]))->assertRedirect()->assertSessionHas('success');
        self::assertSame('cancelled', $letter->fresh()->status);
        self::assertSame(2, RmcpBoardSignature::withoutGlobalScopes()->where('letter_id', $letter->id)->count(), 'nothing deleted');
        $this->get(route('rmcp-board.show', $token))->assertStatus(410);

        self::assertSame(2, $this->send()->round);
    }

    // ── The emailed link: code gate, then the signature ──────────────────

    public function test_the_letter_is_not_shown_until_the_emailed_code_is_entered_and_a_wrong_code_does_not_open_it(): void
    {
        $this->board();
        $letter = $this->send();
        $sig = RmcpBoardSignature::withoutGlobalScopes()->where('member_email', self::A)->firstOrFail();

        $this->get(route('rmcp-board.show', $sig->token))->assertOk()->assertDontSee('section 42(2A)')->assertSee('Email me a code');
        $this->post(route('rmcp-board.sign', $sig->token), ['signature_type' => 'typed', 'typed_name' => 'Elize', 'declaration_acknowledged' => '1'])
            ->assertRedirect(route('rmcp-board.show', $sig->token));
        self::assertFalse($sig->fresh()->isSigned(), 'signing is refused before the code');

        $this->post(route('rmcp-board.code', $sig->token))->assertRedirect();
        Mail::assertSent(OtpMail::class, fn (OtpMail $m) => $m->hasTo(self::A));
        $this->post(route('rmcp-board.verify', $sig->token), ['code' => '000000'])->assertSessionHas('code_error');
        $this->get(route('rmcp-board.show', $sig->token))->assertDontSee('section 42(2A)');
        self::assertNull($sig->fresh()->viewed_at);

        $this->verifyLink($sig);
        $this->get(route('rmcp-board.show', $sig->token))->assertOk()->assertSee('section 42(2A)')->assertSee('Sign the letter');
        self::assertSame('viewed', $sig->fresh()->status);
        self::assertNotNull($sig->fresh()->viewed_at);
        $events = RmcpBoardAuditLog::withoutGlobalScopes()->where('letter_id', $letter->id)->pluck('event_type')->all();
        self::assertContains('code_sent', $events);
        self::assertContains('code_failed', $events);
        self::assertContains('code_verified', $events);
        self::assertContains('viewed', $events);
    }

    public function test_an_unknown_token_gives_the_standard_gone_page(): void
    {
        $this->get(route('rmcp-board.show', str_repeat('x', 40)))->assertStatus(410);
        $this->post(route('rmcp-board.code', str_repeat('x', 40)))->assertStatus(410);
    }

    public function test_signing_stores_the_image_privately_and_a_second_attempt_never_overwrites_it(): void
    {
        $this->board();
        $this->send();
        $sig = RmcpBoardSignature::withoutGlobalScopes()->where('member_email', self::A)->firstOrFail();

        $this->signViaLink($sig);
        $signed = $sig->fresh();
        self::assertSame('signed', $signed->status);
        self::assertSame('drawn', $signed->signature_type);
        self::assertSame('link', $signed->signed_via);
        Storage::disk('local')->assertExists($signed->signature_path);
        self::assertStringStartsWith("rmcp/{$this->agencyId}/board-approval/", $signed->signature_path);
        $path = $signed->signature_path;
        $at = $signed->signed_at;

        // Same link, same session, second try with a different signature: refused, nothing changes.
        $this->post(route('rmcp-board.sign', $sig->token), ['signature_type' => 'typed', 'typed_name' => 'Someone Else', 'declaration_acknowledged' => '1'])
            ->assertSessionHas('sign_error');
        self::assertSame($path, $sig->fresh()->signature_path);
        self::assertEquals($at, $sig->fresh()->signed_at);
        self::assertNull($sig->fresh()->typed_name);
        self::assertSame(1, collect(Storage::disk('local')->allFiles("rmcp/{$this->agencyId}/board-approval"))->filter(fn ($f) => str_ends_with($f, '.png'))->count());
        self::assertSame(1, RmcpBoardAuditLog::withoutGlobalScopes()->where('signature_id', $sig->id)->where('event_type', 'signed')->count());
    }

    public function test_a_typed_signature_needs_a_name_and_a_junk_drawing_is_refused(): void
    {
        $this->board();
        $this->send();
        $sig = RmcpBoardSignature::withoutGlobalScopes()->where('member_email', self::A)->firstOrFail();
        $this->verifyLink($sig);

        $this->post(route('rmcp-board.sign', $sig->token), ['signature_type' => 'drawn', 'signature_data' => 'data:image/png;base64,' . base64_encode('not a picture at all, just text padding padding padding padding padding padding padding padding'), 'declaration_acknowledged' => '1'])
            ->assertSessionHas('sign_error');
        $this->post(route('rmcp-board.sign', $sig->token), ['signature_type' => 'typed', 'typed_name' => '', 'declaration_acknowledged' => '1'])
            ->assertSessionHasErrors('typed_name');
        $this->post(route('rmcp-board.sign', $sig->token), ['signature_type' => 'typed', 'typed_name' => 'Elize Reichel'])
            ->assertSessionHasErrors('declaration_acknowledged');
        self::assertFalse($sig->fresh()->isSigned());

        $this->post(route('rmcp-board.sign', $sig->token), ['signature_type' => 'typed', 'typed_name' => 'Elize Reichel', 'declaration_acknowledged' => '1'])->assertRedirect();
        self::assertSame('typed', $sig->fresh()->signature_type);
        self::assertSame('Elize Reichel', $sig->fresh()->typed_name);
        self::assertNull($sig->fresh()->signature_path);
    }

    // ── In-app signing for a board member who is a user ──────────────────

    public function test_a_board_member_who_is_a_user_signs_in_app_without_a_code_and_nobody_else_can(): void
    {
        $this->board();
        $this->send();
        $stranger = $this->makeUser($this->agencyId, 'agent');

        $this->actingAs($stranger)->get(route('compliance.rmcp.board.sign', $this->draft))->assertRedirect()->assertSessionHas('error');
        $this->actingAs($stranger)->post(route('compliance.rmcp.board.sign.submit', $this->draft), ['signature_type' => 'typed', 'typed_name' => 'Nope', 'declaration_acknowledged' => '1'])
            ->assertSessionHas('error');

        $this->actingAs($this->admin)->get(route('compliance.rmcp.show', $this->draft))->assertOk()->assertSee('Sign the letter');
        $this->actingAs($this->admin)->get(route('compliance.rmcp.board.sign', $this->draft))->assertOk()->assertSee('section 42(2A)');
        $this->actingAs($this->admin)->post(route('compliance.rmcp.board.sign.submit', $this->draft), [
            'signature_type' => 'drawn', 'signature_data' => $this->png(), 'declaration_acknowledged' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $mine = RmcpBoardSignature::withoutGlobalScopes()->where('member_email', self::B)->firstOrFail();
        self::assertSame('signed', $mine->status);
        self::assertSame('in_app', $mine->signed_via);
        self::assertSame($this->admin->id, RmcpBoardAuditLog::withoutGlobalScopes()->where('signature_id', $mine->id)->where('event_type', 'signed')->value('actor_user_id'));
        self::assertSame('sent', RmcpBoardSignature::withoutGlobalScopes()->where('member_email', self::A)->value('status'), "the other member's line is untouched");
    }

    // ── Completing, the Approve gate, the Approval block ─────────────────

    private function everyoneSigns(): RmcpBoardLetter
    {
        $this->board();
        $letter = $this->send();
        foreach (RmcpBoardSignature::withoutGlobalScopes()->where('letter_id', $letter->id)->get() as $sig) {
            $this->signViaLink($sig);
            Mail::fake(); // fresh fake so the next member's code is the one read
        }

        return $letter->fresh();
    }

    public function test_when_everyone_has_signed_the_round_is_complete_and_the_signed_pdf_is_filed(): void
    {
        $letter = $this->everyoneSigns();

        self::assertSame('complete', $letter->status);
        self::assertNotNull($letter->completed_at);
        self::assertNotNull($letter->signed_pdf_path);
        Storage::disk('local')->assertExists($letter->signed_pdf_path);
        self::assertSame(hash('sha256', Storage::disk('local')->get($letter->signed_pdf_path)), $letter->signed_pdf_sha256);
        $events = RmcpBoardAuditLog::withoutGlobalScopes()->where('letter_id', $letter->id)->pluck('event_type')->all();
        self::assertContains('letter_completed', $events);
        self::assertContains('pdf_filed', $events);
        self::assertSame($letter->id, app(RmcpBoardApprovalService::class)->usableLetter($this->draft)?->id);
    }

    public function test_approve_with_the_signed_letter_activates_links_the_letter_and_fills_the_approval_block(): void
    {
        $letter = $this->everyoneSigns();

        $this->actingAs($this->admin)->post(route('compliance.rmcp.approve', $this->draft), ['use_board_letter' => '1', 'approval_notes' => 'Board met 3 Nov.'])
            ->assertRedirect(route('compliance.rmcp.show', $this->draft));

        $v = $this->draft->fresh();
        self::assertSame('active', $v->status);
        self::assertSame($letter->id, $v->board_letter_id);
        self::assertSame($letter->signed_pdf_path, $v->board_approval_document_path);
        self::assertSame($letter->effective_from->toDateString(), $v->effective_from->toDateString());
        self::assertSame($letter->next_review_due->toDateString(), $v->next_review_due->toDateString());
        self::assertSame($this->admin->id, $v->approved_by);

        $vars = app(RmcpVariableResolver::class)->resolve(\App\Models\Agency::findOrFail($this->agencyId), $v->load('approver'));
        self::assertSame('Elize Reichel, Johan Reichel', $vars['rmcp.approved_by_name']);
        self::assertSame('Principal Practitioner, CEO', $vars['rmcp.approved_by_title']);
        self::assertSame($letter->completed_at->format('d F Y'), $vars['rmcp.approved_on']);

        $html = app(CompliancePdf::class)->versionHtml('k', null, $v->load('sections', 'approver'), \App\Models\Agency::findOrFail($this->agencyId), $vars, 'RMCP');
        self::assertStringContainsString('Elize Reichel, Johan Reichel', $html);
        self::assertStringContainsString('Principal Practitioner, CEO', $html);

        $approved = RmcpBoardAuditLog::withoutGlobalScopes()->where('rmcp_version_id', $v->id)->where('event_type', 'approved')->first();
        self::assertNotNull($approved);
        self::assertSame('board_letter', $approved->meta['how']);
        self::assertSame($letter->id, $approved->letter_id);
    }

    public function test_approve_without_a_letter_or_an_upload_is_refused_with_a_pointer_to_the_panel(): void
    {
        $this->board();

        $this->actingAs($this->admin)->post(route('compliance.rmcp.approve', $this->draft), [
            'approver_title' => 'Principal', 'effective_from' => now()->toDateString(), 'next_review_due' => now()->addYear()->toDateString(),
        ])->assertSessionHasErrors('board_approval_document');
        self::assertSame('draft', $this->draft->fresh()->status);

        $page = $this->actingAs($this->admin)->get(route('compliance.rmcp.approve.form', $this->draft))->assertOk();
        $page->assertSee('have the board sign the approval letter in CoreX')->assertSee('#board-approval', false);
    }

    public function test_approve_with_a_letter_still_waiting_for_signatures_is_refused_and_names_who(): void
    {
        $this->board();
        $this->send();

        $this->actingAs($this->admin)->post(route('compliance.rmcp.approve', $this->draft), ['use_board_letter' => '1'])
            ->assertRedirect(route('compliance.rmcp.show', $this->draft) . '#board-approval')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Still waiting for Elize Reichel, Johan Reichel'));
        self::assertSame('draft', $this->draft->fresh()->status);
    }

    public function test_a_section_edited_after_the_board_signed_blocks_the_letter_until_a_new_round_is_signed(): void
    {
        $this->everyoneSigns();
        RmcpSection::withoutGlobalScopes()->where('rmcp_version_id', $this->draft->id)->where('section_number', '1')->update(['body_html' => '<p>Changed after signing.</p>']);

        $check = app(RmcpBoardApprovalService::class)->problemFor($this->draft->fresh());
        self::assertNull($check['letter']);
        self::assertStringContainsString('changed after the board signed', $check['problem']);

        $this->actingAs($this->admin)->post(route('compliance.rmcp.approve', $this->draft), ['use_board_letter' => '1'])
            ->assertSessionHas('error');
        self::assertSame('draft', $this->draft->fresh()->status);
        $this->actingAs($this->admin)->get(route('compliance.rmcp.show', $this->draft))->assertOk()->assertSee('The RMCP was changed after this letter was sent')->assertSee('Send for board signature');
    }

    public function test_the_uploaded_signed_pdf_path_still_works_and_is_audited(): void
    {
        $this->actingAs($this->admin)->post(route('compliance.rmcp.approve', $this->draft), [
            'approver_title' => 'Principal', 'board_approval_document' => UploadedFile::fake()->create('board.pdf', 50, 'application/pdf'),
            'effective_from' => now()->toDateString(), 'next_review_due' => now()->addYear()->toDateString(),
        ])->assertRedirect(route('compliance.rmcp.show', $this->draft));

        $v = $this->draft->fresh();
        self::assertSame('active', $v->status);
        self::assertNull($v->board_letter_id);
        self::assertSame('uploaded_document', RmcpBoardAuditLog::withoutGlobalScopes()->where('rmcp_version_id', $v->id)->where('event_type', 'approved')->first()->meta['how']);
        $vars = app(RmcpVariableResolver::class)->resolve(\App\Models\Agency::findOrFail($this->agencyId), $v->load('approver'));
        self::assertSame('Principal', $vars['rmcp.approved_by_title']);
        self::assertSame($this->admin->name, $vars['rmcp.approved_by_name']);
    }

    public function test_a_missing_pdf_leaves_the_round_complete_but_unusable_until_it_is_created_again(): void
    {
        $letter = $this->everyoneSigns();
        Storage::disk('local')->delete($letter->signed_pdf_path);

        self::assertNull(app(RmcpBoardApprovalService::class)->usableLetter($this->draft));
        $this->actingAs($this->admin)->get(route('compliance.rmcp.show', $this->draft))->assertSee('Create the PDF again');
        $this->actingAs($this->admin)->post(route('compliance.rmcp.board.pdf.create', [$this->draft, $letter]))->assertSessionHas('success');
        self::assertNotNull(app(RmcpBoardApprovalService::class)->usableLetter($this->draft->fresh()));
    }

    // ── Scoping, permission, first-time reading ──────────────────────────

    public function test_another_agency_cannot_see_send_or_download_anything_here(): void
    {
        $letter = $this->everyoneSigns();
        $otherId = $this->seedAgency();
        $other = $this->makeUser($otherId);
        $sig = RmcpBoardSignature::withoutGlobalScopes()->where('letter_id', $letter->id)->firstOrFail();

        $this->actingAs($other)->get(route('compliance.rmcp.show', $this->draft))->assertNotFound();
        $this->actingAs($other)->post(route('compliance.rmcp.board.send', $this->draft), ['effective_from' => now()->addDay()->toDateString()])->assertNotFound();
        $this->actingAs($other)->post(route('compliance.rmcp.board.resend', [$this->draft, $sig]))->assertNotFound();
        $this->actingAs($other)->post(route('compliance.rmcp.board.cancel', [$this->draft, $letter]))->assertNotFound();
        $this->actingAs($other)->get(route('compliance.rmcp.board.pdf', [$this->draft, $letter]))->assertNotFound();

        // Mixing ids across the agency's own versions is also refused.
        $sibling = $this->makeDraft($this->agencyId, 3);
        $this->actingAs($this->admin)->get(route('compliance.rmcp.board.pdf', [$sibling, $letter]))->assertNotFound();
        $this->actingAs($this->admin)->post(route('compliance.rmcp.board.resend', [$sibling, $sig]))->assertNotFound();
    }

    public function test_sending_needs_the_board_approval_key_and_the_panel_is_hidden_from_plain_viewers(): void
    {
        $this->board();
        foreach (['access_rmcp', 'approve_rmcp'] as $key) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $key, 'scope' => 'all', 'agency_id' => $this->agencyId]);
        }
        RolePermission::create(['role' => 'branch_manager', 'permission_key' => 'access_rmcp', 'scope' => 'all', 'agency_id' => $this->agencyId]);
        $approver = $this->makeUser($this->agencyId, 'agent');
        $viewer = $this->makeUser($this->agencyId, 'branch_manager');
        \App\Services\PermissionService::clearCache();

        $this->actingAs($approver)->post(route('compliance.rmcp.board.send', $this->draft), ['effective_from' => now()->addDay()->toDateString()])->assertForbidden();
        self::assertSame(0, RmcpBoardLetter::withoutGlobalScopes()->count());

        $this->actingAs($approver)->get(route('compliance.rmcp.show', $this->draft))->assertOk()->assertSee('Board approval')->assertDontSee('Send for board signature');
        $this->actingAs($viewer)->get(route('compliance.rmcp.show', $this->draft))->assertOk()->assertDontSee('Board approval');
    }

    public function test_the_panel_reads_plainly_in_each_state_with_one_next_step(): void
    {
        // No board yet (the agency's own list was emptied on purpose, so nothing is started for it).
        $this->emptyTheBoard();
        $this->actingAs($this->admin)->get(route('compliance.rmcp.show', $this->draft))->assertOk()->assertSee('Add board members');

        // Board set, nothing sent.
        $this->board();
        $this->actingAs($this->admin)->get(route('compliance.rmcp.show', $this->draft))->assertOk()
            ->assertSee('Send for board signature')->assertSee('Elize Reichel')->assertSee('The RMCP takes effect on');

        // Sent: who we are waiting for, and no second send form.
        $letter = $this->send();
        $this->actingAs($this->admin)->get(route('compliance.rmcp.show', $this->draft))->assertOk()
            ->assertSee('waiting for Elize Reichel, Johan Reichel to sign')->assertDontSee('Send for board signature');

        // Signed by all: the next step is Approve.
        foreach (RmcpBoardSignature::withoutGlobalScopes()->where('letter_id', $letter->id)->get() as $sig) {
            $this->signViaLink($sig);
            Mail::fake();
        }
        $this->actingAs($this->admin)->get(route('compliance.rmcp.show', $this->draft))->assertOk()
            ->assertSee('Everyone has signed')->assertSee('Next: Approve RMCP')->assertSee('Download the signed letter');
    }

    public function test_the_signed_letter_is_downloadable_for_managers_and_prints_every_signature_line(): void
    {
        $letter = $this->everyoneSigns();
        $this->actingAs($this->admin)->get(route('compliance.rmcp.board.pdf', [$this->draft, $letter]))->assertOk()->assertHeader('content-type', 'application/pdf');

        $html = app(CompliancePdf::class)->boardLetterHtml($letter->fresh(), \App\Models\Agency::findOrFail($this->agencyId), [1 => 'data:image/png;base64,AAAA']);
        foreach (['Elize Reichel', 'Principal Practitioner', 'Johan Reichel', 'CEO', 'section 42(2A)'] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
        self::assertSame(2, substr_count($html, 'class="sigbox"'));
    }

    public function test_the_approval_block_title_migration_changes_drafts_only(): void
    {
        $active = $this->makeDraft($this->agencyId, 7);
        $active->update(['status' => 'active']);
        $old = '<table><tbody><tr><th>Title</th><td>Principal</td></tr></tbody></table>';
        RmcpSection::withoutGlobalScopes()->whereIn('rmcp_version_id', [$this->draft->id, $active->id])->where('section_number', 'Approval')->update(['body_html' => $old]);

        (require base_path('database/migrations/2026_12_12_100100_rmcp_approval_block_title_follows_signed_letter.php'))->up();

        $body = fn (RmcpVersion $v) => RmcpSection::withoutGlobalScopes()->where('rmcp_version_id', $v->id)->where('section_number', 'Approval')->value('body_html');
        self::assertStringContainsString('{{rmcp.approved_by_title}}', $body($this->draft));
        self::assertSame($old, $body($active), 'an approved version is a record and is left as it was');
    }

    public function test_the_master_text_title_row_follows_the_signed_letter(): void
    {
        self::assertStringContainsString('<th>Title</th><td>{{rmcp.approved_by_title}}</td>', (string) file_get_contents(base_path('database/data/rmcp/v2.md')));
    }

    public function test_the_signed_letter_really_prints_with_the_real_printer(): void
    {
        $node = trim((string) @shell_exec('command -v node'));
        if ($node === '' || ! is_dir(base_path('node_modules/puppeteer')) && ! is_dir(base_path('node_modules/puppeteer-core'))) {
            $this->markTestSkipped('Needs Node and Puppeteer.');
        }
        $this->app->bind(CompliancePdf::class, fn () => new CompliancePdf());

        $letter = $this->everyoneSigns();
        self::assertStringStartsWith('%PDF', Storage::disk('local')->get($letter->fresh()->signed_pdf_path));
    }
}
