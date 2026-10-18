<?php

namespace App\Console\Commands;

use App\Events\Docuperfect\TemplateAgencyAssigned;
use App\Models\Agency;
use App\Models\Docuperfect\Template;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/leases.md §15.12.4 (Build L0) — the one-time repair for an e-sign template that ended up with
 * no owning agency. A lease agreement can only be linked once it belongs to the agency linking it, and a
 * template with no owner is refused for every agency, so this gives it one.
 *
 *   php artisan templates:assign-agency {template_id} {agency_id}
 *
 * Idempotent (running it again for the same agency changes nothing), prints the template's ownership
 * before and after, records the change in the domain-event log, and REFUSES a template that already
 * belongs to a different agency — moving a template between agencies is not what this is for. No agency
 * id is ever written into code: the operator passes it.
 */
class AssignTemplateToAgency extends Command
{
    protected $signature = 'templates:assign-agency {template_id : docuperfect_templates.id} {agency_id : agencies.id that should own it}';

    protected $description = 'Give an ownerless e-sign template an owning agency (idempotent; refuses a template another agency owns)';

    public function handle(): int
    {
        $templateId = (int) $this->argument('template_id');
        $agencyId = (int) $this->argument('agency_id');

        $agency = $agencyId > 0 ? Agency::find($agencyId) : null;
        if (! $agency) {
            $this->error("Agency {$agencyId} does not exist — nothing changed.");

            return self::FAILURE;
        }

        $event = null;
        $exit = DB::transaction(function () use ($templateId, $agency, &$event) {
            $template = $templateId > 0 ? Template::query()->lockForUpdate()->find($templateId) : null;
            if (! $template) {
                $this->error("Template {$templateId} does not exist — nothing changed.");

                return self::FAILURE;
            }

            $this->line($this->describe('Before', $template));

            if ($template->agency_id !== null && (int) $template->agency_id === (int) $agency->id) {
                $this->info("Already owned by agency {$agency->id} — nothing to do.");

                return self::SUCCESS;
            }
            if ($template->agency_id !== null) {
                $this->error("Template {$template->id} already belongs to agency {$template->agency_id}. It will not be moved to agency {$agency->id}.");

                return self::FAILURE;
            }

            $template->agency_id = $agency->id;
            $template->save();

            $this->line($this->describe('After ', $template->fresh()));
            $event = new TemplateAgencyAssigned((int) $template->id, (string) $template->name, null, (int) $agency->id);

            return self::SUCCESS;
        });

        if ($event) {
            event($event);
            $this->info("Template assigned to agency {$agency->id}. Recorded in the domain-event log.");
        }

        return $exit;
    }

    private function describe(string $label, Template $template): string
    {
        return sprintf(
            '%s: template #%d "%s" — agency_id=%s, is_global=%s, is_esign=%s',
            $label,
            $template->id,
            $template->name,
            $template->agency_id === null ? 'NULL' : $template->agency_id,
            $template->is_global ? '1' : '0',
            $template->is_esign ? '1' : '0',
        );
    }
}
