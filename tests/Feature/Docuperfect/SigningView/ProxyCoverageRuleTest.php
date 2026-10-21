<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Support\Docuperfect\ProxyCoverage;
use PHPUnit\Framework\TestCase;

/**
 * AT-498 — "Proxy / signing on behalf of" (.ai/specs/claude_esignature_v2_spec.md, the Proxy section).
 *
 * A proxy signs for the other parties of its role EXCEPT the ones the agent un-ticked. No exclusions = everyone = the
 * rule this system always had; the legacy reference below is the OLD filterToSigningParticipants() verbatim, and the
 * equivalence test proves every flow that exists today (no proxy / proxy with no exclusions) resolves identically.
 */
final class ProxyCoverageRuleTest extends TestCase
{
    /** The pre-AT-498 rule, copied verbatim from ESignWizardController::filterToSigningParticipants(). */
    private function legacy(array $recipients): array
    {
        $proxyRoles = [];
        foreach ($recipients as $r) {
            if (!empty($r['_is_proxy'])) {
                $proxyRoles[strtolower($r['role'] ?? '')] = true;
            }
        }

        return array_values(array_filter($recipients, function (array $r) use ($proxyRoles) {
            if (!empty($r['_is_deceased'])) {
                return false;
            }
            $role = strtolower($r['role'] ?? '');
            if (!empty($proxyRoles[$role]) && empty($r['_is_proxy'])) {
                return false;
            }

            return true;
        }));
    }

    private function party(string $name, string $role = 'seller', array $extra = []): array
    {
        return array_merge([
            'role' => $role, 'name' => $name, 'id_number' => '800101500908' . strlen($name),
            '_recipient_local_key' => strtolower($name) . '-key',
        ], $extra);
    }

    private function names(array $rows): array
    {
        return array_map(fn ($r) => $r['name'], $rows);
    }

    public function test_every_existing_flow_resolves_identically_to_the_old_rule(): void
    {
        $roles = ['seller', 'buyer', 'landlord', 'tenant'];
        $checked = 0;
        // Every combination of up to 5 parties across two roles: each party is plain / proxy / deceased, with or without a key.
        for ($n = 1; $n <= 5; $n++) {
            $combos = 3 ** $n;
            for ($c = 0; $c < $combos; $c++) {
                foreach ($roles as $roleA) {
                    $rows = [];
                    $x = $c;
                    for ($i = 0; $i < $n; $i++) {
                        $state = $x % 3;
                        $x = intdiv($x, 3);
                        $row = ['role' => $i % 2 === 0 ? $roleA : 'buyer', 'name' => "P{$i}"];
                        if ($i % 3 !== 2) {
                            $row['_recipient_local_key'] = "k{$i}";
                        }
                        if ($state === 1) {
                            $row['_is_proxy'] = true;
                        }
                        if ($state === 2) {
                            $row['_is_deceased'] = true;
                        }
                        $rows[] = $row;
                    }
                    $this->assertSame($this->names($this->legacy($rows)), $this->names(ProxyCoverage::filterSigners($rows)), "n={$n} c={$c} role={$roleA}");
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(1000, $checked);
    }

    public function test_three_sellers_partial_proxy_a_signs_for_b_only_and_c_signs_for_themself(): void
    {
        $rows = [
            $this->party('A', 'seller', ['_is_proxy' => true, '_proxy_excludes' => ['c-key']]),
            $this->party('B'),
            $this->party('C'),
        ];

        $this->assertSame(['A', 'C'], $this->names(ProxyCoverage::filterSigners($rows)), '2 recipients: A (for A+B) and C (for self)');
        $this->assertSame([0 => [1]], ProxyCoverage::coverageMap($rows));
        $this->assertNull(ProxyCoverage::violation($rows));
    }

    public function test_full_proxy_covers_everyone_else_exactly_as_before(): void
    {
        $rows = [$this->party('A', 'seller', ['_is_proxy' => true]), $this->party('B'), $this->party('C')];

        $this->assertSame(['A'], $this->names(ProxyCoverage::filterSigners($rows)));
        $this->assertSame([0 => [1, 2]], ProxyCoverage::coverageMap($rows));
        // an explicit empty list is the same thing
        $rows[0]['_proxy_excludes'] = [];
        $this->assertSame(['A'], $this->names(ProxyCoverage::filterSigners($rows)));
    }

    public function test_no_proxy_everyone_signs(): void
    {
        $rows = [$this->party('A'), $this->party('B'), $this->party('C')];

        $this->assertSame(['A', 'B', 'C'], $this->names(ProxyCoverage::filterSigners($rows)));
        $this->assertSame([], ProxyCoverage::coverageMap($rows));
        $this->assertSame($rows, ProxyCoverage::withRepresentation($rows), 'wording untouched without a proxy');
    }

    public function test_a_proxy_who_signs_for_nobody_is_refused(): void
    {
        $rows = [
            $this->party('A', 'seller', ['_is_proxy' => true, '_proxy_excludes' => ['b-key', 'c-key']]),
            $this->party('B'),
            $this->party('C'),
        ];
        $this->assertStringContainsString('signs for nobody', (string) ProxyCoverage::violation($rows));

        $alone = [$this->party('A', 'seller', ['_is_proxy' => true])];
        $this->assertStringContainsString('signs for nobody', (string) ProxyCoverage::violation($alone));
    }

    /** @return array<string, array{0:int}> */
    public static function partyCounts(): array
    {
        return ['3 parties' => [3], '4 parties' => [4], '6 parties' => [6]];
    }

    /**
     * @dataProvider partyCounts
     */
    public function test_any_number_of_parties_partial_proxy_covers_only_the_ticked_and_nobody_is_dropped(int $n): void
    {
        foreach (['seller', 'landlord', 'buyer', 'tenant'] as $role) {
            $rows = [];
            for ($i = 0; $i < $n; $i++) {
                $rows[] = $this->party("P{$i}", $role);
            }
            // P0 is proxy for everyone except the LAST party.
            $rows[0]['_is_proxy'] = true;
            $rows[0]['_proxy_excludes'] = [$rows[$n - 1]['_recipient_local_key']];

            $signers = $this->names(ProxyCoverage::filterSigners($rows));
            $covered = ProxyCoverage::coverageMap($rows)[0];

            $this->assertSame(['P0', 'P' . ($n - 1)], $signers, "{$n} {$role}: the proxy and the un-ticked party sign");
            $this->assertCount($n - 2, $covered, "{$n} {$role}: everyone in between is represented");
            $this->assertCount($n, array_unique(array_merge($signers, array_map(fn ($i) => "P{$i}", $covered))), 'every party is either a signer or represented — nobody dropped');
        }
    }

    public function test_two_parties_full_proxy_and_the_only_other_party_cannot_be_unticked(): void
    {
        $full = [$this->party('P0', 'seller', ['_is_proxy' => true]), $this->party('P1')];
        $this->assertSame(['P0'], $this->names(ProxyCoverage::filterSigners($full)));

        $none = [$this->party('P0', 'seller', ['_is_proxy' => true, '_proxy_excludes' => ['p1-key']]), $this->party('P1')];
        $this->assertNotNull(ProxyCoverage::violation($none), 'un-ticking the only other party leaves a proxy for nobody');
    }

    public function test_a_proxy_only_affects_its_own_role(): void
    {
        $rows = [
            $this->party('A', 'seller', ['_is_proxy' => true]),
            $this->party('B', 'seller'),
            $this->party('X', 'buyer'),
            $this->party('Y', 'buyer'),
        ];

        $this->assertSame(['A', 'X', 'Y'], $this->names(ProxyCoverage::filterSigners($rows)));
    }

    public function test_two_proxies_in_one_role_each_cover_their_own_people(): void
    {
        $rows = [
            $this->party('A', 'seller', ['_is_proxy' => true, '_proxy_excludes' => ['c-key', 'd-key']]),
            $this->party('B'),
            $this->party('C', 'seller', ['_is_proxy' => true, '_proxy_excludes' => ['a-key', 'b-key']]),
            $this->party('D'),
        ];

        $this->assertSame(['A', 'C'], $this->names(ProxyCoverage::filterSigners($rows)));
        $this->assertSame([0 => [1], 2 => [3]], ProxyCoverage::coverageMap($rows));
    }

    public function test_a_party_with_no_identity_is_never_dropped_by_a_partial_proxy(): void
    {
        $rows = [
            $this->party('A', 'seller', ['_is_proxy' => true, '_proxy_excludes' => ['c-key']]),
            $this->party('B'),
            ['role' => 'seller', 'name' => 'NoKey'],
        ];

        $this->assertSame(['A', 'NoKey'], $this->names(ProxyCoverage::filterSigners($rows)));
    }

    public function test_wording_names_the_represented_party_and_the_proxy_and_leaves_the_others_alone(): void
    {
        $rows = [
            $this->party('Anne Adams', 'seller', ['_is_proxy' => true, '_proxy_excludes' => ['carl-key'], '_recipient_local_key' => 'anne-key']),
            $this->party('Ben Brown', 'seller', ['_recipient_local_key' => 'ben-key']),
            $this->party('Carl Clark', 'seller', ['_recipient_local_key' => 'carl-key']),
        ];

        $out = ProxyCoverage::withRepresentation($rows);

        $this->assertStringContainsString('Ben Brown (ID: ', $out[1]['_party_clause_text']);
        $this->assertStringContainsString('herein represented by Anne Adams (ID: ', $out[1]['_party_clause_text']);
        $this->assertStringEndsWith('in terms of a proxy', $out[1]['_party_clause_text']);
        $this->assertArrayNotHasKey('_party_clause_text', $out[0], 'the proxy keeps their own plain name');
        $this->assertArrayNotHasKey('_party_clause_text', $out[2], 'C signs for themself — no representation wording');
        $this->assertSame('For self and as proxy for Ben Brown', $out[0]['_signature_caption']);
    }

    public function test_full_proxy_wording_lists_every_other_party(): void
    {
        $rows = [$this->party('A', 'seller', ['_is_proxy' => true]), $this->party('B'), $this->party('C')];

        $out = ProxyCoverage::withRepresentation($rows);

        $this->assertStringContainsString('herein represented by A', $out[1]['_party_clause_text']);
        $this->assertStringContainsString('herein represented by A', $out[2]['_party_clause_text']);
        $this->assertSame('For self and as proxy for B and C', $out[0]['_signature_caption']);
    }

    public function test_a_companys_signers_are_covered_by_a_full_proxy_as_before_but_keep_signing_under_a_partial_one(): void
    {
        $company = $this->party('CoRep', 'seller', ['_entity_contact_id' => 99]);

        $full = [$this->party('A', 'seller', ['_is_proxy' => true]), $this->party('B'), $company];
        $this->assertSame(['A'], $this->names(ProxyCoverage::filterSigners($full)), 'full proxy = the old rule, company included');

        $partial = [$this->party('A', 'seller', ['_is_proxy' => true, '_proxy_excludes' => ['b-key']]), $this->party('B'), $company];
        $this->assertSame(['A', 'B', 'CoRep'], $this->names(ProxyCoverage::filterSigners($partial)), 'company reps are never dropped by a partial proxy');
    }

    public function test_wording_order_puts_the_proxy_first_then_who_they_sign_for_then_everyone_else(): void
    {
        $paula = $this->party('Paula Partner', 'seller', ['_recipient_local_key' => 'paula']);
        $sam = $this->party('Sam Separate', 'seller', ['_recipient_local_key' => 'sam']);
        $peter = $this->party('Peter Proxy', 'seller', ['_recipient_local_key' => 'peter', '_is_proxy' => true, '_proxy_excludes' => ['sam']]);

        $this->assertSame(['Peter Proxy', 'Paula Partner', 'Sam Separate'], $this->names(ProxyCoverage::orderForWording([$paula, $sam, $peter])));
        // already in a sensible order → unchanged
        $this->assertSame(['Peter Proxy', 'Paula Partner', 'Sam Separate'], $this->names(ProxyCoverage::orderForWording([$peter, $paula, $sam])));
        // the group sits where its earliest member stood; another role is untouched
        $buyer = $this->party('Bea Buyer', 'buyer');
        $this->assertSame(['Bea Buyer', 'Peter Proxy', 'Paula Partner', 'Sam Separate'], $this->names(ProxyCoverage::orderForWording([$buyer, $paula, $sam, $peter])));
    }

    public function test_wording_order_is_untouched_without_a_proxy_and_with_two_proxies_keeps_each_group_together(): void
    {
        $rows = [$this->party('A'), $this->party('B'), $this->party('C')];
        $this->assertSame(['A', 'B', 'C'], $this->names(ProxyCoverage::orderForWording($rows)));

        $two = [
            $this->party('B'),
            $this->party('D'),
            $this->party('A', 'seller', ['_is_proxy' => true, '_proxy_excludes' => ['c-key', 'd-key']]),
            $this->party('C', 'seller', ['_is_proxy' => true, '_proxy_excludes' => ['a-key', 'b-key']]),
        ];
        $this->assertSame(['A', 'B', 'C', 'D'], $this->names(ProxyCoverage::orderForWording($two)));
    }

    public function test_proxy_index_names_who_covers_a_row(): void
    {
        $rows = [$this->party('A', 'seller', ['_is_proxy' => true, '_proxy_excludes' => ['c-key']]), $this->party('B'), $this->party('C')];
        $this->assertSame(0, ProxyCoverage::proxyIndexFor($rows, 1));
        $this->assertNull(ProxyCoverage::proxyIndexFor($rows, 2));
        $this->assertNull(ProxyCoverage::proxyIndexFor($rows, 0));
    }

    public function test_a_saved_party_list_in_an_older_order_gives_way_to_the_fresh_one_but_a_real_edit_is_kept(): void
    {
        $old = 'Paula Partner (ID: 1), herein represented by Peter Proxy (ID: 3) in terms of a proxy; Sam Separate (ID: 2); and Peter Proxy (ID: 3)';
        $fresh = 'Peter Proxy (ID: 3); Paula Partner (ID: 1), herein represented by Peter Proxy (ID: 3) in terms of a proxy; and Sam Separate (ID: 2)';

        $this->assertSame($fresh, ProxyCoverage::preferFreshOrder($old, $fresh), 'same parties, older order → the fresh order');
        $this->assertSame($fresh, ProxyCoverage::preferFreshOrder($fresh, $fresh));
        $edited = str_replace('Sam Separate (ID: 2)', 'Samuel Separate (ID: 2)', $old);
        $this->assertSame($edited, ProxyCoverage::preferFreshOrder($edited, $fresh), 'a genuine edit is kept as typed');
        $this->assertSame($old, ProxyCoverage::preferFreshOrder($old, null));
        $this->assertSame($old, ProxyCoverage::preferFreshOrder($old, ''));
    }

    public function test_an_existing_clause_is_never_overwritten(): void
    {
        $rows = [
            $this->party('A', 'seller', ['_is_proxy' => true]),
            $this->party('B', 'seller', ['_party_clause_text' => 'Late Estate of B herein represented by X']),
        ];

        $out = ProxyCoverage::withRepresentation($rows);

        $this->assertSame('Late Estate of B herein represented by X', $out[1]['_party_clause_text']);
    }
}
