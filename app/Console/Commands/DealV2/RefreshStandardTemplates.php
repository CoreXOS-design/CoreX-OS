<?php

namespace App\Console\Commands\DealV2;

use App\Services\DealV2\DealPipelineTemplateProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AT-158 WS-V5 — upgrade shipped default pipeline templates to the current
 * (SA-conveyancing-corrected) definitions for an agency, or all agencies.
 *
 * Only a default template with NO deals referencing it is soft-deleted and
 * re-provisioned; a template in use (or an agency's own customised template) is
 * left untouched. No hard deletes. Idempotent — safe to re-run.
 *
 *   php artisan deals:refresh-standard-templates --agency=1
 *   php artisan deals:refresh-standard-templates --all
 */
class RefreshStandardTemplates extends Command
{
    protected $signature = 'deals:refresh-standard-templates {--agency= : A single agency id} {--all : Every agency}';

    protected $description = 'Refresh shipped default deal-pipeline templates to the corrected definitions (deal-free templates only; no hard deletes).';

    public function handle(DealPipelineTemplateProvisioner $provisioner): int
    {
        $agencyId = $this->option('agency');
        if (! $agencyId && ! $this->option('all')) {
            $this->error('Specify --agency=<id> or --all.');
            return self::INVALID;
        }

        $agencyIds = $agencyId
            ? [(int) $agencyId]
            : DB::table('agencies')->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($agencyIds as $id) {
            $r = $provisioner->refreshDefaultsForAgency($id);
            $this->info("Agency {$id}: refreshed {$r['refreshed']}, in-use kept {$r['skipped_in_use']}, "
                . "created {$r['created']}, steps {$r['steps_created']}.");
        }

        return self::SUCCESS;
    }
}
