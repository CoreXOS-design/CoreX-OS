<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Compliance\RiskTierResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class EmployeeRiskTierSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasColumn('users', 'risk_tier')) {
            $this->command->warn('risk_tier column not found on users table. Run migrations first.');
            return;
        }

        $resolver = app(RiskTierResolver::class);

        $users = User::withoutGlobalScopes()->whereNull('deleted_at')->get();
        $counts = ['high' => 0, 'medium' => 0, 'low' => 0];
        $updated = 0;

        foreach ($users as $user) {
            $tier = $resolver->resolve($user);
            $changes = ['risk_tier' => $tier];

            if (empty($user->screening_status) || $user->screening_status === 'never_screened') {
                $changes['screening_status'] = 'never_screened';
            }

            $user->update($changes);
            $counts[$tier]++;
            $updated++;
        }

        $this->command->info("Updated {$updated} users.");
        $this->command->info("  High: {$counts['high']}");
        $this->command->info("  Medium: {$counts['medium']}");
        $this->command->info("  Low: {$counts['low']}");
    }
}
