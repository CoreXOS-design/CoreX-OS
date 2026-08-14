<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Mail\Signatures\SigningRequestMail;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-296 — e-sign mail body FOOTER showed the agency admin@ inbox instead of
 * the sending agent's identity.
 *
 * Root cause: BaseSignatureMail::getAgentFooter() fell back website → $agency->email
 * (admin@hfcoastal.co.za) for a website-less agent, and the footer partial rendered
 * that as a "website" link while never rendering the agent's own email. Fix: the
 * website fallback no longer uses the agency email, and the footer now renders the
 * sender's outward_email.
 */
final class MailFooterIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_footer_shows_sender_email_not_agency_admin_inbox(): void
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Home Finders Coastal', 'slug' => 'hfc-' . Str::random(6),
            'email' => 'admin@hfcoastal.co.za',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A website-less agent whose own email differs from the agency admin inbox.
        $agent = new User();
        $agent->name = 'Johan Reichel';
        $agent->email = 'johan@corexos.co.za';
        $agent->agency_id = $agencyId;
        $agent->role = 'agent';
        $agent->password = bcrypt('x');
        $agent->save();

        $html = (new SigningRequestMail(
            signerName: 'Thandeka Zulu',
            documentName: 'Exclusive Mandate',
            signingUrl: 'https://x/y',
            personalMessage: 'Please sign.',
            expiresAt: now()->addDays(14),
        ))->fromAgent($agent)->render();

        $this->assertStringContainsString('johan@corexos.co.za', $html, 'footer must show the sending agent email');
        $this->assertStringNotContainsString('admin@hfcoastal.co.za', $html, 'footer must NOT leak the agency admin inbox');
    }
}
