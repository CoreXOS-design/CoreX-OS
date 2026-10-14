<?php

declare(strict_types=1);

namespace App\Support\Docuperfect;

use App\Models\Agency;
use App\Models\Branch;
use Illuminate\Support\Facades\Auth;

/**
 * WHICH agency's letterhead / wording does a rendered document carry?
 *
 * One answer, used by every document surface (the shared company header, corex-document, the baked-header
 * refresher at signing time, and the agency-name wording inside the web templates):
 *
 *   1. the agency (and branch) of the DOCUMENT being rendered, when the caller says so — `with()`. This is how a
 *      public signing page, a portal page or a PDF knows whose document it is when nobody is signed in, and how a
 *      staff member of agency A who opens agency B's document (an owner on the agency switcher) still sees B's;
 *   2. else the signed-in user's own branch, then their effective agency;
 *   3. else NOTHING. There is no fallback agency: the old "else the agency whose slug is hfc-coastal" printed Home
 *      Finders Coastal's letterhead on every other agency's document the moment nobody was signed in.
 *
 * Request-scoped and re-entrant (a stack), so a nested render restores the outer context.
 */
final class DocumentAgencyContext
{
    /** @var list<array{0: ?int, 1: ?int}> */
    private static array $stack = [];

    /**
     * Run $render with the document's own agency (and optionally branch) in force.
     *
     * @template T
     * @param  \Closure(): T  $render
     * @return T
     */
    public static function with(?int $agencyId, ?int $branchId, \Closure $render): mixed
    {
        if ($agencyId === null && $branchId === null) {
            return $render(); // nothing to pin: whatever context is already in force stays
        }

        self::$stack[] = [$agencyId, $branchId];
        try {
            return $render();
        } finally {
            array_pop(self::$stack);
        }
    }

    /**
     * Pin the document's agency for the REST OF THIS REQUEST — for a controller action that renders one document
     * through many nested calls (public signing page, document view). Request-scoped by construction: PHP-FPM
     * starts every request clean; a queue worker or a test must use with() or flush().
     */
    public static function enter(?int $agencyId, ?int $branchId = null): void
    {
        self::$stack[] = [$agencyId, $branchId];
    }

    /**
     * @return array{0: ?Agency, 1: ?Branch} [agency, branch] — branch only when it belongs to that agency.
     */
    public static function resolve(): array
    {
        [$agencyId, $branchId] = self::$stack === [] ? [null, null] : self::$stack[array_key_last(self::$stack)];

        if ($agencyId === null && $branchId === null) {
            $user = Auth::user();
            if ($user !== null) {
                $branchId = $user->effectiveBranchId();
                $agencyId = $user->effectiveAgencyId();
            }
        }

        return self::load($agencyId, $branchId);
    }

    public static function agency(): ?Agency
    {
        return self::resolve()[0];
    }

    /**
     * The words a template uses for "us": the branch's own value when it has one, else the agency's.
     * Every key is a plain string ('' when the agency has not filled it in) so a template can print it safely.
     *
     * @return array{name: string, name_upper: string, trading_name: string, email: string, phone: string, website: string, privacy_url: string}
     */
    public static function wording(): array
    {
        [$agency, $branch] = self::resolve();
        $pick = static function (string $field) use ($agency, $branch): string {
            if ($branch !== null && ! empty($branch->{$field})) {
                return trim((string) $branch->{$field});
            }

            return trim((string) ($agency?->{$field} ?? ''));
        };
        $name = trim((string) ($agency?->name ?? ''));

        return [
            'name'         => $name,
            'name_upper'   => mb_strtoupper($name),
            'trading_name' => $pick('trading_name'),
            'email'        => $pick('email'),
            'phone'        => $pick('phone'),
            'website'      => trim((string) ($agency?->website_url ?? '')),
            'privacy_url'  => $agency !== null ? trim((string) ($agency->effectivePopiUrl() ?? '')) : '',
        ];
    }

    /** Test seam / long-running worker hygiene: forget any leftover context. */
    public static function flush(): void
    {
        self::$stack = [];
    }

    /** @return array{0: ?Agency, 1: ?Branch} */
    private static function load(?int $agencyId, ?int $branchId): array
    {
        $branch = $branchId !== null ? Branch::withoutGlobalScopes()->with('agency')->find($branchId) : null;
        if ($branch !== null && $agencyId !== null && (int) $branch->agency_id !== $agencyId) {
            $branch = null; // a branch of some other agency never decorates this agency's document
        }

        $agency = $agencyId !== null
            ? Agency::withoutGlobalScopes()->find($agencyId)
            : $branch?->agency;

        return [$agency, $agency === null ? null : $branch];
    }
}
