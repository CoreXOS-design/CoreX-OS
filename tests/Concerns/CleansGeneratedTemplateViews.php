<?php

namespace Tests\Concerns;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * DocumentTemplateGenerator::generate() writes the new template's Blade view into the APP's own
 * resources/views/docuperfect/web-templates/imported/ folder (a real file, not a fake disk), so a test that
 * generates a template used to leave a file behind in the working tree on every run - one of which was
 * committed by mistake (imported-test-document.blade.php). This removes exactly the files a test created:
 * it takes a listing of that folder before the test and deletes whatever is new afterwards. Files that were
 * already there (tracked or not) are never touched.
 */
trait CleansGeneratedTemplateViews
{
    /** @var string[] */
    private array $importedViewsBefore = [];

    private function importedViewsDir(): string
    {
        // not resource_path(): #[Before] hooks run before the application exists
        return dirname(__DIR__, 2) . '/resources/views/docuperfect/web-templates/imported';
    }

    #[Before]
    protected function snapshotImportedViews(): void
    {
        $this->importedViewsBefore = is_dir($this->importedViewsDir()) ? (scandir($this->importedViewsDir()) ?: []) : [];
    }

    #[After]
    protected function removeImportedViewsCreatedByThisTest(): void
    {
        $dir = $this->importedViewsDir();
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], $this->importedViewsBefore) as $file) {
            if (str_ends_with($file, '.blade.php') && is_file($dir . '/' . $file)) {
                @unlink($dir . '/' . $file);
            }
        }
    }
}
