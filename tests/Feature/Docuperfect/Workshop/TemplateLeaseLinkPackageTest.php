<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Workshop;

use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateRelease;
use App\Models\Docuperfect\TemplateReleaseEvent;
use App\Models\RentalLeaseTemplate;
use App\Services\Docuperfect\Release\TemplateReleaseException;
use App\Services\Docuperfect\Release\TemplateReleaseService;
use App\Services\Docuperfect\TemplateTransfer\TemplatePackage;
use App\Services\Docuperfect\TemplateTransfer\TemplatePackageImporter;
use App\Services\Docuperfect\TemplateTransfer\TemplateTransferException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/esign-template-transfer.md §13.8 — the package carries the lease link and field map; an import reports it and creates nothing from
 * it; a release from a package lands awaiting approval and applies the link only when approved; a later package is the next version.
 */
final class TemplateLeaseLinkPackageTest extends TestCase
{
    use RefreshDatabase;
    use WorkshopFixtures;

    private const MAP = ['rent' => 'monthly_rental', 'start_date' => 'lease_start', 'tenant_name' => 'tenant_name', 'landlord_name' => 'landlord_name', '_meta' => ['month_to_month' => true]];

    private function linked(\App\Models\Agency $agency, \App\Models\User $owner): Template
    {
        $t = $this->megaShaped($agency, $owner);
        RentalLeaseTemplate::withoutGlobalScopes()->create(['agency_id' => $agency->id, 'name' => 'Mega Lease 2026', 'docuperfect_template_id' => $t->id, 'category' => 'residential', 'is_active' => true, 'is_default' => true, 'field_map' => self::MAP]);

        return $t;
    }

    /** @return array{data: array, files: array, manifest: array} */
    private function readPackage(string $bytes): array
    {
        return TemplatePackage::read($this->tmpFile($bytes));
    }

    private function rebuilt(string $bytes, callable $mutate): string
    {
        $p = $this->readPackage($bytes);
        $data = $mutate($p['data']);

        return TemplatePackage::build($data, $p['files'], (string) $data['template']['name'], 'test')['bytes'];
    }

    public function test_the_package_carries_the_lease_link_and_a_template_without_one_carries_none(): void
    {
        $w = $this->agency('CoreX Workshop');
        $owner = $this->owner();
        $linked = $this->linked($w, $owner);
        $plain = $this->megaShaped($w, $owner, ['name' => 'Unlinked']);

        $p = $this->readPackage($this->export($linked, $owner)['bytes']);
        $this->assertSame('residential', $p['data']['lease_link']['category']);
        $this->assertSame('Mega Lease 2026', $p['data']['lease_link']['name']);
        $this->assertSame('monthly_rental', $p['data']['lease_link']['field_map']['rent']);
        $this->assertSame(1, $p['manifest']['format_version'], 'additive: the format version does not change');

        $this->assertArrayNotHasKey('lease_link', $this->readPackage($this->export($plain, $owner)['bytes'])['data']);
        $this->assertNull(TemplatePackageImporter::leaseLinkOf($this->readPackage($this->export($plain, $owner)['bytes'])['data']));
    }

    public function test_an_archived_link_is_not_carried(): void
    {
        $w = $this->agency('CoreX Workshop');
        $owner = $this->owner();
        $t = $this->linked($w, $owner);
        RentalLeaseTemplate::withoutGlobalScopes()->where('docuperfect_template_id', $t->id)->update(['is_active' => false]);

        $this->assertArrayNotHasKey('lease_link', $this->readPackage($this->export($t, $owner)['bytes'])['data']);
    }

    public function test_the_preview_reports_the_link_and_a_plain_import_creates_nothing_from_it(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $bytes = $this->export($this->linked($w, $owner), $owner)['bytes'];

        $imp = app(TemplatePackageImporter::class);
        $loaded = $imp->load($this->tmpFile($bytes));
        $preview = $imp->preview($loaded, $client);
        $this->assertSame(['category' => 'residential', 'name' => 'Mega Lease 2026', 'mappings' => count(self::MAP)], $preview[0]['lease_link']);

        $created = $this->importInto($bytes, $client, $owner);
        $this->assertCount(1, $created);
        $this->assertSame(0, RentalLeaseTemplate::withoutGlobalScopes()->where('agency_id', $client->id)->count(), 'an import never creates the link (esign-template-transfer.md §4.7)');
    }

    public function test_a_damaged_or_unsafe_lease_link_is_refused_in_plain_words(): void
    {
        $w = $this->agency('CoreX Workshop');
        $owner = $this->owner();
        $bytes = $this->export($this->linked($w, $owner), $owner)['bytes'];
        $imp = app(TemplatePackageImporter::class);

        $badCategory = $this->rebuilt($bytes, function ($d) { $d['lease_link']['category'] = 'timeshare'; return $d; });
        $unsafeMap = $this->rebuilt($bytes, function ($d) { $d['lease_link']['field_map']['rent'] = '{{ system("id") }}'; return $d; });
        $notArray = $this->rebuilt($bytes, function ($d) { $d['lease_link'] = 'nonsense'; return $d; });
        $hugeKey = $this->rebuilt($bytes, function ($d) { $d['lease_link']['field_map'][str_repeat('k', 80)] = 'x'; return $d; });

        foreach ([[$badCategory, 'unknown category'], [$unsafeMap, 'template expression'], [$notArray, 'damaged'], [$hugeKey, 'invalid field name']] as [$pkg, $needle]) {
            try {
                $imp->load($this->tmpFile($pkg));
                $this->fail("expected a refusal containing \"{$needle}\"");
            } catch (TemplateTransferException $e) {
                $this->assertStringContainsString($needle, implode(' ', array_merge([$e->getMessage()], $e->details)));
            }
        }
        $this->assertSame(0, Template::withoutGlobalScopes()->where('agency_id', '!=', $w->id)->count());
    }

    public function test_a_package_release_lands_awaiting_and_the_link_is_applied_only_at_approval(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $bytes = $this->export($this->linked($w, $owner), $owner)['bytes'];
        $svc = app(TemplateReleaseService::class);

        $loaded = app(TemplatePackageImporter::class)->load($this->tmpFile($bytes));
        $release = $svc->releaseFromPackage($loaded[0], $client, $owner);
        $copy = Template::withoutGlobalScopes()->find($release->template_id);
        $this->trackBlade($copy);

        $this->assertSame('awaiting_approval', $release->state);
        $this->assertSame('awaiting_approval', $copy->release_status);
        $this->assertSame(1, $release->version);
        $this->assertSame('residential', $release->lease_link['category']);
        $this->assertSame($loaded[0]['checksum'], $release->package_sha256);
        $this->assertSame('package', TemplateReleaseEvent::where('release_id', $release->id)->where('event', 'released')->first()->detail['via']);
        $this->assertFalse(Template::active()->visibleTo($this->agencyAdmin($client))->whereKey($copy->id)->exists(), 'not live');
        $this->assertSame(0, RentalLeaseTemplate::withoutGlobalScopes()->where('agency_id', $client->id)->count());

        $res = $svc->approve($release, $this->agencyAdmin($client), true);
        $this->assertSame('live', $res['release']->state);
        $row = RentalLeaseTemplate::withoutGlobalScopes()->where('agency_id', $client->id)->first();
        $this->assertSame((int) $copy->id, (int) $row->docuperfect_template_id);
        $this->assertSame('monthly_rental', $row->field_map['rent']);
    }

    public function test_a_later_package_of_the_same_document_is_the_next_version_and_replaces_the_older_one_when_approved(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $admin = $this->agencyAdmin($client);
        $t = $this->linked($w, $owner);
        $svc = app(TemplateReleaseService::class);
        $imp = app(TemplatePackageImporter::class);

        $r1 = $svc->releaseFromPackage($imp->load($this->tmpFile($this->export($t, $owner)['bytes']))[0], $client, $owner);
        $c1 = Template::withoutGlobalScopes()->find($r1->template_id);
        $this->trackBlade($c1);
        $svc->approve($r1, $admin, true);

        $r2 = $svc->releaseFromPackage($imp->load($this->tmpFile($this->export($t, $owner)['bytes']))[0], $client, $owner);   // same document name again
        $c2 = Template::withoutGlobalScopes()->find($r2->template_id);
        $this->trackBlade($c2);
        $this->assertSame(2, $r2->version);
        $this->assertSame($r1->source_template_id, $r2->source_template_id, 'same lineage');
        $this->assertSame($r1->id, $r2->previous_release_id);
        $this->assertStringContainsString(' v2', $c2->name, 'a name clash never replaces anything');

        $svc->approve($r2, $admin, true);
        $this->assertSame('superseded', $r1->fresh()->state);
        $this->assertNotNull($c1->fresh()->archived_at);
        $this->assertSame((int) $c2->id, (int) RentalLeaseTemplate::withoutGlobalScopes()->where('agency_id', $client->id)->value('docuperfect_template_id'), 'the lease link moved to the new version');
    }

    public function test_only_the_owner_can_release_from_a_package_and_a_broken_one_creates_nothing(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $bytes = $this->export($this->linked($w, $owner), $owner)['bytes'];
        $loaded = app(TemplatePackageImporter::class)->load($this->tmpFile($bytes));
        $svc = app(TemplateReleaseService::class);

        try {
            $svc->releaseFromPackage($loaded[0], $client, $this->agencyAdmin($client));
            $this->fail('expected a refusal');
        } catch (TemplateReleaseException $e) {
            $this->assertSame(403, $e->status);
        }
        $before = [Template::withoutGlobalScopes()->count(), TemplateRelease::withoutGlobalScopes()->count()];
        $broken = $loaded[0];
        $broken['data']['template']['document_type'] = ['slug' => 'no-such-type-here', 'label' => 'Missing'];
        try {
            $svc->releaseFromPackage($broken, $client, $owner);
            $this->fail('a missing document type needs an explicit allowance');
        } catch (TemplateReleaseException $e) {
            $this->assertStringContainsString('confirmation', $e->getMessage());
        }
        $this->assertSame($before, [Template::withoutGlobalScopes()->count(), TemplateRelease::withoutGlobalScopes()->count()]);
    }
}
