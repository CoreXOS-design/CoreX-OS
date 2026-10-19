<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Exceptions\Docuperfect\TemplateGenerationRefused;
use App\Models\Docuperfect\ImportDraft;
use App\Models\Docuperfect\Template;
use App\Models\User;
use App\Services\Docuperfect\DocumentTemplateGenerator;
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

    /** Same rule for generate(): an unexpected failure shows a plain sentence + reference, never the raw text. */
    public function test_a_generate_failure_shows_a_reference_never_the_raw_exception_text(): void
    {
        [$user, $draft] = $this->userWithDraft();
        $secret = 'SQLSTATE[42S22] Unknown column x in /var/www/corex/app/Secret.php';
        $this->bindGeneratorThrowing(new \RuntimeException($secret));

        $res = $this->actingAs($user)->post(route('docuperfect.import.generate'), [
            'template_name' => 'My lease', 'draft_id' => $draft->id,
        ]);

        $res->assertRedirect(route('docuperfect.import.review', ['draft_id' => $draft->id]));
        $flash = (string) session('error');
        $this->assertStringNotContainsString('SQLSTATE', $flash);
        $this->assertStringNotContainsString('/var/www', $flash);
        $this->assertMatchesRegularExpression('/send this reference to support: [A-Z0-9]{8}$/', $flash);
        $this->assertNotNull(ImportDraft::find($draft->id), 'the draft is preserved for a retry');
    }

    /** A failure the author can fix themselves keeps its plain, author-facing sentence. */
    public function test_a_generate_refusal_the_author_can_fix_is_shown_as_is(): void
    {
        [$user, $draft] = $this->userWithDraft();
        $this->bindGeneratorThrowing(new TemplateGenerationRefused('Draft has no tags. Tag fields before generating.'));

        $this->actingAs($user)->post(route('docuperfect.import.generate'), [
            'template_name' => 'My lease', 'draft_id' => $draft->id,
        ])->assertRedirect();

        $this->assertSame('Draft has no tags. Tag fields before generating. Your draft has been preserved.', session('error'));
    }

    /** @return array{0: User, 1: ImportDraft} */
    private function userWithDraft(): array
    {
        $agency = (int) DB::table('agencies')->insertGetId([
            'name' => 'Agency ' . Str::random(6), 'slug' => 'ag-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $user  = User::factory()->create(['agency_id' => $agency, 'role' => 'admin']);
        $draft = ImportDraft::create([
            'user_id' => $user->id, 'filename' => 'lease.docx', 'html' => '<p>x</p>',
            'fields_json' => json_encode(['tags' => [['id' => 1]], 'tagged_html' => '<p>x</p>']),
        ]);

        return [$user, $draft];
    }

    private function bindGeneratorThrowing(\Throwable $e): void
    {
        $this->app->instance(DocumentTemplateGenerator::class, new class($e) extends DocumentTemplateGenerator {
            public function __construct(private \Throwable $e) {}

            public function generate(ImportDraft $draft, string $templateName, int $ownerId, ?int $agencyId = null): Template
            {
                throw $this->e;
            }
        });
    }
}
