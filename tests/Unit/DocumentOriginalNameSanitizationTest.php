<?php

namespace Tests\Unit;

use App\Models\Document;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Tests\TestCase;

/**
 * A stored document display name may never contain a path separator.
 *
 * Symfony's Content-Disposition builder rejects "/" and "\", so a name
 * carrying one made EVERY download and inline view of that document a 500 —
 * the bytes were fine, the name was unusable. The live trigger was the PDF
 * splitter composing "Subject · DocType · Date.pdf" over the document-type
 * label "IDs / Identity". These assertions lock the class shut at the model,
 * which is the one gate every writer of a Document passes through.
 */
class DocumentOriginalNameSanitizationTest extends TestCase
{
    public function test_it_strips_a_forward_slash_from_a_stored_name(): void
    {
        $doc = new Document;
        $doc->original_name = 'Coenraad Van Dyk · IDs / Identity · 2026-09-07.pdf';

        $this->assertSame('Coenraad Van Dyk · IDs - Identity · 2026-09-07.pdf', $doc->original_name);
    }

    public function test_it_strips_a_backslash_from_a_stored_name(): void
    {
        $doc = new Document;
        $doc->original_name = 'scan\\batch 4.pdf';

        $this->assertSame('scan-batch 4.pdf', $doc->original_name);
    }

    public function test_it_leaves_an_ordinary_name_untouched(): void
    {
        $doc = new Document;
        $doc->original_name = 'Mandate · 2026-09-07.pdf';

        $this->assertSame('Mandate · 2026-09-07.pdf', $doc->original_name);
    }

    public function test_it_preserves_a_null_name(): void
    {
        $doc = new Document;
        $doc->original_name = null;

        $this->assertNull($doc->original_name);
    }

    /**
     * The point of the sanitiser is not the string, it is that the serve path
     * can no longer throw. Assert against the actual builder that used to.
     */
    public function test_a_sanitised_name_can_always_build_a_content_disposition_header(): void
    {
        $names = [
            'Coenraad Van Dyk · IDs / Identity · 2026-09-07.pdf',
            'a/b\\c.pdf',
            '/leading.pdf',
            'trailing/',
        ];

        foreach ($names as $name) {
            $doc = new Document;
            $doc->original_name = $name;

            $header = HeaderUtils::makeDisposition('inline', (string) $doc->original_name, 'file');

            $this->assertStringContainsString('inline', $header);
        }
    }
}
