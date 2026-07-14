<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use Illuminate\Support\Str;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * AT-267 — Assistants, Prompt D: every `scopeVisibleTo()` resolves 'own' through
 * User::dataIdentityIds(), or is on an explicit allowlist with a written reason.
 *
 * WHY THIS FILE EXISTS. The identical failure already happened once in this codebase, to
 * BranchScope: it was attached to the models that existed when it was written, and every
 * model added afterwards was born unscoped — 15 of them, including the deal pipeline and
 * commission. Nothing ever failed, because no test ever turned the feature on. The coverage
 * rotted silently for months (see branch-isolation-spec §7a).
 *
 * The same rot is available here, and it is worse. A `scopeVisibleTo()` added next month
 * that filters on `$user->id` would not break anything visibly — it would just quietly show
 * an assistant an EMPTY LIST where their agent's records should be, and the agent would
 * conclude the feature is broken and go back to sharing their password. Which is the exact
 * problem Assistants exists to solve.
 *
 * So: a model whose scopeVisibleTo mentions `$user->id` must either use dataIdentityIds(),
 * or be listed in PRIVATE_TO_SELF below. Adding a model to that list is a design decision —
 * it is not a way to silence this test.
 */
final class AssistantVisibilityCoverageTest extends TestCase
{
    /**
     * Models whose 'own' scope is deliberately the ACTING USER and never their Assigned
     * Agent. Each entry is a decision, and each carries its reason.
     */
    private const PRIVATE_TO_SELF = [
        // An agent talks to Ellie the way they'd think out loud — about their own deals,
        // their own targets, sometimes their own colleagues. An assistant must not read it.
        // Not everything an agent can see is something the agent meant to delegate.
        \App\Models\AiConversation::class => 'Private AI conversations — an assistant must never read their agent\'s Ellie history.',
    ];

    /**
     * Models with a scopeVisibleTo that has no user-owned 'own' tier at all — visibility is
     * decided by is_global / branch pivots, so there is no identity to translate. An assistant
     * inherits their agent's branch, so these already behave correctly.
     */
    private const NO_OWN_TIER = [
        \App\Models\Docuperfect\Clause::class,
        \App\Models\Docuperfect\Pack::class,
        \App\Models\Docuperfect\Template::class,
    ];

    public function test_every_visible_to_scope_resolves_own_through_data_identity_ids(): void
    {
        $offenders = [];
        $checked   = 0;

        foreach ($this->modelsWithVisibleToScope() as $class => $body) {
            if (isset(self::PRIVATE_TO_SELF[$class]) || in_array($class, self::NO_OWN_TIER, true)) {
                continue;
            }

            $checked++;

            // The tell: filtering a row set by the ACTING user's id. For an assistant that is
            // the wrong person — it must be their Assigned Agent's id (or both), which is what
            // dataIdentityIds() returns.
            $usesRawUserId = (bool) preg_match('/\$user->id\b/', $body);
            $usesIdentity  = str_contains($body, 'dataIdentityIds()');

            if ($usesRawUserId && !$usesIdentity) {
                $offenders[] = $class;
            }
        }

        $this->assertGreaterThan(
            15,
            $checked,
            'Expected to have scanned the full set of visibleTo models — the scanner found almost none, which means it is broken, not that the codebase is clean.'
        );

        $this->assertSame([], $offenders, sprintf(
            "These models filter scopeVisibleTo() by \$user->id instead of \$user->dataIdentityIds():\n  - %s\n\n"
            . "An assistant would see an EMPTY LIST where their agent's records should be.\n"
            . "Fix: swap ->where(col, \$user->id) for ->whereIn(col, \$user->dataIdentityIds()).\n"
            . "If the data is genuinely private to the acting user, add the model to PRIVATE_TO_SELF WITH A REASON.",
            implode("\n  - ", $offenders)
        ));
    }

    /**
     * scopeVisibleTo() is not the only visibility mechanism in CoreX — Contacts use a GLOBAL
     * scope (ContactScope) instead, and it was very nearly missed. A global scope that filters
     * by the acting user's id has exactly the same defect, so scan those too.
     *
     * These have to be handled by hand rather than by a `dataIdentityIds()` substring, because
     * a global scope also has to fail CLOSED for assistants: ContactScope's null branch used to
     * mean "no restriction", which for an assistant would have meant seeing the WHOLE AGENCY —
     * more than their own agent can see. So the requirement is that the scope KNOWS about
     * assistants at all, not merely that it mentions the helper.
     */
    public function test_every_user_identity_global_scope_handles_assistants(): void
    {
        // PARTITION scopes slice by TENANT (agency / branch), not by record owner. They touch
        // the acting user's id only for the self-row carve-out on the `users` table — so that a
        // stale session agency cannot make a user invisible to themselves and log them out.
        // That is not ownership, and an assistant inherits their agent's agency and branch
        // anyway, so there is nothing to translate.
        $partitionScopes = [
            'AgencyScope'     => 'Tenant partition (agency_id). Touches $authId only for the users-table self-row carve-out.',
            'BranchScope'     => 'Branch partition (branch_id). Same self-row carve-out; an assistant inherits the agent\'s branch.',
            'DealBranchScope' => 'Branch partition via the deal_branches pivot. No user identity.',
        ];

        $offenders = [];

        foreach (glob(app_path('Models/Scopes/*.php')) as $path) {
            $source = (string) file_get_contents($path);
            $name   = basename($path, '.php');

            if (isset($partitionScopes[$name])) {
                continue;
            }

            // Does this scope filter rows by WHO the acting user is? (as opposed to agency /
            // branch / status scopes, which carry no user identity)
            $filtersByUserIdentity = preg_match('/\$user->getKey\(\)|\$user->id\b|\$userId\b/', $source);

            if (!$filtersByUserIdentity) {
                continue;
            }

            if (!str_contains($source, 'is_assistant')) {
                $offenders[] = $name;
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These global scopes filter by the acting user's identity but do not handle assistants:\n  - %s\n\n"
            . "An assistant would see the wrong person's records — or, if the scope falls open on a null\n"
            . "data scope, the WHOLE AGENCY's records, which is more than their own agent can see.\n"
            . "Fix: branch on \$user->is_assistant, resolve identity via dataIdentityIds(), and FAIL CLOSED on null.",
            implode("\n  - ", $offenders)
        ));
    }

    public function test_the_allowlisted_models_still_exist_and_still_have_the_scope(): void
    {
        // An allowlist entry for a model that has been deleted or refactored is a lie that
        // makes the suite look greener than it is.
        foreach (array_keys(self::PRIVATE_TO_SELF) as $class) {
            $this->assertTrue(class_exists($class), "PRIVATE_TO_SELF names a class that no longer exists: {$class}");
            $this->assertTrue(
                method_exists($class, 'scopeVisibleTo'),
                "{$class} is allowlisted for scopeVisibleTo but no longer defines it — remove the entry."
            );
        }

        foreach (self::NO_OWN_TIER as $class) {
            $this->assertTrue(class_exists($class), "NO_OWN_TIER names a class that no longer exists: {$class}");
        }
    }

    public function test_every_allowlist_entry_carries_a_reason(): void
    {
        foreach (self::PRIVATE_TO_SELF as $class => $reason) {
            $this->assertNotEmpty(
                trim((string) $reason),
                "Allowlisting {$class} is a design decision. Write down why."
            );
        }
    }

    /**
     * @return array<class-string, string> model FQCN => the source of its scopeVisibleTo body
     */
    private function modelsWithVisibleToScope(): array
    {
        $found = [];

        $finder = (new Finder())->files()->in(app_path('Models'))->name('*.php');

        /** @var SplFileInfo $file */
        foreach ($finder as $file) {
            $source = $file->getContents();

            if (!str_contains($source, 'function scopeVisibleTo')) {
                continue;
            }

            $class = $this->classFor($file);

            if (!$class || !class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || !$reflection->hasMethod('scopeVisibleTo')) {
                continue;
            }

            $method = $reflection->getMethod('scopeVisibleTo');

            // Only judge the model that DECLARES the scope, not one that inherits it.
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $lines = file($reflection->getFileName());
            $body  = implode('', array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1
            ));

            $found[$class] = $body;
        }

        return $found;
    }

    private function classFor(SplFileInfo $file): ?string
    {
        $relative = Str::after($file->getRealPath(), realpath(app_path()) . DIRECTORY_SEPARATOR);
        $relative = str_replace(['/', '\\'], '\\', $relative);

        return 'App\\' . Str::beforeLast($relative, '.php');
    }
}
