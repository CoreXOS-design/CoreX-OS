<?php

declare(strict_types=1);

namespace Tests\Feature\TitleDeeds;

use App\Exceptions\TitleDeedException;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedAuditLog;
use App\Models\TitleDeeds\TitleDeedOwner;
use App\Services\TitleDeeds\TitleDeedService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * AT-450 - the record: create / edit / archive / restore, and the input space around them.
 * Paths proven: all fields; each optional field omitted; the lazy shortcut (property + one owner + date); no owner; malformed
 * date; future date; an owner removed and re-added (soft-delete + unique key); an archived contact; an archived property;
 * archive and restore twice; a collected deed accepts notes only.
 */
final class TitleDeedRecordTest extends TitleDeedTestCase
{
    public function test_a_deed_is_logged_with_two_owners_through_the_screen(): void
    {
        $property = $this->property('21 Dee Road', 'Uvongo');
        $a = $this->contact('Thandi', 'Mkhize');
        $b = $this->contact('Sipho', 'Mkhize');

        $this->actingAs($this->admin)->post(route('title-deeds.store'), [
            'property_id' => $property->id, 'owner_ids' => [$a->id, $b->id], 'agent_id' => $this->agent->id, 'branch_id' => $this->branchA->id,
            'attorney_firm' => 'Shepstone & Wylie', 'received_at' => now()->subDay()->toDateString(), 'storage_location' => 'Safe 2', 'notes' => 'Bond cancelled',
        ])->assertRedirect();

        $deed = TitleDeed::firstOrFail();
        $this->assertSame('received', $deed->status);
        $this->assertSame($this->agencyId, $deed->agency_id);
        $this->assertSame(2, $deed->owners()->count());
        $this->assertStringContainsString('Dee Road', $deed->property_display);
        $this->assertTrue(TitleDeedAuditLog::where('title_deed_id', $deed->id)->where('event', 'created')->exists());
    }

    public function test_the_lazy_shortcut_works_property_one_owner_and_a_date_only(): void
    {
        $property = $this->property();
        $a = $this->contact('Thandi', 'Mkhize');

        $this->actingAs($this->admin)->post(route('title-deeds.store'), [
            'property_id' => $property->id, 'owner_ids' => [$a->id], 'received_at' => now()->toDateString(),
        ])->assertRedirect();

        $deed = TitleDeed::firstOrFail();
        $this->assertNull($deed->attorney_firm);
        $this->assertNull($deed->storage_location);
        $this->assertNull($deed->notes);
        $this->assertNotNull($deed->agent_id);   // derived from the property
        $this->assertNotNull($deed->branch_id);
    }

    public function test_no_owner_is_refused_with_a_plain_message(): void
    {
        $response = $this->actingAs($this->admin)->from(route('title-deeds.create'))->post(route('title-deeds.store'), [
            'property_id' => $this->property()->id, 'received_at' => now()->toDateString(),
        ]);
        $response->assertSessionHasErrors('owner_ids');
        $this->assertSame(0, TitleDeed::count());
    }

    public function test_a_malformed_or_future_date_and_a_missing_property_are_refused(): void
    {
        $a = $this->contact('Thandi', 'Mkhize');
        $this->actingAs($this->admin)->post(route('title-deeds.store'), ['property_id' => $this->property()->id, 'owner_ids' => [$a->id], 'received_at' => 'not-a-date'])->assertSessionHasErrors('received_at');
        $this->actingAs($this->admin)->post(route('title-deeds.store'), ['property_id' => $this->property()->id, 'owner_ids' => [$a->id], 'received_at' => now()->addDays(3)->toDateString()])->assertSessionHasErrors('received_at');
        $this->actingAs($this->admin)->post(route('title-deeds.store'), ['owner_ids' => [$a->id], 'received_at' => now()->toDateString()])->assertSessionHasErrors('property_id');
        $this->actingAs($this->admin)->post(route('title-deeds.store'), ['property_id' => 999999, 'owner_ids' => [$a->id], 'received_at' => now()->toDateString()])->assertSessionHasErrors('property_id');
        $this->assertSame(0, TitleDeed::count());
    }

    public function test_an_owner_from_another_agency_cannot_be_attached(): void
    {
        $other = $this->makeAgency('Cape Town');
        $foreign = $this->contact('Zara', 'Naidoo', agencyId: $other);

        $this->actingAs($this->agent)->post(route('title-deeds.store'), [
            'property_id' => $this->property()->id, 'owner_ids' => [$foreign->id], 'received_at' => now()->toDateString(),
        ]);
        $this->assertSame(0, TitleDeed::count());
    }

    public function test_owners_can_be_removed_and_added_back_without_a_duplicate_key_error(): void
    {
        $a = $this->contact('Thandi', 'Mkhize');
        $b = $this->contact('Sipho', 'Mkhize');
        $deed = $this->deed([$a, $b]);
        $svc = app(TitleDeedService::class);
        $base = ['property_id' => $deed->property_id, 'received_at' => now()->subDay()->toDateString()];

        $svc->update($this->admin, $deed, $base + ['owner_ids' => [$a->id]]);
        $this->assertSame(1, TitleDeedOwner::where('title_deed_id', $deed->id)->count());
        $this->assertSame(2, TitleDeedOwner::withTrashed()->where('title_deed_id', $deed->id)->count());

        $svc->update($this->admin, $deed, $base + ['owner_ids' => [$a->id, $b->id]]);
        $this->assertSame(2, TitleDeedOwner::where('title_deed_id', $deed->id)->count());
        $this->assertSame(2, TitleDeedOwner::withTrashed()->where('title_deed_id', $deed->id)->count(), 'the soft-deleted row is restored, never duplicated');
    }

    public function test_an_edit_is_audited_and_archive_restore_are_idempotent(): void
    {
        $deed = $this->deed();
        $svc = app(TitleDeedService::class);

        $svc->update($this->admin, $deed, [
            'property_id' => $deed->property_id, 'owner_ids' => $deed->owners->pluck('contact_id')->all(), 'received_at' => $deed->received_at->toDateString(),
            'attorney_firm' => 'Cox Yeats', 'storage_location' => 'Strongroom',
        ]);
        $this->assertSame('Cox Yeats', $deed->fresh()->attorney_firm);
        $this->assertTrue(TitleDeedAuditLog::where('title_deed_id', $deed->id)->where('event', 'edited')->exists());

        $svc->archive($this->admin, $deed);
        $svc->archive($this->admin, $deed->fresh() ?? $deed);   // second archive: absorbed
        $this->assertSoftDeleted('title_deeds', ['id' => $deed->id]);

        $svc->restore($this->admin, $deed);
        $svc->restore($this->admin, $deed);                     // second restore: absorbed
        $this->assertNotNull(TitleDeed::find($deed->id));
        $this->assertSame(1, TitleDeedAuditLog::where('title_deed_id', $deed->id)->where('event', 'archived')->count());
        $this->assertSame(1, TitleDeedAuditLog::where('title_deed_id', $deed->id)->where('event', 'restored')->count());
    }

    public function test_an_archived_deed_cannot_be_edited_until_restored(): void
    {
        $deed = $this->deed();
        app(TitleDeedService::class)->archive($this->admin, $deed);

        $this->expectException(TitleDeedException::class);
        app(TitleDeedService::class)->update($this->admin, $deed->fresh() ?? TitleDeed::withTrashed()->find($deed->id), ['notes' => 'x']);
    }

    public function test_an_archived_property_and_an_archived_owner_contact_still_render(): void
    {
        $a = $this->contact('Thandi', 'Mkhize');
        $deed = $this->deed([$a]);
        $deed->property->delete();
        $a->delete();

        $this->actingAs($this->admin)->get(route('title-deeds.show', $deed->id))
            ->assertOk()->assertSee('Marine Drive')->assertSee('Thandi Mkhize')->assertSee('contact archived');
        $this->actingAs($this->admin)->get(route('title-deeds.index'))->assertOk()->assertSee('Marine Drive');
    }

    public function test_a_collected_deed_accepts_notes_only(): void
    {
        $deed = $this->deed();
        $deed->forceFill(['status' => 'collected', 'collected_at' => now(), 'collected_by_name' => 'Thandi Mkhize'])->save();
        $svc = app(TitleDeedService::class);

        $svc->update($this->admin, $deed, ['notes' => 'Collected after hours', 'attorney_firm' => 'SHOULD NOT CHANGE']);
        $fresh = $deed->fresh();
        $this->assertSame('Collected after hours', $fresh->notes);
        $this->assertSame('Shepstone & Wylie', $fresh->attorney_firm);
    }

    public function test_the_scan_is_stored_privately_and_replaced_without_a_hard_delete(): void
    {
        Storage::fake('local');
        $deed = $this->deed();
        $svc = app(TitleDeedService::class);

        $first = $svc->attachScan($this->admin, $deed, UploadedFile::fake()->create('deed.pdf', 300, 'application/pdf'));
        $this->assertSame('local', $first->disk);
        Storage::disk('local')->assertExists($first->storage_path);
        $this->assertSame('Copy only - original held at office', $deed->fresh()->scan_label);

        $second = $svc->attachScan($this->admin, $deed->fresh(), UploadedFile::fake()->create('deed2.pdf', 200, 'application/pdf'));
        $this->assertSame($second->id, $deed->fresh()->scan_document_id);
        $this->assertSoftDeleted('documents', ['id' => $first->id]);

        $deed->forceFill(['status' => 'collected', 'collected_at' => now()])->save();
        $this->assertStringStartsWith('Original collected', (string) $deed->fresh()->scan_label);
    }

    public function test_a_collected_deed_takes_no_new_scan(): void
    {
        Storage::fake('local');
        $deed = $this->deed();
        $deed->forceFill(['status' => 'collected', 'collected_at' => now()])->save();

        $this->actingAs($this->admin)->post(route('title-deeds.scan.upload', $deed->id), ['scan' => UploadedFile::fake()->create('late.pdf', 20, 'application/pdf')])
            ->assertSessionHas('error');
        $this->assertNull($deed->fresh()->scan_document_id);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_scan_that_is_not_a_pdf_or_image_is_refused(): void
    {
        Storage::fake('local');
        $deed = $this->deed();
        $this->actingAs($this->admin)->post(route('title-deeds.scan.upload', $deed->id), ['scan' => UploadedFile::fake()->create('virus.exe', 20, 'application/octet-stream')])
            ->assertSessionHasErrors('scan');
        $this->assertNull($deed->fresh()->scan_document_id);
    }
}
