<?php

declare(strict_types=1);

namespace Tests\Feature\TitleDeeds;

use App\Mail\TitleDeeds\TitleDeedCollectedMail;
use App\Mail\TitleDeeds\TitleDeedNoticeMail;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedSettingHistory;
use App\Services\TitleDeeds\TitleDeedNotifier;
use App\Services\TitleDeeds\TitleDeedPinService;
use App\Services\TitleDeeds\TitleDeedReferenceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * AT-450 round 4 - every title deed has a unique reference number (spec section 18): per agency, never reused, safe under concurrent saves,
 * backfilled in the order deeds were logged, searchable however it is typed, and printed where the deed is identified.
 */
final class TitleDeedReferenceTest extends TitleDeedTestCase
{
    private function logDeed(string $road = 'Alpha'): TitleDeed
    {
        return $this->deed([$this->contact('Ref', 'Owner ' . $road)], ['_property' => $this->property('Road ' . $road, 'Uvongo')]);
    }

    /* ═════ Assigned once, formatted, never reused ═════ */

    public function test_a_deed_gets_the_agencys_next_number_in_the_td_format_when_it_is_logged(): void
    {
        $a = $this->logDeed('Alpha');
        $b = $this->logDeed('Bravo');

        $this->assertSame(1, (int) $a->reference_no);
        $this->assertSame('TD-00001', $a->reference);
        $this->assertSame('TD-00002', $b->reference);
        $this->assertSame('TD-00003', $this->logDeed('Charlie')->reference);
    }

    public function test_it_never_changes_is_kept_on_archive_and_restore_and_never_reused(): void
    {
        $a = $this->logDeed('Alpha');
        $b = $this->logDeed('Bravo');

        $b->delete();                                  // archive the newest
        $c = $this->logDeed('Charlie');
        $this->assertSame('TD-00003', $c->reference, 'the archived deed\'s number is not handed out again');

        $b->restore();
        $this->assertSame('TD-00002', $b->fresh()->reference, 'kept on restore');
        $a->update(['notes' => 'edited', 'status' => 'notified']);
        $this->assertSame('TD-00001', $a->fresh()->reference, 'an edit or a status change never touches it');
        $this->assertSame(['TD-00001', 'TD-00002', 'TD-00003'], TitleDeed::withTrashed()->orderBy('reference_no')->pluck('reference')->all());
    }

    public function test_a_failed_create_leaves_no_gap_and_a_second_number_never_repeats(): void
    {
        $a = $this->logDeed('Alpha');
        try {
            DB::transaction(function () {
                $this->logDeed('Bravo');
                throw new \RuntimeException('roll back this create');
            });
        } catch (\RuntimeException $e) {
        }

        $this->assertSame('TD-00002', $this->logDeed('Charlie')->reference, 'the rolled-back create gave its number back together with the deed');
        $this->assertSame(1, (int) $a->reference_no);
    }

    public function test_the_database_itself_refuses_a_duplicate_number_in_an_agency(): void
    {
        $a = $this->logDeed('Alpha');
        $b = $this->logDeed('Bravo');

        $this->expectException(QueryException::class);
        DB::table('title_deeds')->where('id', $b->id)->update(['reference_no' => $a->reference_no, 'reference' => $a->reference]);
    }

    /* ═════ Per-agency isolation ═════ */

    public function test_each_agency_has_its_own_sequence(): void
    {
        $this->logDeed('Alpha');
        $this->logDeed('Bravo');
        $other = $this->makeAgency('Cape Town');
        $otherBranch = \App\Models\Branch::create(['agency_id' => $other, 'name' => 'Cape Branch']);
        $first = TitleDeed::withoutGlobalScopes()->create([
            'agency_id' => $other, 'branch_id' => $otherBranch->id, 'agent_id' => $this->agent->id, 'created_by' => $this->admin->id,
            'property_id' => $this->property('Road Z', 'Uvongo')->id, 'property_address' => 'Road Z', 'received_at' => '2026-09-01', 'status' => 'received',
        ]);

        $this->assertSame('TD-00001', $first->reference, 'the second agency starts at 1 whatever the first has reached');
        $this->assertSame('TD-00003', $this->logDeed('Charlie')->reference, 'and the first agency carries on undisturbed');
        $this->assertSame(2, DB::table('title_deed_sequences')->count());
    }

    /* ═════ Concurrency (real processes, one database) ═════ */

    public function test_numbers_are_unique_when_many_processes_save_at_the_same_moment(): void
    {
        $agency = 987654321;     // an agency id no fixture uses: the children only touch the counter table
        $cfg = config('database.connections.' . config('database.default'));
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => getenv('HOME') ?: '/tmp', 'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => (string) $cfg['host'], 'DB_PORT' => (string) $cfg['port'], 'DB_DATABASE' => (string) $cfg['database'],
            'DB_USERNAME' => (string) $cfg['username'], 'DB_PASSWORD' => (string) $cfg['password'],
        ];
        $script = base_path('tests/Support/allocate-title-deed-references.php');
        $procs = [];
        $this->forgetCommittedCounter($agency);   // anything an earlier, interrupted run committed
        try {
            for ($p = 0; $p < 5; $p++) {
                $proc = proc_open([PHP_BINARY, $script, (string) $agency, '8'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
                $this->assertIsResource($proc);
                $procs[] = [$proc, $pipes];
            }
            $numbers = [];
            $errors = '';
            foreach ($procs as [$proc, $pipes]) {
                $numbers = array_merge($numbers, array_filter(array_map('trim', explode("\n", (string) stream_get_contents($pipes[1])))));
                $errors .= (string) stream_get_contents($pipes[2]);
                proc_close($proc);
            }

            $this->assertSame('', trim($errors), 'the helper processes ran cleanly: ' . $errors);
            $this->assertCount(40, $numbers, '5 processes x 8 allocations');
            $this->assertCount(40, array_unique($numbers), 'no number was handed out twice');
            sort($numbers, SORT_NUMERIC);
            $this->assertSame(range(1, 40), array_map('intval', $numbers), 'consecutive: no gaps either');
        } finally {
            $this->forgetCommittedCounter($agency);   // the children committed OUTSIDE this test's transaction, so a delete inside it would be rolled back
        }
    }

    /* ═════ Backfill ═════ */

    public function test_the_backfill_numbers_existing_deeds_per_agency_in_the_order_they_were_logged_archived_ones_included(): void
    {
        $now = now();
        $mk = function (int $agency, string $addr, string $created, bool $archived = false) use ($now) {
            return DB::table('title_deeds')->insertGetId([
                'agency_id' => $agency, 'branch_id' => $this->branchA->id, 'agent_id' => $this->agent->id, 'created_by' => $this->admin->id, 'property_id' => $this->property($addr)->id,
                'property_address' => $addr, 'received_at' => '2026-09-01', 'status' => 'received', 'created_at' => $created, 'updated_at' => $now, 'deleted_at' => $archived ? $now : null,
            ]);
        };
        $other = $this->makeAgency('Cape Town');
        // inserted OUT of order on purpose: the number follows the date logged, then the id
        $third = $mk($this->agencyId, 'Third St', '2026-03-03 10:00:00');
        $first = $mk($this->agencyId, 'First St', '2026-01-01 10:00:00', archived: true);
        $second = $mk($this->agencyId, 'Second St', '2026-02-02 10:00:00');
        $tieA = $mk($this->agencyId, 'Tie A', '2026-04-04 10:00:00');
        $tieB = $mk($this->agencyId, 'Tie B', '2026-04-04 10:00:00');
        $otherOne = $mk($other, 'Other One', '2026-05-05 10:00:00');
        DB::table('title_deeds')->update(['reference_no' => null, 'reference' => null]);
        DB::table('title_deed_sequences')->delete();

        app(TitleDeedReferenceService::class)->backfillExisting('TD');

        $ref = fn (int $id) => DB::table('title_deeds')->where('id', $id)->value('reference');
        $this->assertSame(['TD-00001', 'TD-00002', 'TD-00003', 'TD-00004', 'TD-00005'], [$ref($first), $ref($second), $ref($third), $ref($tieA), $ref($tieB)], 'oldest logged first, the archived one included, the id breaks a tie');
        $this->assertSame('TD-00001', $ref($otherOne), 'each agency is numbered on its own');
        $this->assertSame(6, (int) DB::table('title_deed_sequences')->where('agency_id', $this->agencyId)->value('next_value'), 'the counter continues after the highest');
        $this->assertSame(2, (int) DB::table('title_deed_sequences')->where('agency_id', $other)->value('next_value'));
        $this->assertSame(6, DB::table('title_deeds')->count(), 'nothing was deleted');

        // a new deed carries on from there
        $this->assertSame('TD-00006', $this->logDeed('Newest')->reference);
    }

    public function test_running_the_backfill_again_changes_nothing_that_is_numbered(): void
    {
        $a = $this->logDeed('Alpha');
        $b = $this->logDeed('Bravo');
        app(TitleDeedReferenceService::class)->backfillExisting('TD');

        $this->assertSame('TD-00001', $a->fresh()->reference);
        $this->assertSame('TD-00002', $b->fresh()->reference);
        $this->assertSame(3, (int) DB::table('title_deed_sequences')->where('agency_id', $this->agencyId)->value('next_value'));
    }

    /* ═════ Prefix ═════ */

    public function test_changing_the_prefix_only_affects_new_deeds(): void
    {
        $old = $this->logDeed('Alpha');
        $this->save(['reference_prefix' => 'filing'])->assertSessionHasNoErrors();
        $new = $this->logDeed('Bravo');

        $this->assertSame('TD-00001', $old->fresh()->reference, 'a deed already numbered never changes');
        $this->assertSame('FILING-00002', $new->reference, 'the sequence carries on; only the printed prefix is new');
        $this->assertSame(['TD-00001', 'FILING-00002'], TitleDeed::orderBy('reference_no')->pluck('reference')->all());
    }

    public function test_the_prefix_is_cleaned_defaulted_validated_and_kept_in_the_history(): void
    {
        $this->save(['reference_prefix' => ' tdx- '])->assertSessionHasNoErrors();
        $this->assertSame('TDX', \App\Models\TitleDeeds\TitleDeedSetting::referencePrefixFor($this->agencyId), 'dashes, spaces and case are tidied');

        $h = TitleDeedSettingHistory::where('setting_key', 'reference_prefix')->firstOrFail();
        $this->assertSame('TD', $h->from_value);
        $this->assertSame('TDX', $h->to_value);

        $this->save(['reference_prefix' => ' -- '])->assertSessionHasErrors('reference_prefix');
        $this->save(['reference_prefix' => str_repeat('A', 41)])->assertSessionHasErrors('reference_prefix');
        $this->assertSame('TDX', \App\Models\TitleDeeds\TitleDeedSetting::referencePrefixFor($this->agencyId), 'nothing changed by the refused saves');
        $this->assertSame('TD', \App\Models\TitleDeeds\TitleDeedSetting::referencePrefixFor($this->makeAgency('Cape Town')), 'another agency keeps the default');

        $this->assertSame('TD-00042', app(TitleDeedReferenceService::class)->format('TD', 42));
        $this->assertSame('TD-123456', app(TitleDeedReferenceService::class)->format('TD', 123456), 'a number past 99999 simply grows longer');
    }

    public function test_the_settings_screen_has_the_reference_numbers_section_with_the_exact_wording(): void
    {
        $html = $this->actingAs($this->admin)->get(route('corex.settings.title-deeds.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('Reference numbers', $html);
        $this->assertStringContainsString('Reference prefix', $html);
        $this->assertStringContainsString('Printed on every title deed record, e.g. TD-00042. Changing it only affects new deeds.', $html);
        $this->assertStringContainsString('The next deed logged will be TD-00001.', $html);

        $controls = collect(config('agency-onboarding-copy.properties.controls'))->where('source', 'title_deeds')->keyBy('key');
        $this->assertSame('Reference prefix', $controls['reference_prefix']['label'], 'the wizard says the same');
    }

    /* ═════ Search ═════ */

    public function test_search_finds_a_reference_however_it_is_typed(): void
    {
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf', 'Hotel', 'India', 'Juliet', 'Kilo', 'Lima'] as $road) {
            $this->logDeed($road);
        }
        $find = fn (string $term) => TitleDeed::query()->search($term)->orderBy('reference_no')->pluck('reference')->all();

        foreach (['TD-00007', 'td-00007', 'TD00007', 'td00007', 'TD 00007', 'TD_00007', '00007', '0007', '007', '7', 'TD7', 'td-7', 'TD-7'] as $typed) {
            $this->assertSame(['TD-00007'], $find($typed), "\"$typed\" finds TD-00007");
        }
        $this->assertSame(['TD-00012'], $find('12'));
        $this->assertSame(['TD-00001', 'TD-00010', 'TD-00011', 'TD-00012'], $find('1'), 'a partial number finds every reference containing it');
        $this->assertSame(['TD-00001', 'TD-00010', 'TD-00011', 'TD-00012'], $find('TD-0001'), 'a partial reference: leading zeros are ignored, so this is "TD" + a number containing 1');
        $this->assertSame(['TD-00010', 'TD-00011', 'TD-00012'], TitleDeed::query()->where('reference', 'like', '%TD-0001%')->orderBy('reference_no')->pluck('reference')->all(), 'a literal substring of the printed text still matches too');
        $this->assertCount(12, $find('TD'), 'the prefix alone finds all of them');
        $this->assertCount(12, $find('td-'), 'a dash alone after the prefix too');
        $this->assertSame([], $find('XX7'), 'a different prefix finds nothing');
        $this->assertSame([], $find('99'));
        $this->assertSame(['TD-00003'], $find('Charlie'), 'a word still searches the address');
    }

    public function test_search_by_reference_works_on_the_page_and_the_placeholder_says_so(): void
    {
        $a = $this->logDeed('Alpha');
        $b = $this->logDeed('Bravo');

        $html = $this->actingAs($this->admin)->get(route('title-deeds.index', ['search' => 'td00002']))->assertOk()->getContent();
        $this->assertStringContainsString('Road Bravo', $html);
        $this->assertStringNotContainsString('Road Alpha', $html);
        $this->assertStringContainsString('placeholder="Reference, property, owner, attorney, collector"', $html);
        $this->assertTrue(TitleDeed::query()->search('2')->whereKey($b->id)->exists());
    }

    public function test_a_number_in_an_address_still_finds_the_address(): void
    {
        $d = $this->deed([$this->contact('Num', 'Owner')], ['_property' => $this->property('14 Marine Drive', 'Shelly Beach')]);
        $this->logDeed('Bravo');

        $this->assertContains($d->id, TitleDeed::query()->search('14 Marine')->pluck('id')->all());
        $this->assertContains($d->id, TitleDeed::query()->search('Marine')->pluck('id')->all());
    }

    /* ═════ Where it is shown ═════ */

    public function test_the_success_message_after_logging_says_the_number_to_write_on_the_envelope(): void
    {
        $property = $this->property('Road Success', 'Uvongo');
        $owner = $this->contact('Thandi', 'Mkhize');

        $this->actingAs($this->admin)->post(route('title-deeds.store'), $this->tk('create') + [
            'property_id' => $property->id, 'owner_ids' => [$owner->id], 'agent_id' => $this->agent->id, 'branch_id' => $this->branchA->id,
            'received_at' => now()->toDateString(), 'storage_location_id' => $this->location->id,
        ])->assertSessionHas('success', 'Logged as TD-00001 - write this on the envelope.');

        $this->actingAs($this->admin)->get(route('title-deeds.show', TitleDeed::firstOrFail()->id))->assertSee('Logged as TD-00001 - write this on the envelope.', false);
    }

    public function test_the_record_shows_the_reference_large_beside_the_address_and_the_handover_names_it(): void
    {
        $deed = $this->logDeed('Alpha');

        $record = $this->actingAs($this->admin)->get(route('title-deeds.show', $deed->id))->assertOk()->getContent();
        $this->assertStringContainsString('data-reference>TD-00001<', $record);
        $this->assertStringContainsString('text-2xl', $record);
        $this->assertSame(1, preg_match('/>Reference<\/div>\s*<div[^>]*data-reference>TD-00001/', $record), 'labelled "Reference"');

        $this->actingAs($this->admin)->get(route('title-deeds.handover', $deed->id))->assertOk()->assertSee('Hand over: TD-00001', false);
    }

    public function test_the_list_has_a_sortable_ref_column_first_and_the_csv_starts_with_the_reference(): void
    {
        $a = $this->logDeed('Alpha');
        $b = $this->logDeed('Bravo');

        $html = $this->actingAs($this->admin)->get(route('title-deeds.index'))->assertOk()->getContent();
        $header = substr($html, (int) strpos($html, '<thead'), 700);
        $this->assertLessThan((int) strpos($header, '>Property'), (int) strpos($header, '>Ref'), 'Ref is the first column');
        $this->assertStringContainsString('sort_a=ref', $html, 'and it sorts');
        $this->assertStringContainsString('data-td-ref>TD-00001<', $html);

        $csv = $this->actingAs($this->admin)->get(route('title-deeds.export'))->assertOk()->streamedContent();
        $lines = array_values(array_filter(preg_split('/\r?\n/', $csv)));
        $this->assertStringStartsWith("\xEF\xBB\xBFReference,Property,", $lines[0]);
        $this->assertStringStartsWith('TD-00001,"Road Alpha', $lines[1]);
    }

    public function test_the_owner_notice_the_whatsapp_text_and_the_collected_email_name_the_reference(): void
    {
        Mail::fake();
        $this->settings(['pin_mode' => 'optional']);
        $deed = $this->deed([$this->contact('Lerato', 'Khumalo', email: 'lerato@example.test', phone: '0731112222')], ['_property' => $this->property('Road Notice', 'Uvongo')]);
        app(TitleDeedNotifier::class)->notifyOwners($this->admin, $deed);

        Mail::assertSent(TitleDeedNoticeMail::class, fn ($m) => str_contains($m->propertyAddress, '(reference TD-00001)'));

        $event = \App\Models\TitleDeeds\TitleDeedEvent::where('channel', 'whatsapp')->firstOrFail();
        $loc = $this->actingAs($this->admin)->post(route('title-deeds.events.open', [$deed->id, $event->id]))->headers->get('Location');
        $this->assertStringContainsString('(reference TD-00001)', rawurldecode((string) $loc), 'the WhatsApp text');

        // a handover: the collected email names it too
        $deed = $deed->fresh();
        $this->actingAs($this->admin)->post(route('title-deeds.handover.submit', $deed->id), $this->tk('handover', $deed) + [
            'method' => 'id_check', 'collector_contact_id' => $deed->owners->first()->contact_id, 'id_number_seen' => '8001015009087',
            'typed_name' => 'Lerato Khumalo', 'signature_image' => $this->pngDataUri(),
        ])->assertSessionHas('success');
        Mail::assertSent(TitleDeedCollectedMail::class, fn ($m) => str_contains($m->propertyAddress, 'TD-00001'));
    }

    /** Delete the helper processes' counter row on a separate, auto-committing connection (this test's own connection is inside a transaction). */
    private function forgetCommittedCounter(int $agency): void
    {
        config(['database.connections.tdref_cleanup' => config('database.connections.' . config('database.default'))]);
        DB::purge('tdref_cleanup');
        DB::connection('tdref_cleanup')->table('title_deed_sequences')->where('agency_id', $agency)->delete();
        DB::purge('tdref_cleanup');
    }

    private function save(array $post)
    {
        return $this->actingAs($this->admin)->post(route('corex.settings.title-deeds.update'), $post);
    }
}
