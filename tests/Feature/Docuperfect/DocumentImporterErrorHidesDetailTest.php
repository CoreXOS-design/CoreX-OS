<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\User;
use App\Services\Docuperfect\ImporterAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-456 finding — the document importer's parse catch returned the raw exception message and the full
 * stack trace as JSON. The browser now gets a plain sentence and a short reference; detail stays in the log.
 */
final class DocumentImporterErrorHidesDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_parse_failure_returns_a_plain_message_and_reference_never_a_trace(): void
    {
        $agency = (int) DB::table('agencies')->insertGetId([
            'name' => 'Agency ' . Str::random(6), 'slug' => 'ag-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create(['agency_id' => $agency, 'role' => 'admin']);

        $secret = 'SQLSTATE[HY000] secret table /var/www/corex/app/Secret.php';
        $this->app->instance(ImporterAiService::class, new class($secret) extends ImporterAiService {
            public function __construct(private string $secret) {}

            public function detectFromPdf(string $pdfPath, ?string $supplementaryText = null): array
            {
                throw new \RuntimeException($this->secret);
            }
        });

        $logged = [];
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes()->andReturnUsing(function ($message, $context = []) use (&$logged) {
            $logged[] = [$message, $context];
        });

        $res = $this->actingAs($user)->post(route('docuperfect.import.parse'), [
            'document' => UploadedFile::fake()->createWithContent('lease.pdf', '%PDF-1.4 fake'),
        ], ['Accept' => 'application/json']);

        $res->assertStatus(500);
        $body = $res->json();

        $this->assertArrayNotHasKey('trace', $body);
        $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
        $this->assertStringNotContainsString('/var/www', $res->getContent());
        $this->assertStringNotContainsString('#0 ', $res->getContent());
        $this->assertMatchesRegularExpression(
            '/^The document could not be read\. Please try again or send this reference to support: [A-Z0-9]{8}$/',
            (string) $body['error']
        );

        // Full detail went to the server log under the same reference.
        $entry = collect($logged)->first(fn ($e) => str_contains($e[0], 'EXCEPTION in parse'));
        $this->assertNotNull($entry, 'the failure must be logged server-side');
        $this->assertSame($body['reference'], $entry[1]['ref']);
        $this->assertSame($secret, $entry[1]['message']);
        $this->assertArrayHasKey('trace', $entry[1]);
    }
}
