<?php

namespace App\Services\DealV2;

/**
 * AT-334 — the default composable-pipeline catalogue: the common/base conveyancing
 * spine + the step pack each suspensive condition contributes + the movable Granted
 * marker. Modelled on the existing DealPipelineTemplateProvisioner steps. This is the
 * new-model default; pipeline-setup editing of packs is a later phase.
 *
 * A step def:
 *   key, name, follows(step key|null|__grant__|__last_suspensive__), offset(days),
 *   milestone, suspensive, grant_marker, completion(type), condition(key|null),
 *   anchor(bool — first step, auto-completed from deals.deal_date), pos(order hint).
 */
class Dr2ConditionCatalog
{
    /** Conditions a deal can carry, with their option schema (for the Structure tab). */
    public function conditions(): array
    {
        return [
            'bond' => [
                'label'   => 'Bond',
                'options' => ['deposit' => ['type' => 'bool', 'label' => 'Include a deposit', 'default' => false]],
            ],
            'cash' => [
                'label'   => 'Cash',
                'options' => ['payments' => ['type' => 'int', 'label' => 'How many payments?', 'default' => 1, 'min' => 1, 'max' => 6]],
            ],
            'sale_of_another' => [
                'label'   => 'Subject to sale of another property',
                'options' => [],
            ],
        ];
    }

    /** The common spine present on every deal (excludes the Granted marker). */
    private function baseSteps(): array
    {
        return [
            ['key' => 'otp',           'name' => 'Deal Signed',                'follows' => null,        'offset' => 0,  'milestone' => true,  'completion' => 'date_input',      'anchor' => true, 'pos' => 10],
            ['key' => 'attorneys',     'name' => 'Attorneys Instructed',       'follows' => '__grant__', 'offset' => 3,  'completion' => 'text_input',      'pos' => 40],
            // FICA is a two-step vertical lane (Buyer → Seller) so it reads as ONE lane in
            // the concurrent band rather than two peers.
            ['key' => 'fica_buyer',    'name' => 'FICA Completed (Buyer)',     'follows' => 'attorneys', 'offset' => 7,  'completion' => 'document_upload', 'pos' => 45],
            ['key' => 'fica_seller',   'name' => 'FICA Completed (Seller)',    'follows' => 'fica_buyer','offset' => 3,  'completion' => 'document_upload', 'pos' => 46],
            ['key' => 'elec_coc',      'name' => 'Electrical COC',             'follows' => 'attorneys', 'offset' => 14, 'completion' => 'document_upload', 'pos' => 50],
            ['key' => 'beetle',        'name' => 'Beetle Certificate',         'follows' => 'attorneys', 'offset' => 14, 'completion' => 'document_upload', 'pos' => 51],
            ['key' => 'rates',         'name' => 'Rates Clearance',            'follows' => 'attorneys', 'offset' => 21, 'completion' => 'document_upload', 'pos' => 55],
            ['key' => 'docs_signed',   'name' => 'Documents Signed',           'follows' => 'attorneys', 'offset' => 5,  'completion' => 'document_signed', 'pos' => 60],
            ['key' => 'transfer_duty', 'name' => 'Transfer Duty / SARS Receipt','follows' => 'docs_signed','offset' => 7, 'completion' => 'document_upload', 'pos' => 65],
            // FAN-IN: Deeds Office Lodgement cannot start until EVERY concurrent Stage-2 lane
            // tail is in — the honest convergence. Primary follows = Rates; the rest are
            // AND-gate dependencies (written to deal_step_instance_dependencies).
            ['key' => 'lodgement',     'name' => 'Deeds Office Lodgement',      'follows' => 'rates',     'deps' => ['fica_seller', 'elec_coc', 'beetle', 'transfer_duty'], 'offset' => 5,  'milestone' => true,  'completion' => 'date_input',     'pos' => 70],
            ['key' => 'registration',  'name' => 'Registration / Transfer',    'follows' => 'lodgement', 'offset' => 10, 'milestone' => true,  'completion' => 'date_input',     'status_trigger' => 'completed', 'pos' => 80],
        ];
    }

    /** The step pack for one condition, expanded for its options. */
    private function conditionSteps(string $key, array $opts): array
    {
        switch ($key) {
            case 'bond':
                $steps = [
                    ['key' => 'bond_app',      'name' => 'Bond Application Submitted', 'follows' => 'otp',      'offset' => 3,  'completion' => 'date_input', 'condition' => 'bond', 'pos' => 20],
                    ['key' => 'bond_approved', 'name' => 'Bond Approved',              'follows' => 'bond_app', 'offset' => 21, 'milestone' => true, 'suspensive' => true, 'completion' => 'date_input', 'condition' => 'bond', 'pos' => 21],
                    ['key' => 'guarantees',    'name' => 'Guarantees Issued',          'follows' => 'bond_approved', 'offset' => 10, 'completion' => 'text_input', 'condition' => 'bond', 'pos' => 48],
                ];
                if (! empty($opts['deposit'])) {
                    $steps[] = ['key' => 'deposit', 'name' => 'Deposit Paid', 'follows' => 'otp', 'offset' => 3, 'completion' => 'amount_input', 'condition' => 'bond', 'pos' => 19];
                }
                return $steps;

            case 'cash':
                $n = max(1, (int) ($opts['payments'] ?? 1));
                $steps = [
                    ['key' => 'proof_funds', 'name' => 'Proof of Funds', 'follows' => 'otp', 'offset' => 3, 'milestone' => true, 'suspensive' => true, 'completion' => 'amount_input', 'condition' => 'cash', 'pos' => 22],
                ];
                for ($i = 1; $i <= $n; $i++) {
                    // Payments can sit LATE (default follows Deeds Office Lodgement); repointable per deal.
                    $steps[] = ['key' => "payment_{$i}", 'name' => $n > 1 ? "Payment Received ({$i} of {$n})" : 'Payment Received', 'follows' => 'lodgement', 'offset' => 0, 'completion' => 'amount_input', 'condition' => 'cash', 'pos' => 90 + $i];
                }
                return $steps;

            case 'sale_of_another':
                return [
                    ['key' => 'linked_sold', 'name' => 'Linked Property Sold', 'follows' => 'otp', 'offset' => 0, 'milestone' => true, 'suspensive' => true, 'completion' => 'date_input', 'condition' => 'sale_of_another', 'pos' => 23],
                ];
        }
        return [];
    }

    /**
     * Resolve the full ordered step set for a deal's condition selections.
     * $selections = ['bond'=>['deposit'=>true], 'cash'=>['payments'=>2], 'sale_of_another'=>[]]
     * Returns steps with follows-keys resolved and the Granted marker placed after the
     * last active suspensive step (its default position — movable per deal in Phase 5).
     */
    public function resolve(array $selections): array
    {
        $steps = $this->baseSteps();
        $suspensiveKeys = [];

        foreach ($selections as $key => $opts) {
            if (! array_key_exists($key, $this->conditions())) {
                continue;
            }
            foreach ($this->conditionSteps($key, is_array($opts) ? $opts : []) as $s) {
                $steps[] = $s;
                if (! empty($s['suspensive'])) {
                    $suspensiveKeys[] = $s['key'];
                }
            }
        }

        // Granted marker CONVERGES on EVERY active suspensive condition — the deal grants
        // only when all are met. Primary follows = the last suspensive step; the rest are
        // AND-gate dependencies. If a deal has no suspensive condition, it follows OTP
        // (grants on signing).
        $grantFollows = end($suspensiveKeys) ?: 'otp';
        $grantDeps    = array_values(array_diff($suspensiveKeys, [$grantFollows]));
        $steps[] = ['key' => 'granted', 'name' => 'Granted', 'follows' => $grantFollows, 'deps' => $grantDeps, 'offset' => 0, 'milestone' => true, 'grant_marker' => true, 'completion' => 'manual_tick', 'pos' => 30];

        // Resolve the __grant__ sentinel (base steps that start after grant → follow the marker).
        foreach ($steps as &$s) {
            if (($s['follows'] ?? null) === '__grant__') {
                $s['follows'] = 'granted';
            }
        }
        unset($s);

        usort($steps, fn ($a, $b) => ($a['pos'] ?? 999) <=> ($b['pos'] ?? 999));
        return $steps;
    }
}
