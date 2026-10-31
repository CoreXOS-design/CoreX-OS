<?php

namespace App\Services\TrainingVideos\Auto;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\RoleProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * AT-525 (spec section 9.8) - the ONE agency the recorder may sign in to: "CoreX Training Academy", flagged agencies.is_training, with INVENTED people, properties
 * and a booked viewing, so a recording never shows a real client (POPIA). Idempotent; reset() removes only this agency's rows and seeds again, which is how it
 * stays fresh. Nothing here sends mail (every address is on the .invalid domain).
 */
class TrainingAgencySeeder
{
    public const SLUG = 'corex-training-academy';

    /** @return array{agency: Agency, users: array<string,User>, created: bool} */
    public function seed(): array
    {
        $created = false;
        $agency = Agency::withoutGlobalScopes()->where('slug', self::SLUG)->first();
        if (! $agency) {
            $created = true;
            $agency = DB::transaction(function () {
                $a = Agency::create([
                    'name' => 'CoreX Training Academy', 'slug' => self::SLUG, 'is_active' => true, 'is_demo' => false,
                    'sidebar_color' => '#0ea5e9', 'icon_color' => '#0ea5e9', 'default_color' => '#0b2a4a', 'button_color' => '#0ea5e9',
                    'agency_type' => \App\Services\Features\AgencyTypeFeatureSeeder::normalise('sales'),
                ]);
                $a->forceFill(['is_training' => true])->save();   // not mass-assignable on purpose: no form can ever make an agency a training agency
                app(\App\Services\Features\AgencyTypeFeatureSeeder::class)->seedNewAgency($a);
                $branch = Branch::withoutAgencyStamping(fn () => Branch::create(['name' => 'Training Branch', 'code' => 'TRN', 'agency_id' => $a->id]));
                $a->update(['default_branch_id' => $branch->id]);

                return $a;
            });
        }
        RoleProvisioningService::provisionForAgency($agency);
        $branch = Branch::withoutGlobalScopes()->where('agency_id', $agency->id)->orderBy('id')->first();

        $users = [];
        foreach (['agent' => 'Alex Agent', 'manager' => 'Morgan Manager', 'admin' => 'Sam Admin'] as $key => $name) {
            $email = "training.{$key}@" . TrainingSessionService::EMAIL_DOMAIN;
            $users[$key] = User::withoutAgencyStamping(fn () => User::withoutGlobalScopes()->firstOrCreate(['email' => $email], [
                'name' => $name, 'password' => Hash::make(Str::random(40)), 'role' => $key === 'manager' ? 'branch_manager' : ($key === 'admin' ? 'admin' : 'agent'),
                'agency_id' => $agency->id, 'branch_id' => $branch?->id, 'is_active' => true, 'email_verified_at' => now(),
            ]));
        }

        $this->seedDemoData($agency, $branch, $users['agent']);

        return ['agency' => $agency, 'users' => $users, 'created' => $created];
    }

    /** Remove this agency's demo rows (never the agency or its users) and seed them again. */
    public function reset(): array
    {
        $agency = Agency::withoutGlobalScopes()->where('slug', self::SLUG)->where('is_training', true)->first();
        if ($agency) {
            DB::table('calendar_events')->where('agency_id', $agency->id)->delete();
            Property::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agency->id)->forceDelete();
            Contact::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agency->id)->forceDelete();
        }

        return $this->seed();
    }

    private function seedDemoData(Agency $agency, ?Branch $branch, User $agent): void
    {
        if (Property::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agency->id)->exists()) {
            return;
        }
        $people = [['Bianca', 'Buyer', 'buyer'], ['Sipho', 'Seller', 'seller'], ['Priya', 'Prospect', 'buyer'], ['Johan', 'Owner', 'seller']];
        $contacts = [];
        foreach ($people as $i => [$first, $last, $role]) {
            $contacts[] = Contact::withoutGlobalScope(AgencyScope::class)->create([
                'agency_id' => $agency->id, 'branch_id' => $branch?->id, 'first_name' => $first, 'last_name' => $last,
                'email' => strtolower($first) . '.' . strtolower($last) . '@' . TrainingSessionService::EMAIL_DOMAIN, 'phone' => '082 555 01' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
        }
        $suburbs = ['Uvongo', 'Margate', 'Ramsgate', 'Shelly Beach', 'Southbroom', 'Port Edward'];
        $properties = [];
        foreach ($suburbs as $i => $suburb) {
            $properties[] = Property::withoutGlobalScope(AgencyScope::class)->create([
                'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch?->id, 'external_id' => (string) Str::uuid(),
                'title' => 'Demo ' . ($i % 2 ? 'townhouse' : 'family home') . ' in ' . $suburb, 'suburb' => $suburb, 'property_type' => $i % 2 ? 'townhouse' : 'house',
                'listing_type' => 'sale', 'status' => 'active', 'price' => 1450000 + $i * 180000, 'bedrooms' => 2 + ($i % 3), 'bathrooms' => 1 + ($i % 2),
            ]);
        }
        // a booked viewing in the diary - the screen the first how-to video walks through
        DB::table('calendar_events')->insert([
            'user_id' => $agent->id, 'created_by_id' => $agent->id, 'agency_id' => $agency->id, 'branch_id' => $branch?->id, 'event_type' => 'viewing',
            'title' => 'Viewing - ' . $properties[0]->title, 'event_date' => now()->addDay()->setTime(10, 0), 'end_date' => now()->addDay()->setTime(10, 45),
            'property_id' => $properties[0]->id, 'contact_id' => $contacts[0]->id, 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
