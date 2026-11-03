<?php

declare(strict_types=1);

namespace App\Services\Prospecting\Matching;

/** AT-520 4.4: the four matching rules behind one name list (R3 portal-group, R4 twin, R5 complex, R6 map-point). Each reads its own agency settings. */
final class MatchingRules
{
    public const RULES = ['portal-group', 'twin', 'complex', 'map-point', 'unit-split', 'link-audit', 'backlog-collapse'];

    public function __construct(
        private readonly PortalGroupLinker $groups,
        private readonly SameAgencyTwinMatcher $twins,
        private readonly ComplexGrouping $complexes,
        private readonly MapPointCheck $mapPoints,
        private readonly UnitSplitReview $unitSplits,
        private readonly AdvertLinkAudit $audit,
        private readonly BacklogCollapse $backlog,
    ) {
    }

    /**
     * @param  list<string>|null $only
     * @return array<string,array<string,int|string>>
     */
    public function run(int $agencyId, bool $apply, ?array $only = null): array
    {
        $out = [];
        foreach (self::RULES as $rule) {
            if ($only !== null && ! in_array($rule, $only, true)) {
                continue;
            }
            $out[$rule] = match ($rule) {
                'portal-group' => $this->groups->run($agencyId, $apply),
                'twin'         => $this->twins->run($agencyId, $apply),
                'complex'      => $this->complexes->run($agencyId, $apply),
                'map-point'    => $this->mapPoints->run($agencyId, $apply),
                'unit-split'   => $this->unitSplits->run($agencyId, $apply),
                'link-audit'   => $this->linkAudit($agencyId, $apply),
                'backlog-collapse' => $this->backlog->run($agencyId, $apply),
            };
        }

        return $out;
    }

    /** the advert-vs-file link audit as an automatic rule (behind the "tidy up" switch); the sample list is dropped, only the counts are kept */
    private function linkAudit(int $agencyId, bool $apply): array
    {
        if (! \App\Models\AddressMatchSetting::forAgency($agencyId)['auto_tidy']) {
            return ['skipped' => 'switched off'];
        }
        $r = $this->audit->run($agencyId, $apply);
        unset($r['samples']);

        return array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $r);
    }
}
