<?php

namespace App\Console\Commands;

use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Template;
use App\Models\User;
use App\Services\PlatformEsign\EsignService;
use Illuminate\Console\Command;

/**
 * AT-447 — seeds (or removes) a DEMO CoreX contract in Platform E-Sign (spec §3A): one wording template and one
 * document with two signers. NO email is sent; open the document in Platform E-Sign to copy a signer's link.
 * QA only — never run on live.
 *
 *   php artisan platform-esign:demo            create (idempotent)
 *   php artisan platform-esign:demo --remove   archive (soft-delete) the demo rows
 */
class PlatformEsignDemo extends Command
{
    protected $signature = 'platform-esign:demo {--remove : archive the demo contract instead of creating it}';

    protected $description = 'Create or remove a demo CoreX contract in Platform E-Sign (QA only)';

    public const NAME = 'DEMO — CoreX Subscription Agreement';

    public function handle(EsignService $svc): int
    {
        $existing = Template::where('name', self::NAME)->get();

        if ($this->option('remove')) {
            foreach ($existing as $t) {
                Document::where('template_id', $t->id)->get()->each->delete();
                $t->delete();   // archived (soft delete) — never a hard delete
            }
            $this->info('Demo contract archived (' . $existing->count() . ' template).');

            return self::SUCCESS;
        }
        if ($existing->isNotEmpty()) {
            $this->info('Demo contract already exists (template #' . $existing->first()->id . ').');

            return self::SUCCESS;
        }

        $owner = User::withoutGlobalScopes()->where('role', 'super_admin')->orderBy('id')->first();
        $body = "# CoreX Subscription Agreement (DEMO)\n\nThis is a demonstration contract between CoreX and an agency. It exists only inside Platform E-Sign and is used to try out sending and signing.\n\n- Payment: by debit order.\n\nBy signing, the agency principal confirms that they are authorised to bind the agency to this agreement.";
        $tpl = $svc->createTemplate([
            'name' => self::NAME, 'kind' => 'subscription_agreement', 'source' => 'web', 'body' => $body,
            'roles' => [['label' => 'Agency Principal'], ['label' => 'CoreX']],
        ], null, $owner?->id);

        $doc = $svc->send($tpl, [
            'title' => self::NAME, 'sequential' => true, 'expiry_days' => 14,
            'signers' => [
                ['role_key' => 'r1', 'name' => 'Demo Principal', 'email' => 'demo.principal@example.invalid', 'id_number' => '8001015009087'],
                ['role_key' => 'r2', 'name' => 'Demo CoreX Signer', 'email' => 'demo.corex@example.invalid', 'id_number' => '8001015009087'],
            ],
        ], [], $owner?->id);

        $this->info("Demo created: template #{$tpl->id}, document #{$doc->id} (no email sent).");

        return self::SUCCESS;
    }
}
