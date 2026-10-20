<?php

namespace Tests\Feature\Platform\Agreement\Concerns;

use App\Models\PlatformEsign\Template;
use App\Models\PlatformEsign\WordingAudit;
use App\Models\PlatformEsign\WordingVersion;
use App\Services\PlatformEsign\Agreement\AgreementContent;
use App\Services\PlatformEsign\Agreement\AgreementPricing;
use Illuminate\Support\Facades\DB;

/**
 * For tests written against the tier-priced agreement (CoreX Team / CoreX Agency, versions before the October 2026 price change):
 * the environment as it was BEFORE the band pricing was published — the seed 1.0 is the current version.
 *
 * A fresh database now seeds the band-priced version on top of 1.0 (spec §11.5a); agreements already sent on 1.0 keep pricing by
 * tiers, and these tests keep proving that they do. Removing the band version here is test-database set-up, not product behaviour.
 * A published version can never be deleted through the app (WordingVersion refuses), so the rows are removed underneath it.
 */
trait PinsTierPricing
{
    protected function setUpPinsTierPricing(): void
    {
        app(AgreementContent::class)->ensureSeeded();
        $ids = WordingVersion::withTrashed()->get()
            ->filter(fn (WordingVersion $v) => AgreementPricing::isBands((array) $v->rates_json))
            ->pluck('id');
        WordingAudit::whereIn('version_id', $ids)->delete();
        DB::table((new WordingVersion())->getTable())->whereIn('id', $ids)->orderByDesc('id')->delete();
    }

    /** Back to a database that has never seeded the agreement (for tests of the very first seed). */
    protected function unseedAgreement(): void
    {
        WordingAudit::query()->delete();
        DB::table((new WordingVersion())->getTable())->orderByDesc('id')->delete();
        Template::withTrashed()->where('name', AgreementContent::TEMPLATE_NAME)->get()->each(fn (Template $t) => $t->forceDelete());
    }
}
