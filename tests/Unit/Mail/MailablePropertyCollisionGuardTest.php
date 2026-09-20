<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use PHPUnit\Framework\TestCase;

/**
 * Bug class, not an instance (Johan's own rule) — this has now bitten three
 * times: RentalApplicationReturnedMail broke real production-flow submissions
 * on QA1 (found 2026-09-19); the same night, cc4 hit it again while writing
 * three brand-new work-order mailables in one sitting. The shape every time:
 * a Mailable declares its own `public $queue = 'mail';` directly on the
 * class, ALONGSIDE `use Queueable, SerializesModels;` — and
 * `Illuminate\Bus\Queueable` already declares `public $queue;` with no
 * default. PHP requires a class's own property declaration and a USED
 * TRAIT's declaration of the same name to have IDENTICAL defaults; `'mail'`
 * vs (implicitly) `null` are not identical, so PHP refuses to compose the
 * class at all — a fatal error at class-load (autoload) time, before the
 * constructor, before any method body, before any try/catch anywhere in the
 * call stack gets a chance to run. Confirmed directly (not assumed) via a
 * live probe: attempting to autoload one of these broken classes in-process
 * takes the ENTIRE PHP PROCESS down (exit 255) — this is exactly why the
 * bug is so expensive: the symptom (a 500, a dead request, a worker that
 * silently stops) gives whoever's debugging it almost nothing to go on.
 *
 * WHY THIS GUARD CHECKS BY ACTUALLY LOADING EACH CLASS, IN ITS OWN ISOLATED
 * SUBPROCESS, rather than parsing source or reflecting in-process:
 *
 *   - Reflecting a BROKEN Mailable in-process (`new ReflectionClass(...)`,
 *     even a bare `class_exists($x, true)`) would trigger the exact same
 *     fatal and kill THIS WHOLE TEST RUN, not just fail one assertion —
 *     proven directly, not assumed, the same way the production bug was.
 *     A guard that can only prove a class is broken by taking the test
 *     suite down with it is not a guard anyone would keep.
 *   - Letting the real PHP engine do the check (in a throwaway subprocess)
 *     is also strictly more CORRECT than re-implementing "which defaults
 *     count as different" by hand: PHP's own composition rules have a real
 *     nuance that a naive text-based check (e.g. "does this class declare
 *     $queue") gets wrong. Confirmed empirically on this exact codebase:
 *     RentalApplicationInviteMail and RentalApplicationReopenedMail both
 *     declare `public $queue = 'mail';` too, and LOOK identical to the four
 *     genuinely-broken files at a glance — but they extend
 *     App\Mail\Signatures\BaseSignatureMail, which is the class that
 *     actually `use`s Queueable; by the time a grandchild class overrides
 *     an already-resolved inherited property, that's ordinary, always-legal
 *     property shadowing, not a trait collision — PHP only refuses
 *     composition at the exact level a class directly `use`s the
 *     conflicting trait. A guard that flagged those two as broken would be
 *     wrong, and untrustworthy the next time it fired on something that
 *     genuinely wasn't. Only a real load, via the real engine, gets this
 *     right in every case without hand-modelling PHP's own trait-resolution
 *     rules.
 *   - This also means the guard is NOT hard-coded to the name `$queue` —
 *     it catches the collision SHAPE (any property name a Mailable
 *     redeclares that a trait IT DIRECTLY USES already provides with an
 *     incompatible default), because it never inspects property names at
 *     all; it just asks the one question that actually matters, "does this
 *     class load," the same way production asks it.
 *
 * Zero database use — pure filesystem discovery + isolated `php` subprocess
 * calls. Extends plain PHPUnit\Framework\TestCase (not Tests\TestCase)
 * deliberately, so this can never be mistaken for needing one.
 */
final class MailablePropertyCollisionGuardTest extends TestCase
{
    private const MAIL_ROOT = __DIR__ . '/../../../app/Mail';
    private const VENDOR_AUTOLOAD = __DIR__ . '/../../../vendor/autoload.php';

    public function test_no_mailable_class_fails_to_load(): void
    {
        $files = $this->discoverPhpFiles(self::MAIL_ROOT);
        $this->assertNotEmpty(
            $files,
            'No .php files found under app/Mail — the discovery glob itself is broken; fix the glob before trusting a green result from this test.'
        );

        $checked = 0;
        $failures = [];

        foreach ($files as $file) {
            $class = $this->fqcnFromPath($file);
            $probe = $this->probeClassLoad($class);

            // A file that isn't a real class under that name (rare, but a
            // trait/interface/abstract-with-no-matching-filename would look
            // this way) is not this guard's concern — skip it rather than
            // report a false failure for something that was never a loadable
            // class to begin with.
            if ($probe === self::PROBE_NOT_A_CLASS) {
                continue;
            }

            $checked++;

            if ($probe !== null) {
                $failures[] = $this->formatFailure($class, $file, $probe);
            }
        }

        $this->assertGreaterThan(
            0,
            $checked,
            'Every discovered file under app/Mail resolved to "not a class" — the FQCN derivation is wrong, this test is not actually checking anything.'
        );

        $this->assertSame([], $failures, implode("\n\n", $failures));
    }

    /**
     * Deliberately proves the guard does NOT false-positive on a Mailable
     * that legitimately redeclares a Queueable property with the SAME
     * default value — legal in PHP (mere redundancy, not a conflict), and a
     * real pattern this codebase could reasonably use on purpose. A guard
     * that can't tell "same value, harmless" from "different value, fatal"
     * would be noisy enough to get disabled within a week.
     */
    public function test_a_property_redeclared_with_an_identical_default_is_not_flagged(): void
    {
        $fixtureFile = sys_get_temp_dir() . '/mailable_guard_fixture_same_default_' . getmypid() . '.php';
        file_put_contents($fixtureFile, <<<'PHP'
<?php
class MailableGuardFixtureSameDefault
{
    use \Illuminate\Bus\Queueable;
    public $queue = null;
}
PHP
        );

        try {
            $probe = $this->probeClassLoadFromFile($fixtureFile, 'MailableGuardFixtureSameDefault');
            $this->assertNull($probe, "A same-value redeclaration must never be flagged, but got:\n{$probe}");
        } finally {
            @unlink($fixtureFile);
        }
    }

    /**
     * Sibling proof, the other direction: an UNRELATED property name (no
     * collision at all, never touches anything Queueable provides) must
     * never be flagged either.
     */
    public function test_an_unrelated_property_is_not_flagged(): void
    {
        $fixtureFile = sys_get_temp_dir() . '/mailable_guard_fixture_unrelated_' . getmypid() . '.php';
        file_put_contents($fixtureFile, <<<'PHP'
<?php
class MailableGuardFixtureUnrelatedProperty
{
    use \Illuminate\Bus\Queueable;
    public $reviewUrl = 'https://example.test';
}
PHP
        );

        try {
            $probe = $this->probeClassLoadFromFile($fixtureFile, 'MailableGuardFixtureUnrelatedProperty');
            $this->assertNull($probe, "An unrelated property must never be flagged, but got:\n{$probe}");
        } finally {
            @unlink($fixtureFile);
        }
    }

    /**
     * Sibling proof this guard catches the CLASS of mistake, not just the
     * one property name every real occurrence happened to use so far.
     * Queueable provides several properties beyond $queue (connection,
     * delay, middleware, chained, ...) — this reconstructs the identical
     * collision shape against a DIFFERENT one ($middleware, which the trait
     * defaults to an empty array) to prove the guard was never secretly
     * hard-coded to the string "queue".
     */
    public function test_a_different_queueable_property_with_an_incompatible_default_is_caught(): void
    {
        $fixtureFile = sys_get_temp_dir() . '/mailable_guard_fixture_middleware_' . getmypid() . '.php';
        file_put_contents($fixtureFile, <<<'PHP'
<?php
class MailableGuardFixtureMiddlewareCollision
{
    use \Illuminate\Bus\Queueable;
    public $middleware = ['something'];
}
PHP
        );

        try {
            $probe = $this->probeClassLoadFromFile($fixtureFile, 'MailableGuardFixtureMiddlewareCollision');
            $this->assertNotNull($probe, 'A genuinely incompatible redeclaration of a DIFFERENT Queueable property ($middleware, not $queue) must be caught — if this is null, the guard is checking for the literal name "queue", not the collision shape.');
            $this->assertStringContainsString('middleware', $probe);
        } finally {
            @unlink($fixtureFile);
        }
    }

    private const PROBE_NOT_A_CLASS = '__not_a_class__';

    /**
     * @return list<string> absolute file paths
     */
    private function discoverPhpFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * PSR-4 derivation (app/ -> App\), matching this codebase's own
     * composer.json mapping — deliberately not parsing `namespace`/`class`
     * tokens out of the file, since that would mean partially loading the
     * very source this test cannot safely fully load.
     */
    private function fqcnFromPath(string $absolutePath): string
    {
        $appRoot = realpath(__DIR__ . '/../../../app');
        $relative = ltrim(str_replace($appRoot, '', realpath($absolutePath) ?: $absolutePath), '/');
        $relative = substr($relative, 0, -4); // strip ".php"

        return 'App\\' . str_replace('/', '\\', $relative);
    }

    /**
     * @return string|null|self::PROBE_NOT_A_CLASS null = loaded clean; a
     *         string = the captured fatal (or other) output; PROBE_NOT_A_CLASS
     *         = nothing by that name exists (skip, not a failure).
     */
    private function probeClassLoad(string $fqcn): ?string
    {
        $php = PHP_BINARY;
        $autoload = self::VENDOR_AUTOLOAD;
        $code = 'require ' . var_export($autoload, true) . ';'
            . 'if (!class_exists(' . var_export($fqcn, true) . ', true) && !trait_exists(' . var_export($fqcn, true) . ', true) && !interface_exists(' . var_export($fqcn, true) . ', true)) { echo "' . self::PROBE_NOT_A_CLASS . '"; exit(0); }'
            . 'echo "LOADED_OK";';

        $output = shell_exec($php . ' -d display_errors=1 -r ' . escapeshellarg($code) . ' 2>&1');
        $output = trim((string) $output);

        if ($output === self::PROBE_NOT_A_CLASS) {
            return self::PROBE_NOT_A_CLASS;
        }
        if ($output === 'LOADED_OK') {
            return null;
        }

        return $output !== '' ? $output : 'Subprocess produced no output at all (unexpected) — treat as a failure worth investigating by hand.';
    }

    /** Same isolation mechanism, for a throwaway single-file fixture rather than an app/Mail file. */
    private function probeClassLoadFromFile(string $file, string $className): ?string
    {
        $php = PHP_BINARY;
        $code = 'require ' . var_export(self::VENDOR_AUTOLOAD, true) . ';'
            . 'require ' . var_export($file, true) . ';'
            . 'echo "LOADED_OK";';

        $output = shell_exec($php . ' -d display_errors=1 -r ' . escapeshellarg($code) . ' 2>&1');
        $output = trim((string) $output);

        return $output === 'LOADED_OK' ? null : ($output !== '' ? $output : 'Subprocess produced no output at all.');
    }

    private function formatFailure(string $class, string $file, string $probeOutput): string
    {
        return "FAILED TO LOAD: {$class}\n"
            . "  File: {$file}\n"
            . "  This means an agent/mailer/notification path that constructs this class will 500 the whole request — not degrade, not log a warning, the process dies before any try/catch runs.\n"
            . "  What to do: read the raw PHP error below. If it says a class/trait 'define the same property', the class declares its own copy of a property a `use`d trait already provides with a different default (usually Illuminate\\Bus\\Queueable's \$queue, \$connection, \$middleware, etc). Fix: delete the class's own property declaration and set the value in the constructor instead — e.g. \$this->onQueue('mail') instead of `public \$queue = 'mail';` alongside `use Queueable`.\n"
            . "  Raw error:\n    " . str_replace("\n", "\n    ", $probeOutput);
    }
}
