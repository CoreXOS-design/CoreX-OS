<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals\TakeOnImport\Reos;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactRepresentative;
use App\Models\Lease;
use App\Models\RentalTakeOnImportRun;
use App\Models\User;
use App\Services\Contacts\ContactRepresentativeLinker;
use App\Services\Rentals\TakeOnImport\RentalTakeOnArchiveService;
use App\Services\Rentals\TakeOnImport\Reos\ReosDecisionService;
use App\Services\Rentals\TakeOnImport\Reos\ReosTenancyConfirmService;
use App\Services\Rentals\TakeOnImport\Reos\ReosTenancyRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * AT-479 (Johan, 21 Oct) — the people of a company owner / tenant are chosen on the review screen and linked at import, through the
 * existing company ↔ person relation (`contact_representatives`, Contact::representatives()). Nothing is sent to anyone.
 *
 * Paths: a company with a NEW person / with an EXISTING person / a company that matched a person by phone (one click: create the
 * company, link that person, reuse them) / the same person on two companies in one batch = one contact, two links / people refused on
 * a person / on a bad role / an entity as the "person" / a company already in CoreX lists its linked people / the import's own
 * people are archived with the batch. Synthetic data only ("ZZ").
 */
final class ReosCompanyPeopleTest extends TestCase
{
    use RefreshDatabase;
    use ReosFixtures;

    protected function tearDown(): void
    {
        $this->tearDownReosFiles();
        parent::tearDown();
    }

    public function test_a_company_with_a_new_person_creates_the_person_and_links_them_with_their_role(): void
    {
        Mail::fake();
        Notification::fake();
        [$agency, , $admin] = $this->reosAgency();
        $run = $this->makeRun($admin, [$this->companyRow(self::UUID_A, 'ZZ TEST Wildevy Holdings Pty Ltd')]);
        $svc = app(ReosDecisionService::class);
        $row = $run->rows()->first();

        $row = $svc->apply($row, $run, ['person' => ['party' => 'owner', 'op' => 'add', 'first_name' => 'ZZ', 'last_name' => 'Director One', 'phone' => '+27815550301', 'email' => 'zz.dir1@example.test', 'capacity' => 'Director']]);
        self::assertCount(1, $row->decisions_json['owner']['people']);
        self::assertSame('Director', $row->decisions_json['owner']['people'][0]['capacity']);
        $svc->apply($row, $run, ['suburb_id' => $this->p24Suburb()->id]);   // ready now, so it is a line whose Change controls hold the people
        $html = $this->actingAs($admin)->get(route('corex.rentals.take-on-import.preview', $run))->assertOk()->getContent();
        self::assertStringContainsString('People for this company', $html);
        self::assertStringContainsString('ZZ Director One', $html);

        $this->importReady($run, $admin);

        $company = Contact::withoutGlobalScopes()->where('agency_id', $agency->id)->where('entity_name', 'ZZ TEST Wildevy Holdings Pty Ltd')->firstOrFail();
        $person = Contact::withoutGlobalScopes()->where('agency_id', $agency->id)->where('email', 'zz.dir1@example.test')->firstOrFail();
        self::assertSame(Contact::TYPE_ENTITY, $company->contact_kind);
        self::assertSame(Contact::TYPE_NATURAL_PERSON, $person->contact_kind);
        self::assertSame('Director One', $person->last_name);
        self::assertSame([$person->id], $company->representatives()->pluck('contacts.id')->all(), 'the company↔person link, through the existing relation');
        self::assertSame('Director', $company->representatives()->first()->pivot->capacity);
        self::assertSame([$person->id], $run->rows()->first()->decisions_json['created_people']);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_a_company_with_an_existing_person_links_that_person_and_creates_nobody(): void
    {
        [$agency, $branch, $admin] = $this->reosAgency();
        $existing = $this->person($agency, $branch, 'ZZ', 'Existing Trustee', '0815550777');
        $run = $this->makeRun($admin, [$this->companyRow(self::UUID_A, 'ZZ TEST Family Trust')]);
        $svc = app(ReosDecisionService::class);

        $row = $svc->apply($run->rows()->first(), $run, ['person' => ['party' => 'owner', 'op' => 'add', 'contact_id' => $existing->id, 'capacity' => 'Trustee']]);
        self::assertSame($existing->id, $row->decisions_json['owner']['people'][0]['contact_id']);

        $persons = Contact::withoutGlobalScopes()->where('agency_id', $agency->id)->where('contact_kind', Contact::TYPE_NATURAL_PERSON)->count();
        $this->importReady($run, $admin);

        $company = Contact::withoutGlobalScopes()->where('entity_name', 'ZZ TEST Family Trust')->firstOrFail();
        self::assertSame([$existing->id], $company->representatives()->pluck('contacts.id')->all());
        self::assertSame('Trustee', $company->representatives()->first()->pivot->capacity);
        // The tenant of the row is a person created by the import; the existing person was not duplicated.
        self::assertSame($persons + 1, Contact::withoutGlobalScopes()->where('agency_id', $agency->id)->where('contact_kind', Contact::TYPE_NATURAL_PERSON)->count());
        self::assertSame(1, Contact::withoutGlobalScopes()->where('agency_id', $agency->id)->where('last_name', 'Existing Trustee')->count());
        self::assertSame([], $run->rows()->first()->decisions_json['created_people'] ?? [], 'nobody was created for the company');
    }

    public function test_a_company_that_matched_a_person_can_be_created_with_that_person_linked_as_its_contact_person(): void
    {
        [$agency, $branch, $admin] = $this->reosAgency();
        $arne = $this->person($agency, $branch, 'Arne', 'Nel', '0815550101');
        $run = $this->makeRun($admin, [$this->companyRow(self::UUID_A, 'ZZ TEST Wildevy Properties Pty Ltd', ['Owner Phone' => '+27815550101', 'Owner Email' => ''])]);
        $svc = app(ReosDecisionService::class);
        $row = $run->rows()->first();

        // Default: the matched person is used, with the warning. The one-click is offered.
        self::assertSame('person_for_company', $row->landlord_match_json[1]['name_flag']);
        self::assertSame('link', $row->decisions_json['owner']['action']);
        self::assertSame(['company:owner', 'suburb'], \App\Services\Rentals\TakeOnImport\Reos\ReosQuestions::needs($row));
        $card = $this->lineHtml($admin, $row);
        self::assertStringContainsString('create company “ZZ TEST Wildevy Properties Pty Ltd” with Arne Nel as contact person?', html_entity_decode($card));

        $row = $svc->apply($row, $run, ['person' => ['party' => 'owner', 'op' => 'convert']]);
        self::assertSame('create', $row->decisions_json['owner']['action']);
        self::assertSame($arne->id, $row->decisions_json['owner']['people'][0]['contact_id']);
        self::assertSame('Other', $row->decisions_json['owner']['people'][0]['capacity'], 'CoreX has no "contact person" role; "Other" is its free-form one');
        self::assertFalse($row->decisions_json['name_differs'], 'the warning is settled by the conversion');

        $contactsBefore = Contact::withoutGlobalScopes()->where('agency_id', $agency->id)->where('contact_kind', Contact::TYPE_NATURAL_PERSON)->count();
        $this->importReady($run, $admin);

        $company = Contact::withoutGlobalScopes()->where('agency_id', $agency->id)->where('entity_name', 'ZZ TEST Wildevy Properties Pty Ltd')->firstOrFail();
        self::assertSame(Contact::TYPE_ENTITY, $company->contact_kind, 'the company was created — it was not satisfied by the person sharing its phone');
        $lease = Lease::withoutGlobalScopes()->firstOrFail();
        self::assertSame($company->id, (int) $lease->landlordContacts()->first()->id, 'the lease owner is the company');
        self::assertSame([$arne->id], $company->representatives()->pluck('contacts.id')->all(), 'Arne Nel is its contact person');
        self::assertSame(1, Contact::withoutGlobalScopes()->where('agency_id', $agency->id)->where('last_name', 'Nel')->count(), 'reused, not duplicated');
        self::assertSame('Arne', $arne->fresh()->first_name);
        // Only the row's tenant is a new person.
        self::assertSame($contactsBefore + 1, Contact::withoutGlobalScopes()->where('agency_id', $agency->id)->where('contact_kind', Contact::TYPE_NATURAL_PERSON)->count());
    }

    public function test_the_same_new_person_on_two_companies_in_one_batch_is_one_contact_with_two_links(): void
    {
        [$agency, , $admin] = $this->reosAgency();
        $run = $this->makeRun($admin, [
            $this->companyRow(self::UUID_A, 'ZZ TEST Alpha Holdings Pty Ltd', ['Property Address' => '3 ZZ Alpha Street']),
            $this->companyRow(self::UUID_B, 'ZZ TEST Beta Investments Pty Ltd', ['Property Address' => '9 ZZ Beta Street', 'Tenant' => 'ZZ TEST Tenant Two', 'Tenant Email' => 'zz.t2@example.test', 'Tenant Phone' => '+27635550203']),
        ]);
        $svc = app(ReosDecisionService::class);
        foreach ($run->rows()->orderBy('row_number')->get() as $row) {
            // Row 2 types the name differently and gives no e-mail: the SAME person must still be recognised (same name in this batch).
            $second = $row->row_number > $run->rows()->min('row_number');
            $svc->apply($row, $run, ['person' => ['party' => 'owner', 'op' => 'add', 'first_name' => $second ? 'zz' : 'ZZ', 'last_name' => $second ? 'SHARED director' : 'Shared Director', 'email' => $second ? '' : 'zz.shared@example.test', 'phone' => $second ? '' : '+27815550404', 'capacity' => $second ? 'Member' : 'Director']]);
        }

        $this->importReady($run, $admin);

        $people = Contact::withoutGlobalScopes()->where('agency_id', $agency->id)->where('last_name', 'like', '%hared%irector')->get();
        self::assertCount(1, $people, 'one person, not two');
        $companies = Contact::withoutGlobalScopes()->where('agency_id', $agency->id)->where('contact_kind', Contact::TYPE_ENTITY)->pluck('id')->all();
        self::assertCount(2, $companies);
        self::assertSame(2, ContactRepresentative::query()->where('representative_contact_id', $people[0]->id)->whereIn('entity_contact_id', $companies)->count(), 'two links');
        self::assertEqualsCanonicalizing(['Director', 'Member'], ContactRepresentative::query()->where('representative_contact_id', $people[0]->id)->pluck('capacity')->all());
    }

    public function test_people_are_refused_on_a_person_a_bad_role_a_company_as_the_person_and_an_unknown_op(): void
    {
        [$agency, $branch, $admin] = $this->reosAgency();
        $company = $this->company($agency, $branch, 'ZZ Some Company Pty Ltd');
        $run = $this->makeRun($admin, [$this->companyRow(self::UUID_A, 'ZZ TEST Gamma Holdings Pty Ltd')]);
        $svc = app(ReosDecisionService::class);
        $row = $run->rows()->first();

        $refused = function (array $person, string $contains) use ($svc, $run) {
            try {
                $svc->apply($run->rows()->first(), $run, ['person' => $person]);
                self::fail('should have been refused: ' . json_encode($person));
            } catch (ValidationException $e) {
                self::assertStringContainsString($contains, json_encode($e->errors()));
            }
        };

        $refused(['party' => 'tenant', 'op' => 'add', 'first_name' => 'ZZ'], 'only be added to a company');           // the tenant is a person
        $refused(['party' => 'owner', 'op' => 'add', 'first_name' => 'ZZ', 'capacity' => 'Boss'], 'role');
        $refused(['party' => 'owner', 'op' => 'add', 'contact_id' => $company->id], 'person');                          // a company is not a person
        $refused(['party' => 'owner', 'op' => 'add', 'first_name' => ''], 'name');
        $refused(['party' => 'owner', 'op' => 'add', 'first_name' => 'ZZ', 'email' => 'not-an-email'], 'e-mail');
        $refused(['party' => 'owner', 'op' => 'fly'], 'not something');
        $refused(['party' => 'owner', 'op' => 'convert'], 'did not match a person');

        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.decisions', $row), ['person' => ['party' => 'tenant', 'op' => 'add', 'first_name' => 'ZZ']])->assertStatus(422);
        self::assertSame([], $run->rows()->first()->decisions_json['owner']['people'] ?? []);

        // Leaving the company out takes its people with it.
        $svc->apply($run->rows()->first(), $run, ['person' => ['party' => 'owner', 'op' => 'add', 'first_name' => 'ZZ', 'last_name' => 'Temp']]);
        $row = $svc->apply($run->rows()->first(), $run, ['owner' => ['action' => 'skip']]);
        self::assertSame([], $row->decisions_json['owner']['people']);
    }

    public function test_a_company_already_in_corex_lists_the_people_already_linked_and_role_changes_and_removal_work(): void
    {
        [$agency, $branch, $admin] = $this->reosAgency();
        $company = $this->company($agency, $branch, 'ZZ Linked Company Pty Ltd', '0815550888', 'zz.linkedco@example.test');
        $rep = $this->person($agency, $branch, 'ZZ', 'Already Linked', '0815550999');
        ContactRepresentativeLinker::link($company, $rep, false, 'Director');
        $run = $this->makeRun($admin, [$this->companyRow(self::UUID_A, 'ZZ Linked Company Pty Ltd', ['Owner Phone' => '+27815550888', 'Owner Email' => 'zz.linkedco@example.test'])]);
        $svc = app(ReosDecisionService::class);

        $row = $run->rows()->first();
        self::assertSame($company->id, $row->decisions_json['owner']['contact_id']);
        $row = $svc->apply($row, $run, ['suburb_id' => $this->p24Suburb()->id]);   // ready, so it is a line whose Change controls list the people
        $html = $this->actingAs($admin)->get(route('corex.rentals.take-on-import.preview', $run))->assertOk()->getContent();
        self::assertStringContainsString('ZZ Already Linked', $html);
        self::assertStringContainsString('already linked in CoreX', $html);

        $row = $svc->apply($row, $run, ['person' => ['party' => 'owner', 'op' => 'add', 'first_name' => 'ZZ', 'last_name' => 'Newcomer', 'capacity' => 'Member']]);
        $key = $row->decisions_json['owner']['people'][0]['key'];
        $row = $svc->apply($row, $run, ['person' => ['party' => 'owner', 'op' => 'set_capacity', 'key' => $key, 'capacity' => 'Trustee']]);
        self::assertSame('Trustee', $row->decisions_json['owner']['people'][0]['capacity']);
        $row = $svc->apply($row, $run, ['person' => ['party' => 'owner', 'op' => 'remove', 'key' => $key]]);
        self::assertSame([], $row->decisions_json['owner']['people']);

        // Importing against the existing company links additions to it and leaves the people it already had.
        $svc->apply($row, $run, ['person' => ['party' => 'owner', 'op' => 'add', 'first_name' => 'ZZ', 'last_name' => 'Newcomer', 'capacity' => 'Member']]);
        $this->importReady($run, $admin);
        self::assertEqualsCanonicalizing(['Already Linked', 'Newcomer'], $company->representatives()->pluck('last_name')->all());
        self::assertSame(1, Contact::withoutGlobalScopes()->where('entity_name', 'ZZ Linked Company Pty Ltd')->count(), 'the company itself was reused');
    }

    public function test_the_people_the_import_created_are_archived_with_the_batch(): void
    {
        [$agency, , $admin] = $this->reosAgency();
        $run = $this->makeRun($admin, [$this->companyRow(self::UUID_A, 'ZZ TEST Delta Holdings Pty Ltd')]);
        app(ReosDecisionService::class)->apply($run->rows()->first(), $run, ['person' => ['party' => 'owner', 'op' => 'add', 'first_name' => 'ZZ', 'last_name' => 'Archivable', 'email' => 'zz.arch@example.test']]);
        $this->importReady($run, $admin);
        $person = Contact::withoutGlobalScopes()->where('last_name', 'Archivable')->firstOrFail();

        $counts = app(RentalTakeOnArchiveService::class)->archive($run->fresh());

        self::assertNotNull(Contact::withoutGlobalScopes()->withTrashed()->find($person->id)->deleted_at);
        self::assertGreaterThanOrEqual(1, $counts['archived']['contacts']);
        app(RentalTakeOnArchiveService::class)->restore(RentalTakeOnImportRun::withTrashed()->find($run->id));
        self::assertNull(Contact::withoutGlobalScopes()->find($person->id)->deleted_at, 'restore brings the person back');
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────

    /** A reOS row whose OWNER is a company with no phone / e-mail of its own (so nothing in CoreX matches it) unless overridden. */
    private function companyRow(string $uuid, string $company, array $o = []): array
    {
        return $this->reosRow(array_merge(['uuid' => $uuid, 'Owner' => $company, 'Owner Email' => '', 'Owner Phone' => ''], $o));
    }

    private function makeRun(User $user, array $rows): RentalTakeOnImportRun
    {
        $this->actingAs($user);

        return app(ReosTenancyRunService::class)->createRun($user, (int) $user->agency_id, $user->branch_id, $this->upload($this->scheduleFile($rows)));
    }

    /** Give every row its suburb (its agent was matched from reOS), then import the ready ones. */
    private function importReady(RentalTakeOnImportRun $run, User $admin): void
    {
        $suburb = $this->p24Suburb();
        $svc = app(ReosDecisionService::class);
        foreach ($run->rows()->orderBy('row_number')->get() as $row) {
            $svc->apply($row, $run, ['suburb_id' => $suburb->id]);
        }
        $this->actingAs($admin);
        $r = app(ReosTenancyConfirmService::class)->importChunk($run->fresh(), $admin, true);
        self::assertSame(0, $r['failed'], json_encode($run->rows()->pluck('import_error')));
    }

    private function person(Agency $agency, Branch $branch, string $first, string $last, string $phone): Contact
    {
        return Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => $first, 'last_name' => $last, 'phone' => $phone]);
    }

    private function company(Agency $agency, Branch $branch, string $name, string $phone = '', string $email = ''): Contact
    {
        return Contact::create(array_filter([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => $name, 'last_name' => '', 'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => $name,
            'phone' => $phone ?: null, 'email' => $email ?: null,
        ], fn ($v) => $v !== null));
    }
}
