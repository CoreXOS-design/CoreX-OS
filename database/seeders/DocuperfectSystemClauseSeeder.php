<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Docuperfect\Clause;
use Illuminate\Database\Seeder;

/**
 * ES-9 residue (gap 2) — system-default clause library.
 *
 * Seeds ~20 common South-African real-estate clauses as CoreX defaults
 * (`is_system = true`, `is_global = true`, `owner_id = null`), categorised so
 * the picker UIs can group them. Variable bits use bracket tokens (e.g.
 * "[X] days", "[DATE]") rather than hardcoded numbers — the agent fills them
 * in when inserting the clause.
 *
 * IDEMPOTENT + soft-delete-aware: matches on the stable natural key
 * (name + is_system) INCLUDING trashed rows, so a re-run updates the text /
 * category in place and never duplicates. An admin-archived system clause is
 * left archived (deleted_at preserved) — re-seeding does not silently
 * un-archive an intentional removal.
 */
class DocuperfectSystemClauseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->clauses() as $row) {
            $name = $row['name'];

            $clause = Clause::withTrashed()
                ->where('name', $name)
                ->where('is_system', true)
                ->first();

            $payload = [
                'text'      => $row['text'],
                'category'  => $row['category'],
                'is_global' => true,
                'is_system' => true,
                'owner_id'  => null,
            ];

            if ($clause) {
                // Update content/category in place; preserve archive state.
                $clause->fill($payload)->save();
            } else {
                Clause::create(['name' => $name] + $payload);
            }
        }
    }

    /**
     * @return list<array{name: string, category: string, text: string}>
     */
    private function clauses(): array
    {
        return [
            // ── BOND / FINANCE ──────────────────────────────────────────
            [
                'name' => 'Subject to bond approval',
                'category' => 'bond',
                'text' => 'This sale is subject to the Purchaser obtaining approval of a mortgage bond in the amount of R[AMOUNT] from a registered financial institution within [X] days of acceptance of this offer. Should the bond not be granted within this period, this agreement shall lapse and be of no further force or effect, and any deposit paid shall be refunded to the Purchaser without interest or deduction.',
            ],
            [
                'name' => 'Bond grant on reduced amount',
                'category' => 'bond',
                'text' => 'Should the Purchaser be granted a bond for a lesser amount than applied for, the Purchaser shall be deemed to have fulfilled this condition provided the Purchaser confirms in writing within [X] days that the shortfall will be paid in cash on registration of transfer.',
            ],
            [
                'name' => 'Cash purchase — proof of funds',
                'category' => 'bond',
                'text' => 'This is a cash sale not subject to bond finance. The Purchaser shall furnish the conveyancer with satisfactory proof of funds within [X] days of acceptance of this offer.',
            ],
            // ── OCCUPATION ──────────────────────────────────────────────
            [
                'name' => 'Occupation date',
                'category' => 'occupation',
                'text' => 'Occupation and possession of the property shall be given to the Purchaser on [DATE], from which date all risk and benefit in the property shall pass to the Purchaser.',
            ],
            [
                'name' => 'Occupational rental',
                'category' => 'occupation',
                'text' => 'Should the Purchaser take occupation before registration of transfer, the Purchaser shall pay occupational rental of R[AMOUNT] per month, pro-rated for any part of a month, payable in advance. Should the Seller remain in occupation after registration, the Seller shall pay the same occupational rental to the Purchaser.',
            ],
            // ── FITTINGS & VOETSTOOTS ──────────────────────────────────
            [
                'name' => 'Voetstoots',
                'category' => 'fittings',
                'text' => 'The property is sold voetstoots (as it stands), and the Seller shall not be liable for any patent or latent defects in the property, save where the Seller has fraudulently concealed a defect of which the Seller was aware. This clause does not limit any right the Purchaser may have where the Seller is a supplier acting in the ordinary course of business as contemplated in the Consumer Protection Act 68 of 2008.',
            ],
            [
                'name' => 'Fixtures and fittings included',
                'category' => 'fittings',
                'text' => 'The sale includes all fixtures and fittings of a permanent nature, including but not limited to fitted carpets, blinds, curtain rails, light fittings, fitted stove/oven, and any pool equipment, except the following items expressly excluded: [LIST].',
            ],
            [
                'name' => 'Swimming pool and equipment',
                'category' => 'fittings',
                'text' => 'The swimming pool, pump, filter and associated equipment are included in the sale and shall be handed over in good working order on the date of occupation.',
            ],
            // ── COMPLIANCE CERTIFICATES ────────────────────────────────
            [
                'name' => 'Electrical compliance certificate',
                'category' => 'compliance',
                'text' => 'The Seller shall, at the Seller\'s cost, provide the Purchaser with a valid Electrical Certificate of Compliance in respect of the property as required by the Electrical Installation Regulations, prior to registration of transfer.',
            ],
            [
                'name' => 'Electric fence certificate',
                'category' => 'compliance',
                'text' => 'Where the property is fitted with an electric fence, the Seller shall provide a valid Electric Fence System Certificate of Compliance, at the Seller\'s cost, prior to registration of transfer.',
            ],
            [
                'name' => 'Gas compliance certificate',
                'category' => 'compliance',
                'text' => 'Where the property contains a gas installation, the Seller shall provide a valid Certificate of Conformity for the gas installation, at the Seller\'s cost, prior to registration of transfer.',
            ],
            [
                'name' => 'Plumbing / water compliance (where required)',
                'category' => 'compliance',
                'text' => 'Where required by the relevant municipal by-laws, the Seller shall provide a valid water/plumbing certificate of compliance confirming that the plumbing installation complies with the applicable by-laws, at the Seller\'s cost, prior to registration of transfer.',
            ],
            [
                'name' => 'Beetle / entomologist certificate (coastal)',
                'category' => 'compliance',
                'text' => 'The Seller shall, at the Seller\'s cost, provide a valid entomologist\'s (beetle) certificate confirming that the accessible timber of the property is free from infestation by wood-destroying beetles, prior to registration of transfer.',
            ],
            // ── FEES & COMMISSION ──────────────────────────────────────
            [
                'name' => 'Agent\'s commission',
                'category' => 'fees',
                'text' => 'The Seller shall pay the Agent a commission of [X]% of the purchase price plus VAT thereon, which commission is earned on acceptance of this offer and is due and payable on registration of transfer. The conveyancer is irrevocably authorised to pay the commission to the Agent from the proceeds of the sale.',
            ],
            [
                'name' => 'Commission shared between agencies',
                'category' => 'fees',
                'text' => 'The commission payable in terms of this agreement shall be shared between [AGENCY A] and [AGENCY B] in the ratio [X]:[Y], each agency warranting that its representatives hold valid Fidelity Fund Certificates.',
            ],
            [
                'name' => 'Conveyancing costs for Purchaser',
                'category' => 'fees',
                'text' => 'The Purchaser shall be liable for all costs of transfer, including transfer duty, conveyancing fees and Deeds Office fees, which shall be paid to the conveyancer on request.',
            ],
            // ── NOTICE & TERMINATION ───────────────────────────────────
            [
                'name' => 'Tenant in occupation — notice',
                'category' => 'notice',
                'text' => 'The Purchaser acknowledges that the property is subject to an existing lease in favour of a tenant until [DATE]. The sale is subject to the rights of the tenant, and occupation shall be given subject to such lease. The Seller shall give the tenant the requisite notice in terms of the lease and applicable law.',
            ],
            [
                'name' => 'Lease termination notice period',
                'category' => 'notice',
                'text' => 'Either party may terminate the lease by giving the other not less than [X] calendar months\' written notice, such notice to expire on the last day of a calendar month, without prejudice to any rights accrued prior to termination.',
            ],
            [
                'name' => 'Cooling-off right (CPA, where applicable)',
                'category' => 'notice',
                'text' => 'Where this transaction falls within the ambit of section 29A of the Alienation of Land Act 68 of 1981, the Purchaser may revoke this offer or terminate this agreement within five (5) days by written notice delivered to the Seller or the Agent, in which event any amounts paid shall be refunded.',
            ],
            // ── GENERAL ────────────────────────────────────────────────
            [
                'name' => 'Subject to sale of Purchaser\'s property',
                'category' => 'general',
                'text' => 'This sale is subject to the Purchaser selling the Purchaser\'s existing property situated at [ADDRESS] within [X] days of acceptance of this offer. The Seller retains the right to continue marketing the property and to require the Purchaser to waive this condition within 72 hours of receipt of a written notice (the "72-hour clause"), failing which this agreement shall lapse.',
            ],
            [
                'name' => 'Subject to satisfactory inspection',
                'category' => 'general',
                'text' => 'This sale is subject to the Purchaser obtaining, at the Purchaser\'s cost, a satisfactory inspection report on the property from a suitably qualified inspector within [X] days of acceptance of this offer.',
            ],
            [
                'name' => 'Deposit payable',
                'category' => 'general',
                'text' => 'The Purchaser shall pay a deposit of R[AMOUNT] to the conveyancer within [X] days of acceptance of this offer, to be held in trust and invested in an interest-bearing account for the benefit of the Purchaser pending registration of transfer.',
            ],
            [
                'name' => 'FICA compliance',
                'category' => 'general',
                'text' => 'Both parties undertake to provide the Agent and the conveyancer with all documentation required in terms of the Financial Intelligence Centre Act 38 of 2001 (FICA) without delay, and acknowledge that transfer cannot proceed until FICA verification is complete.',
            ],
        ];
    }
}
