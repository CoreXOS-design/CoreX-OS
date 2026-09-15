<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-392, 2026-09-13 — Johan was live on QA1, blocked before golf: the
 * PRE-EXISTING `throttle:10,1` on the public document upload/replace/remove
 * routes was keyed per-IP (Laravel's default unauthenticated signature), so
 * a real applicant's own file picker — one POST per file, concurrently,
 * plus a retry — tripped it from a single connection. Conductor's ruling,
 * verbatim: re-key to the application token, raise + agency-configure the
 * limit sized against the worst realistic case, and replace the message
 * with a human one. These three tests are the three proofs the conductor
 * required before sign-off:
 *   A. a realistic full-set burst with retries, at the real default, never trips
 *   B. two different application tokens never share a budget
 *   C. deliberately tripping it surfaces the human message and damages nothing already uploaded
 */
final class RentalApplicationDocumentUploadRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
    }

    private function application(array $attrs = []): RentalApplication
    {
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $agent->id, 'status' => 'sent',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ], $attrs));
    }

    private function upload(RentalApplication $application, string $name): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('rental-applications.public.documents', $application->token), [
            'supporting_files' => [UploadedFile::fake()->create($name, 100, 'application/pdf')],
        ]);
    }

    /**
     * Proof A — the realistic worst case this default was sized against:
     * a full 10-file multi-select (the hard `supporting_files` array cap),
     * followed by a full second attempt because the first "stalled" (the
     * exact shape of what happened to Johan). 20 uploads total, at the real
     * shipped default (60 per 10 minutes) — must never trip.
     */
    public function test_a_realistic_full_document_set_with_a_retry_never_trips_the_default_limit(): void
    {
        $application = $this->application();

        for ($batch = 1; $batch <= 2; $batch++) {
            for ($file = 1; $file <= 10; $file++) {
                $this->upload($application, "document-{$batch}-{$file}.pdf")->assertOk();
            }
        }

        $this->assertSame(20, $application->refresh()->documents()->count(), 'every upload in the realistic burst must have landed');
    }

    /**
     * Proof B — the actual defect. The old throttle keyed on IP, so a
     * shared office/mobile-carrier NAT let one applicant exhaust another's
     * allowance. Proves the new key is the token: application A can be
     * driven to its cap while application B (same test process, i.e. same
     * "IP" as far as any IP-based key would be concerned) is untouched.
     */
    public function test_two_different_application_tokens_never_share_a_rate_limit_budget(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['document_rate_limit_max' => 2, 'document_rate_limit_window_minutes' => 10],
        );
        $applicationA = $this->application();
        $otherContact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Other', 'last_name' => 'Applicant', 'email' => 'other@example.co.za',
        ]);
        $applicationB = $this->application(['contact_id' => $otherContact->id]);

        // Exhaust A's tiny budget completely.
        $this->upload($applicationA, 'a1.pdf')->assertOk();
        $this->upload($applicationA, 'a2.pdf')->assertOk();
        $this->upload($applicationA, 'a3.pdf')->assertStatus(429);

        // B, from the exact same test process (same "IP"), is untouched.
        $this->upload($applicationB, 'b1.pdf')->assertOk();
        $this->upload($applicationB, 'b2.pdf')->assertOk();

        $this->assertSame(2, $applicationA->refresh()->documents()->count());
        $this->assertSame(2, $applicationB->refresh()->documents()->count());
    }

    /**
     * Proof C — deliberately trip it (a throwaway agency setting lowered
     * for this test only, never the shipped default) and prove two things
     * at once: the applicant sees the human message, not "Too many
     * attempts", and every document already uploaded before the trip is
     * completely undisturbed by it.
     */
    public function test_tripping_the_limit_shows_the_human_message_and_loses_nothing_already_uploaded(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['document_rate_limit_max' => 2, 'document_rate_limit_window_minutes' => 10],
        );
        $application = $this->application();

        $this->upload($application, 'payslip.pdf')->assertOk();
        $this->upload($application, 'bank-statement.pdf')->assertOk();
        $this->assertSame(2, $application->refresh()->documents()->count(), 'both uploads before the trip must have landed');

        $tripped = $this->upload($application, 'id-copy.pdf');
        $tripped->assertStatus(429);
        $tripped->assertJson([
            'message' => "You've made a lot of document changes in a short time, so uploads are paused for a moment. Everything you've already uploaded is safe — please wait a minute and try again.",
        ]);
        $this->assertStringNotContainsString('Too many attempts', $tripped->json('message'));

        // Nothing already uploaded is touched by the trip.
        $application->refresh();
        $this->assertSame(2, $application->documents()->count(), 'the rate-limited attempt must not remove or duplicate anything already uploaded');
        $this->assertTrue($application->documents()->where('original_name', 'payslip.pdf')->exists());
        $this->assertTrue($application->documents()->where('original_name', 'bank-statement.pdf')->exists());
    }

    public function test_document_rate_limit_setting_has_a_sensible_default_and_is_agency_configurable(): void
    {
        $this->assertSame(
            RentalApplicationQualifyingSetting::DEFAULT_DOCUMENT_RATE_LIMIT_MAX,
            RentalApplicationQualifyingSetting::documentRateLimitMaxFor($this->agency->id)
        );
        $this->assertSame(
            RentalApplicationQualifyingSetting::DEFAULT_DOCUMENT_RATE_LIMIT_WINDOW_MINUTES,
            RentalApplicationQualifyingSetting::documentRateLimitWindowMinutesFor($this->agency->id)
        );

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['document_rate_limit_max' => 5, 'document_rate_limit_window_minutes' => 15],
        );

        $this->assertSame(5, RentalApplicationQualifyingSetting::documentRateLimitMaxFor($this->agency->id));
        $this->assertSame(15, RentalApplicationQualifyingSetting::documentRateLimitWindowMinutesFor($this->agency->id));
    }
}
