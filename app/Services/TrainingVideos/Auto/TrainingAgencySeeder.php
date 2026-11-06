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
        app(TrainingMicSeeder::class)->seed($agency->fresh(), $users['agent']);   // the Market Intelligence Work tab (9.11); does nothing when already there
        $this->seedFicaRequests($agency, $branch, $users['agent']);
        $this->giveContactsToAgent($agency, $users['agent']);
        $this->seedPastViewing($agency, $branch, $users['agent']);
        // events the first version of this seeder wrote had no category, so the diary could not open them ("Could not load event"); a real diary event is a 'manual' event with a class
        DB::table('calendar_events')->where('agency_id', $agency->id)->whereNull('category')->where('event_type', 'viewing')->update(['event_type' => 'manual', 'category' => 'viewing', 'all_day' => 0]);
        $this->linkEvents($agency, $users['agent']);
        $this->seedDealWithPipeline($agency, $branch, $users['agent']);

        return ['agency' => $agency, 'users' => $users, 'created' => $created];
    }

    /** Remove this agency's demo rows (never the agency or its users) and seed them again. */
    public function reset(): array
    {
        $agency = Agency::withoutGlobalScopes()->where('slug', self::SLUG)->where('is_training', true)->first();
        if ($agency) {
            $this->clearDeals($agency);
            DB::table('calendar_event_links')->where('agency_id', $agency->id)->delete();
            DB::table('calendar_events')->where('agency_id', $agency->id)->delete();
            DB::table('fica_documents')->whereIn('fica_submission_id', DB::table('fica_submissions')->where('agency_id', $agency->id)->pluck('id'))->delete();
            DB::table('fica_submissions')->where('agency_id', $agency->id)->delete();
            app(TrainingMicSeeder::class)->clear($agency);
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
                'agent_id' => $agent->id, 'created_by_user_id' => $agent->id,
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
            'user_id' => $agent->id, 'created_by_id' => $agent->id, 'agency_id' => $agency->id, 'branch_id' => $branch?->id, 'event_type' => 'manual', 'category' => 'viewing', 'all_day' => 0,
            'title' => 'Viewing - ' . $properties[0]->title, 'event_date' => now()->addDay()->setTime(10, 0), 'end_date' => now()->addDay()->setTime(10, 45),
            'property_id' => $properties[0]->id, 'contact_id' => $contacts[0]->id, 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The token of the client's FICA link in the training agency - fixed, so the client-side how-to can open it; it never expires and is on an invented person. */
    public const FICA_LINK_TOKEN = 'bluegum-training-fica-link';
    public const DEAL_NO = 'TRN-0001';   // not numeric, so the register's next-number maths ignores it

    /**
     * Two invented FICA requests, so the FICA how-tos have something real to point at (9.26): Priya's is still waiting for her (the link the client video opens),
     * Bianca's has been sent back and is waiting for the agent to review. Idempotent by token.
     */
    private function seedFicaRequests(Agency $agency, ?Branch $branch, User $agent): void
    {
        $contacts = Contact::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agency->id)->get()->keyBy('first_name');
        $rows = [
            [self::FICA_LINK_TOKEN, 'Priya', 'draft', null],
            ['bluegum-training-fica-review', 'Bianca', 'submitted', [
                'personal' => ['full_name' => 'Bianca Buyer', 'id_number' => '9001015800087', 'sa_citizen' => 'yes', 'residential_address' => '12 Bluegum Road, Margate', 'phone' => '082 555 0100', 'email' => 'bianca.buyer@' . TrainingSessionService::EMAIL_DOMAIN],
            ]],
        ];
        foreach ($rows as [$token, $first, $status, $form]) {
            $contact = $contacts->get($first);
            if (! $contact || \App\Models\FicaSubmission::withoutGlobalScopes()->where('token', $token)->exists()) {
                continue;
            }
            \App\Models\FicaSubmission::withoutGlobalScopes()->create([
                'contact_id' => $contact->id, 'agency_id' => $agency->id, 'branch_id' => $branch?->id, 'requested_by' => $agent->id, 'token' => $token, 'token_expires_at' => null,
                'entity_type' => 'natural', 'intake_type' => 'online', 'status' => $status, 'form_data' => $form,
            ]);
        }
    }

    /** A contact list is own-scoped for an agent, so the invented people must belong to the training agent or the Contacts how-tos would film "No contacts yet". Idempotent. */
    private function giveContactsToAgent(Agency $agency, User $agent): void
    {
        Contact::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agency->id)->whereNull('agent_id')
            ->where('email', 'like', '%@' . TrainingSessionService::EMAIL_DOMAIN)
            ->whereIn('first_name', ['Bianca', 'Sipho', 'Priya', 'Johan'])
            ->update(['agent_id' => $agent->id, 'created_by_user_id' => $agent->id]);
    }

    /** A viewing that already happened (yesterday), so the Diary how-to can show where the buyer's feedback is captured. Idempotent by title. */
    private function seedPastViewing(Agency $agency, ?Branch $branch, User $agent): void
    {
        $title = 'Viewing (done) - Bluegum Road home';
        $property = Property::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agency->id)->orderBy('id')->skip(1)->first();
        $contact = Contact::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agency->id)->where('first_name', 'Priya')->first();
        if (! $property || DB::table('calendar_events')->where('agency_id', $agency->id)->where('title', $title)->exists()) {
            return;
        }
        DB::table('calendar_events')->insert([
            'user_id' => $agent->id, 'created_by_id' => $agent->id, 'agency_id' => $agency->id, 'branch_id' => $branch?->id, 'event_type' => 'manual', 'category' => 'viewing', 'all_day' => 0,
            'title' => $title, 'event_date' => now()->subDay()->setTime(14, 0), 'end_date' => now()->subDay()->setTime(14, 45),
            'property_id' => $property->id, 'contact_id' => $contact?->id, 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The diary shows an event's house and buyer from calendar_event_links (not from the event's own columns), so a seeded viewing needs them or the feedback box says "No properties linked". Idempotent. */
    private function linkEvents(Agency $agency, User $agent): void
    {
        foreach (DB::table('calendar_events')->where('agency_id', $agency->id)->whereNull('deleted_at')->get(['id', 'property_id', 'contact_id']) as $e) {
            foreach ([['App\\Models\\Property', $e->property_id, 'subject_property'], ['App\\Models\\Contact', $e->contact_id, 'buyer_contact']] as [$type, $id, $role]) {
                if (! $id || DB::table('calendar_event_links')->where('calendar_event_id', $e->id)->where('linkable_type', $type)->where('linkable_id', $id)->exists()) {
                    continue;
                }
                DB::table('calendar_event_links')->insert(['calendar_event_id' => $e->id, 'agency_id' => $agency->id, 'linkable_type' => $type, 'linkable_id' => $id, 'role' => $role, 'created_by_user_id' => $agent->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
    /**
     * One invented deal with a running pipeline, so the Deal Register and pipeline how-tos (9.27) have a real deal to open. The agent gets the permission that shows the
     * Supplier Work Orders tab (deals_v2.distribute_documents) and create_deals, which the videos need. Idempotent by deal number; pipeline templates are the agency's own defaults.
     */
    private function seedDealWithPipeline(Agency $agency, ?Branch $branch, User $agent): void
    {
        foreach (['deals_v2.distribute_documents', 'create_deals'] as $key) {
            DB::table('role_permissions')->insertOrIgnore(['role' => 'agent', 'permission_key' => $key, 'scope' => null, 'agency_id' => $agency->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $dealNo = self::DEAL_NO;
        $property = Property::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agency->id)->orderBy('id')->first();
        if (! $property || \App\Models\Deal::withoutGlobalScopes()->where('agency_id', $agency->id)->where('deal_no', $dealNo)->exists()) {
            return;
        }
        if (! \App\Models\DealV2\DealPipelineTemplate::withoutGlobalScopes()->where('agency_id', $agency->id)->exists()) {
            app(\App\Services\DealV2\DealPipelineTemplateProvisioner::class)->provisionDefaultsForAgency($agency->id, $agent->id);
        }
        $template = \App\Models\DealV2\DealPipelineTemplate::withoutGlobalScopes()->where('agency_id', $agency->id)->where('is_default', true)->orderBy('id')->first();

        $deal = \App\Models\Deal::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch?->id, 'deal_no' => $dealNo, 'period' => now()->format('Y-m'), 'deal_date' => now()->subDays(10)->toDateString(),
            'deal_type' => 'bond', 'property_id' => $property->id, 'property_address' => $property->address ?? $property->street_address ?? 'Training house', 'property_value' => 1850000, 'sale_price' => 1850000,
            'total_commission' => 129500, 'seller_name' => 'Sipho Seller', 'buyer_name' => 'Bianca Buyer', 'accepted_status' => 'P',
        ]);
        \App\Models\DealProperty::create(['deal_id' => $deal->id, 'property_id' => $property->id, 'is_primary' => true]);
        $deal->agents()->attach($agent->id, ['side' => 'listing', 'agent_split_percent' => 100, 'agent_cut_percent' => 50, 'paye_method' => 'percentage', 'paye_value' => 0]);
        if ($template) {
            app(\App\Services\Deal\Dr1PipelineService::class)->createPipeline($deal, $template->id, ['from_date' => now()->subDays(10)->toDateString()]);
        }
    }

    /** Remove the invented deal and its pipeline (reset only). */
    private function clearDeals(Agency $agency): void
    {
        $ids = \App\Models\Deal::withoutGlobalScopes()->where('agency_id', $agency->id)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        DB::table('deal_step_instances')->whereIn('dr1_deal_id', $ids)->delete();
        DB::table('deal_user')->whereIn('deal_id', $ids)->delete();
        DB::table('deal_properties')->whereIn('deal_id', $ids)->delete();
        DB::table('deal_logs')->whereIn('deal_id', $ids)->delete();
        \App\Models\Deal::withoutGlobalScopes()->whereIn('id', $ids)->forceDelete();
    }
}
