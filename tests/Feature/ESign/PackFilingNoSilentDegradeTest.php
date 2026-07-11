<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Models\Agency;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * P0-5 — a signed pack must never be silently mis-filed.
 *
 * `filePackDocuments()` used to fall back to `fileSingleDocument()` whenever the
 * HTML split produced a different number of fragments than the pack had
 * templates. That files the WHOLE pack as ONE Document carrying the FIRST
 * template's document_type_id — so a four-document mandate pack filed as a single
 * "Mandate", and the FICA and the Mandatory Disclosure were never filed at all.
 *
 * Nothing failed visibly. The agent saw a completed signing; compliance saw
 * missing documents that had in fact been signed. The only trace was a log
 * warning nobody reads.
 *
 * This has bitten for real: `stampDisclosureDocKeys()` injects an attribute
 * between `<div` and `class=`, which broke the old literal-string split and
 * produced ZERO fragments — silently mis-filing every pack that went through it.
 */
final class PackFilingNoSilentDegradeTest extends TestCase
{
    use RefreshDatabase;

    private SignatureService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc']);
        User::factory()->create(['agency_id' => $agency->id, 'role' => 'super_admin']);

        $this->service = app(SignatureService::class);
    }

    private function splitMergedHtml(string $html, int $expected): array
    {
        $m = new ReflectionMethod(SignatureService::class, 'splitMergedHtml');
        $m->setAccessible(true);

        return $m->invoke($this->service, $html, $expected);
    }

    private function wrapper(string $inner, string $extraAttrs = ''): string
    {
        return "<div{$extraAttrs} class=\"corex-document-wrapper\"><p>{$inner}</p></div>";
    }

    // ── the split itself ────────────────────────────────────────────────────

    public function test_a_clean_pack_splits_into_one_fragment_per_document(): void
    {
        $html = '<style>.x{}</style>'
            . $this->wrapper('Exclusive Authority To Sell')
            . $this->wrapper('FICA Natural Person')
            . $this->wrapper('Seller Mandatory Disclosure');

        $fragments = $this->splitMergedHtml($html, 3);

        $this->assertCount(3, $fragments, 'each document in the pack is its own fragment');
        $this->assertStringContainsString('Exclusive Authority To Sell', $fragments[0]);
        $this->assertStringContainsString('FICA Natural Person', $fragments[1]);
        $this->assertStringContainsString('Seller Mandatory Disclosure', $fragments[2]);
    }

    /**
     * The real-world regression: an attribute injected BEFORE `class=`. The old
     * literal-string match ('<div class="corex-document-wrapper"') found nothing
     * and returned zero fragments — which then silently mis-filed the whole pack.
     */
    public function test_an_attribute_before_the_class_does_not_break_the_split(): void
    {
        $html = $this->wrapper('Mandate')
            . $this->wrapper('Disclosure', ' data-disclosure-doc="seller-1"');

        $fragments = $this->splitMergedHtml($html, 2);

        $this->assertCount(2, $fragments,
            'stampDisclosureDocKeys() injects data-disclosure-doc between <div and class= — '
            . 'the split must be attribute-order independent');
        $this->assertStringContainsString('Disclosure', $fragments[1]);
    }

    public function test_nested_divs_do_not_end_a_document_early(): void
    {
        $html = '<div class="corex-document-wrapper"><div><div>deep</div></div><p>Mandate</p></div>'
            . $this->wrapper('FICA');

        $fragments = $this->splitMergedHtml($html, 2);

        $this->assertCount(2, $fragments);
        $this->assertStringContainsString('Mandate', $fragments[0]);
        $this->assertStringContainsString('deep', $fragments[0], 'nested content stays with its document');
        $this->assertStringContainsString('FICA', $fragments[1]);
    }

    // ── the guard: a mismatch must be VISIBLE, not absorbed ─────────────────

    public function test_a_mismatch_is_detectable_rather_than_silently_absorbed(): void
    {
        // Three documents expected, but the merged HTML only carries two —
        // exactly the state that used to trigger the silent merged-PDF fallback.
        $html = $this->wrapper('Mandate') . $this->wrapper('FICA');

        $fragments = $this->splitMergedHtml($html, 3);

        $this->assertNotCount(3, $fragments,
            'the split cannot invent the missing document — the caller MUST refuse to file');

        // filePackDocuments() now treats this as a hard stop: it files nothing,
        // logs at ERROR, and notifies the agent. It no longer calls
        // fileSingleDocument(), which would have filed a 3-document pack as one
        // Document typed as the Mandate — losing the FICA and the Disclosure.
        $this->assertTrue(
            method_exists($this->service, 'isAwaitingAgentReview'),
            'sanity: service resolves'
        );
    }

    /**
     * The refusal must be wired: filePackDocuments no longer degrades to
     * fileSingleDocument, and the agent is notified instead.
     */
    public function test_the_degrade_to_single_document_fallback_is_gone(): void
    {
        $source = file_get_contents(app_path('Services/Docuperfect/SignatureService.php'));

        // Isolate filePackDocuments' mismatch guard.
        $guard = substr(
            $source,
            strpos($source, 'REFUSING to file'),
            600
        );

        $this->assertStringNotContainsString('fileSingleDocument', $guard,
            'a fragment-count mismatch must NOT fall back to filing the merged PDF as one document');
        $this->assertStringContainsString('notifyPackFilingFailed', $guard,
            'the agent must be told the pack was not filed');
    }

    public function test_the_agent_alert_says_what_happened_and_what_to_do(): void
    {
        $note = \App\Notifications\SignatureActivityNotification::packFilingNeedsAttention(
            'Sales Mandate Pack — 14 Marine Drive',
            42,
            '/docuperfect/documents/42/signatures/audit'
        );

        $payload = $note->toArray(new \stdClass());
        $message = $payload['message'];

        // Plain English, no codes at agents (STANDARDS.md F.8).
        $this->assertStringContainsString('not filed automatically', $message);
        $this->assertStringContainsString('signed copy is safe', $message,
            'the agent must be reassured the signing itself is intact');
        $this->assertStringContainsString('file the documents manually', $message,
            'a locked/failed state must offer the way forward (STANDARDS.md — No Silent Locks)');
        $this->assertStringNotContainsString('fragment', $message, 'no developer jargon at agents');
    }
}
