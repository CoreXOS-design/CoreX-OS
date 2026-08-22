<?php

/**
 * Township vs. marketing/informal suburb name aliases (matcher-accuracy build,
 * 2026-08-22). Johan's known open fault: two names for the same physical area
 * ("Three Hills" / "Leisure Bay" — real KZN South Coast tracked_properties
 * data) normalise to two unrelated strings, so every suburb-keyed matching
 * strategy treats them as different places.
 *
 * Each entry in `groups` is a list of NORMALISED suburb strings (i.e. already
 * lowercase, punctuation-stripped — run through
 * TrackedPropertyAddress::normaliseSuburb() logic, minus the alias step
 * itself) that all refer to the same physical area. The FIRST entry in each
 * group is the canonical form everything else in the group resolves to.
 *
 * Evidence-seeded only — never invented wholesale. Extend this list as more
 * aliases are confirmed against real data; TrackedPropertyAddress::
 * canonicaliseSuburbAlias() never needs to change when you do.
 */

return [
    'groups' => [
        ['leisure bay', 'three hills'],
    ],
];
