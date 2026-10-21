<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Services\Docuperfect\WebTemplateBladeEnsurer;
use Tests\Feature\Docuperfect\PaperRouteFixtureCase;

/**
 * HOTFIX 2026-10-21 — a web template renders from a GENERATED, untracked blade file. Every route that renders it
 * (electronic single + pack, recipient signing views, freshness guard) now asks WebTemplateBladeEnsurer for the view
 * name, so a missing file is regenerated from the template's stored HTML instead of failing the send — exactly as the
 * wizard preview and the paper routes already did. Where the file exists, ensure() returns the same name and touches
 * nothing, so output is identical.
 */
final class MissingTemplateFileNeverFailsAnyRouteTest extends PaperRouteFixtureCase
{
    private function prepareSigning($flow)
    {
        return $this->actingAs($this->agent)->post(route('docuperfect.esign.prepareSigning', $flow->id), ['_json' => true], ['Accept' => 'application/json']);
    }

    private function bladePath(int $id): string
    {
        return resource_path('views/docuperfect/web-templates/cds/template-' . $id . '.blade.php');
    }

    public function test_electronic_single_document_with_a_missing_template_file_is_regenerated_not_an_error(): void
    {
        $this->assertFileDoesNotExist($this->bladePath($this->templates[0]->id));
        $flow = $this->flow([$this->templates[0]], 2, false);

        $res = $this->prepareSigning($flow);

        $this->assertSame(200, $res->getStatusCode(), substr($res->getContent(), 0, 400));
        $this->assertFileExists($this->bladePath($this->templates[0]->id));
        $doc = Document::latest('id')->first();
        $this->assertStringContainsString('PACKDOC1 TITLE', (string) ($doc->web_template_data['merged_html'] ?? ''));
    }

    public function test_electronic_pack_with_missing_template_files_is_regenerated_not_an_error(): void
    {
        foreach ($this->templates as $t) {
            $this->assertFileDoesNotExist($this->bladePath($t->id));
        }
        $flow = $this->flow($this->templates, 2, true);

        $res = $this->prepareSigning($flow);

        $this->assertSame(200, $res->getStatusCode(), substr($res->getContent(), 0, 400));
        $merged = (string) Document::latest('id')->first()->web_template_data['merged_html'];
        foreach ([1, 2, 3] as $n) {
            $this->assertStringContainsString("PACKDOC{$n} TITLE", $merged);
        }
    }

    public function test_an_existing_file_is_left_untouched_and_the_same_view_name_is_used(): void
    {
        $ensurer = app(WebTemplateBladeEnsurer::class);
        $t = $this->templates[0];
        $name = $ensurer->ensure($t);          // first use generates it
        $before = md5_file($this->bladePath($t->id));
        $mtime = filemtime($this->bladePath($t->id));
        clearstatcache();

        $this->assertSame($name, $ensurer->ensure($t->fresh()), 'same view name');
        $this->assertSame($t->blade_view, $name);
        clearstatcache();
        $this->assertSame($before, md5_file($this->bladePath($t->id)), 'file content untouched');
        $this->assertSame($mtime, filemtime($this->bladePath($t->id)), 'file not rewritten');
    }
}
