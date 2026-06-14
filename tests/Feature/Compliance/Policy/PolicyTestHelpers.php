<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Policy;

use App\Models\Compliance\AgencyPolicy;
use App\Models\Compliance\PolicyAcknowledgement;
use App\Models\Compliance\PolicySection;
use App\Models\Compliance\PolicySectionAcknowledgement;
use App\Models\Compliance\PolicyVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared fixtures for the AT-29 policy-acknowledgement suite.
 *
 * Every row is created with an explicit agency_id and an acting user that
 * carries an agency context — deliberately NOT reproducing the AT-31 tenancy
 * fixture gap (presentations.* tests omit agency_id and 500 on insert).
 */
trait PolicyTestHelpers
{
    protected function seedAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Test ' . Str::random(6),
            'slug'       => 'test-' . Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('branches')->insert([
            'id'         => $agencyId,
            'agency_id'  => $agencyId,
            'name'       => 'Default',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $agencyId;
    }

    protected function makeUser(int $agencyId, string $role = 'admin'): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => $role,
            'is_active' => true,
        ]);
    }

    protected function makePolicy(int $agencyId, string $key, string $name = 'Test Policy'): AgencyPolicy
    {
        return AgencyPolicy::create([
            'agency_id'  => $agencyId,
            'policy_key' => $key,
            'name'       => $name,
            'is_active'  => true,
        ]);
    }

    /**
     * Create a version with N required sections + one acknowledgement section.
     */
    protected function makeVersion(AgencyPolicy $policy, int $number, string $status, int $requiredSections = 1): PolicyVersion
    {
        $version = PolicyVersion::create([
            'agency_id'      => $policy->agency_id,
            'policy_id'      => $policy->id,
            'version_number' => $number,
            'title'          => $policy->name,
            'status'         => $status,
        ]);

        for ($i = 1; $i <= $requiredSections; $i++) {
            PolicySection::create([
                'agency_id'                => $policy->agency_id,
                'policy_version_id'        => $version->id,
                'section_type'             => 'section',
                'display_order'            => $i,
                'section_number'           => (string) $i,
                'title'                    => "Section {$i}",
                'body_html'                => "<p>Body {$i}</p>",
                'requires_acknowledgement' => true,
                'acknowledgement_prompt'   => 'I have read this section',
            ]);
        }

        PolicySection::create([
            'agency_id'                => $policy->agency_id,
            'policy_version_id'        => $version->id,
            'section_type'             => 'acknowledgement',
            'display_order'            => 99,
            'section_number'           => 'A',
            'title'                    => 'Acknowledgement',
            'body_html'                => '<p>I confirm I have read this policy in full.</p>',
            'requires_acknowledgement' => false,
        ]);

        return $version;
    }

    /**
     * An in_progress acknowledgement with every required section confirmed
     * (but NOT yet signed/completed).
     */
    protected function confirmedAck(User $user, PolicyVersion $version): PolicyAcknowledgement
    {
        $required = $version->sections()->where('requires_acknowledgement', true)->get();

        $ack = PolicyAcknowledgement::create([
            'agency_id'            => $version->agency_id,
            'policy_id'            => $version->policy_id,
            'policy_version_id'    => $version->id,
            'user_id'              => $user->id,
            'status'              => 'in_progress',
            'sections_total_count' => $required->count(),
        ]);

        foreach ($required as $section) {
            PolicySectionAcknowledgement::create([
                'agency_id'                 => $version->agency_id,
                'policy_acknowledgement_id' => $ack->id,
                'policy_section_id'         => $section->id,
                'acknowledged'             => true,
                'acknowledged_at'          => now(),
                'acknowledgement_response' => 'yes',
            ]);
        }

        $ack->update(['sections_acknowledged_count' => $required->count()]);

        return $ack->fresh();
    }

    /**
     * A fully completed (signed) acknowledgement — valid for one year.
     */
    protected function completeAck(User $user, PolicyVersion $version): PolicyAcknowledgement
    {
        $ack = $this->confirmedAck($user, $version);
        $ack->complete('typed:' . $user->name, 'typed', '127.0.0.1', 'phpunit', $user->name);

        return $ack->fresh();
    }
}
