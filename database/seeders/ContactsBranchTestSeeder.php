<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeds 30 realistic test contacts across HFC's 3 branches.
 * Idempotent: uses email pattern 'test-seed-m3-{N}@example.com' to detect existing rows.
 */
class ContactsBranchTestSeeder extends Seeder
{
    public function run(): void
    {
        $agencyId = 1; // HFC

        // Agents per branch
        $branch1Agents = User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('branch_id', 1)->where('role', 'agent')->pluck('id')->toArray();
        $branch2Agents = User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('branch_id', 2)->where('role', 'agent')->pluck('id')->toArray();
        $branch3Agents = User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('branch_id', 3)->where('role', 'agent')->pluck('id')->toArray();

        $contacts = [
            // Branch 1 (Shelly Beach) — 10 contacts
            ['first_name' => 'Sipho', 'last_name' => 'Mthembu', 'phone' => '0821001001', 'branch_id' => 1, 'agents' => $branch1Agents],
            ['first_name' => 'Anri', 'last_name' => 'van der Merwe', 'phone' => '0831001002', 'branch_id' => 1, 'agents' => $branch1Agents],
            ['first_name' => 'Thandi', 'last_name' => 'Zulu', 'phone' => '0841001003', 'branch_id' => 1, 'agents' => $branch1Agents],
            ['first_name' => 'Pieter', 'last_name' => 'Botha', 'phone' => '0821001004', 'branch_id' => 1, 'agents' => $branch1Agents],
            ['first_name' => 'Nompumelelo', 'last_name' => 'Dlamini', 'phone' => '0831001005', 'branch_id' => 1, 'agents' => $branch1Agents],
            ['first_name' => 'Johan', 'last_name' => 'Pretorius', 'phone' => '0841001006', 'branch_id' => 1, 'agents' => $branch1Agents],
            ['first_name' => 'Zanele', 'last_name' => 'Khumalo', 'phone' => '0821001007', 'branch_id' => 1, 'agents' => $branch1Agents],
            ['first_name' => 'Wilhelm', 'last_name' => 'Erasmus', 'phone' => '0831001008', 'branch_id' => 1, 'agents' => $branch1Agents],
            ['first_name' => 'Nomvula', 'last_name' => 'Nkosi', 'phone' => '0841001009', 'branch_id' => 1, 'agents' => $branch1Agents],
            ['first_name' => 'Christiaan', 'last_name' => 'Viljoen', 'phone' => '0821001010', 'branch_id' => 1, 'agents' => $branch1Agents],

            // Branch 2 (Ballito) — 10 contacts
            ['first_name' => 'Rajan', 'last_name' => 'Naidoo', 'phone' => '0822001001', 'branch_id' => 2, 'agents' => $branch2Agents],
            ['first_name' => 'Lerato', 'last_name' => 'Moloi', 'phone' => '0832001002', 'branch_id' => 2, 'agents' => $branch2Agents],
            ['first_name' => 'Francois', 'last_name' => 'du Plessis', 'phone' => '0842001003', 'branch_id' => 2, 'agents' => $branch2Agents],
            ['first_name' => 'Ayesha', 'last_name' => 'Moosa', 'phone' => '0822001004', 'branch_id' => 2, 'agents' => $branch2Agents],
            ['first_name' => 'Bongani', 'last_name' => 'Sithole', 'phone' => '0832001005', 'branch_id' => 2, 'agents' => $branch2Agents],
            ['first_name' => 'Marthinus', 'last_name' => 'Swanepoel', 'phone' => '0842001006', 'branch_id' => 2, 'agents' => $branch2Agents],
            ['first_name' => 'Priya', 'last_name' => 'Govender', 'phone' => '0822001007', 'branch_id' => 2, 'agents' => $branch2Agents],
            ['first_name' => 'Sifiso', 'last_name' => 'Mkhize', 'phone' => '0832001008', 'branch_id' => 2, 'agents' => $branch2Agents],
            ['first_name' => 'Elana', 'last_name' => 'Brink', 'phone' => '0842001009', 'branch_id' => 2, 'agents' => $branch2Agents],
            ['first_name' => 'Mandla', 'last_name' => 'Ngcobo', 'phone' => '0822001010', 'branch_id' => 2, 'agents' => $branch2Agents],

            // Branch 3 (Southbroom) — 10 contacts
            ['first_name' => 'Susan', 'last_name' => 'Fourie', 'phone' => '0823001001', 'branch_id' => 3, 'agents' => $branch3Agents],
            ['first_name' => 'Themba', 'last_name' => 'Ndlovu', 'phone' => '0833001002', 'branch_id' => 3, 'agents' => $branch3Agents],
            ['first_name' => 'Gerhard', 'last_name' => 'Coetzee', 'phone' => '0843001003', 'branch_id' => 3, 'agents' => $branch3Agents],
            ['first_name' => 'Lindiwe', 'last_name' => 'Cele', 'phone' => '0823001004', 'branch_id' => 3, 'agents' => $branch3Agents],
            ['first_name' => 'Hennie', 'last_name' => 'Potgieter', 'phone' => '0833001005', 'branch_id' => 3, 'agents' => $branch3Agents],
            ['first_name' => 'Nokuthula', 'last_name' => 'Shabalala', 'phone' => '0843001006', 'branch_id' => 3, 'agents' => $branch3Agents],
            ['first_name' => 'André', 'last_name' => 'Marais', 'phone' => '0823001007', 'branch_id' => 3, 'agents' => $branch3Agents],
            ['first_name' => 'Phumzile', 'last_name' => 'Buthelezi', 'phone' => '0833001008', 'branch_id' => 3, 'agents' => $branch3Agents],
            ['first_name' => 'Jannie', 'last_name' => 'Steyn', 'phone' => '0843001009', 'branch_id' => 3, 'agents' => $branch3Agents],
            ['first_name' => 'Noluthando', 'last_name' => 'Majola', 'phone' => '0823001010', 'branch_id' => 3, 'agents' => $branch3Agents],
        ];

        foreach ($contacts as $i => $data) {
            $email = 'test-seed-m3-' . ($i + 1) . '@example.com';

            // Idempotent: skip if already exists
            if (Contact::withoutGlobalScopes()->where('email', $email)->exists()) {
                continue;
            }

            $agents = $data['agents'];
            $creatorId = !empty($agents) ? $agents[array_rand($agents)] : 22;

            // Spread creation dates over past 6 months
            $createdAt = Carbon::now()->subDays(rand(1, 180));

            Contact::withoutGlobalScopes()->create([
                'agency_id' => $agencyId,
                'branch_id' => $data['branch_id'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'],
                'email' => $email,
                'created_by_user_id' => $creatorId,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }
}
