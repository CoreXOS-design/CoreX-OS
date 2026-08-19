<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Candidate;

use App\Services\Docuperfect\CandidateAuthoriserSurfaceInjector;
use Tests\TestCase;

/**
 * Candidate-flow rework — completeDocument()'s completeness/parity guard is
 * CandidateAuthoriserSurfaceInjector::unmirroredCandidateMarks($html, 'supervisor'). It keys on the
 * 'supervisor' checkpoint identity, which the rework RETAINS. So removing supervisor_final does NOT
 * break completeness: a candidate mark whose authoriser mirror carries the 'supervisor' identity
 * satisfies the guard.
 *
 * Fixtures mirror exactly what the injector stamps (data-authoriser-anchor on the candidate mark,
 * data-authoriser-mirror-for + data-recipient-identity="supervisor" + data-authoriser-mirror on the
 * mirror), so the guard is exercised directly, independent of the injector's DOM placement logic.
 */
final class CandidateAuthoriserParityTest extends TestCase
{
    private function candidateMark(bool $withSupervisorMirror): string
    {
        $mark = '<span data-marker-party="agent" data-marker-type="initial" data-marker-index="1" '
              . 'data-authoriser-anchor="a1"></span>';

        // A FILLED supervisor mirror — data-signed="true" is what the authoriser's initial stamps
        // (markIsFilled), so the completeness guard counts it as satisfied.
        $mirror = $withSupervisorMirror
            ? '<span data-marker-party="supervisor" data-marker-type="initial" '
              . 'data-recipient-identity="supervisor" data-authoriser-mirror="true" '
              . 'data-authoriser-mirror-for="a1" data-signed="true"></span>'
            : '';

        return '<div class="corex-document-wrapper"><div class="corex-page-initials-row">'
            . $mark . $mirror
            . '</div></div>';
    }

    public function test_candidate_mark_without_a_supervisor_mirror_is_flagged_incomplete(): void
    {
        $unmirrored = CandidateAuthoriserSurfaceInjector::unmirroredCandidateMarks(
            $this->candidateMark(withSupervisorMirror: false),
            'supervisor',
        );
        $this->assertNotEmpty($unmirrored, 'a candidate mark with no supervisor mirror must fail the guard');
    }

    public function test_supervisor_identity_mirror_satisfies_the_completeness_guard(): void
    {
        $unmirrored = CandidateAuthoriserSurfaceInjector::unmirroredCandidateMarks(
            $this->candidateMark(withSupervisorMirror: true),
            'supervisor',
        );
        $this->assertSame(
            [],
            $unmirrored,
            'a supervisor-identity mirror satisfies the guard — supervisor_final is not needed for completeness',
        );
    }
}
