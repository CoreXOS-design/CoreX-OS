<?php

namespace App\Console\Commands;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * AT-447 — seeds (or removes) a DEMO CoreX contract inside the Platform E-Sign section:
 * one platform template, one platform document with its signature ceremony and one signer.
 * Everything is agency-less (agency_id NULL, template is_platform = 1) — the point is to let
 * you log in as any agency user and confirm none of it is visible, while it is visible to CoreX
 * inside Dev → Platform E-Sign.
 *
 *   php artisan platform-esign:demo            create (idempotent)
 *   php artisan platform-esign:demo --remove   archive (soft-delete) the demo rows and delete the generated view file
 */
class PlatformEsignDemo extends Command
{
    protected $signature = 'platform-esign:demo {--remove : delete the demo contract instead of creating it}';

    protected $description = 'Create or remove a demo CoreX contract in Platform E-Sign (agency-less) for isolation checks';

    public const NAME = 'DEMO — CoreX Subscription Agreement';

    public function handle(): int
    {
        $existing = Template::withoutGlobalScopes()->whereNull('deleted_at')->where('name', self::NAME)->where('is_platform', true)->get();

        if ($this->option('remove')) {
            foreach ($existing as $t) {
                @unlink(resource_path('views/docuperfect/web-templates/cds/template-' . $t->id . '.blade.php'));
                $docs = Document::withoutGlobalScopes()->where('template_id', $t->id)->whereNull('agency_id')->pluck('id');
                $sigs = SignatureTemplate::withoutGlobalScopes()->whereIn('document_id', $docs)->whereNull('agency_id')->pluck('id');
                SignatureRequest::whereIn('signature_template_id', $sigs)->get()->each->delete();
                SignatureTemplate::withoutGlobalScopes()->whereIn('id', $sigs)->get()->each->delete();
                Document::withoutGlobalScopes()->whereIn('id', $docs)->get()->each->delete();
                $t->delete();   // archived (soft delete) — never a hard delete
            }
            $this->info('Demo contract removed (' . $existing->count() . ' template).');

            return self::SUCCESS;
        }

        if ($existing->isNotEmpty()) {
            $this->info('Demo contract already exists (template #' . $existing->first()->id . ').');

            return self::SUCCESS;
        }

        $owner = User::withoutGlobalScopes()->where('role', 'super_admin')->orderBy('id')->first();
        if (!$owner) {
            $this->error('No owner (super_admin) user found to own the demo.');

            return self::FAILURE;
        }

        $template = Template::withoutGlobalScopes()->create([
            'name' => self::NAME, 'template_type' => 'cds', 'render_type' => 'web', 'page_count' => 1,
            'is_esign' => true, 'is_global' => false, 'is_platform' => true, 'agency_id' => null,
            'category' => 'sales', 'party_mode' => 'shared', 'allowed_delivery_modes' => 'esign',
            'security_tier' => 'enhanced', 'signing_parties' => ['owner_party', 'agent'],
            'owner_id' => $owner->id, 'fields_json' => [],
        ]);

        // A real compiled view, like every builder-generated web template, so the send wizard offers it.
        $view = 'docuperfect.web-templates.cds.template-' . $template->id;
        file_put_contents(resource_path('views/docuperfect/web-templates/cds/template-' . $template->id . '.blade.php'), $this->bladeBody());
        $template->update([
            'blade_view' => $view,
            'editor_state' => ['tagged_html' => '<div class="corex-h1">CoreX Subscription Agreement (DEMO)</div>', 'tags' => [], 'mappings' => []],
        ]);
        \Illuminate\Support\Facades\Artisan::call('view:clear');

        $document = Document::withoutGlobalScopes()->create([
            'name' => self::NAME, 'template_id' => $template->id, 'owner_id' => $owner->id, 'agency_id' => null,
        ]);

        $sig = SignatureTemplate::withoutGlobalScopes()->create([
            'document_id' => $document->id, 'agency_id' => null, 'status' => SignatureTemplate::STATUS_SIGNING,
            'created_by' => $owner->id,
        ]);

        SignatureRequest::create([
            'signature_template_id' => $sig->id, 'party_role' => 'seller', 'signer_name' => 'Demo Principal',
            'signer_email' => 'demo.principal@example.invalid', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(14), 'status' => 'pending',
        ]);

        $this->info("Demo contract created: template #{$template->id}, document #{$document->id}, signature ceremony #{$sig->id}.");

        return self::SUCCESS;
    }

    /** The demo document body: plain contract text plus the standard signature block (Seller = agency principal, Agent = CoreX). */
    private function bladeBody(): string
    {
        return <<<'BLADE'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CoreX Subscription Agreement (DEMO)</title>
    <link href="/css/corex-document.css" rel="stylesheet">
</head>
<body>
<div class="corex-document-wrapper">
<div class="corex-page">

<div class="corex-h1">CoreX Subscription Agreement (DEMO)</div>
<p>This is a demonstration contract between CoreX and the agency named below. It exists only inside Platform E-Sign, belongs to no agency, and is used to try out the send and sign process.</p>
<table class="corex-table"><thead><tr><th colspan="2">AGREEMENT</th></tr></thead><tbody>
<tr><td>Subscription start date</td><td>1 November</td></tr>
<tr><td>Take-on month</td><td>No charge for the take-on month. Billing starts on the start date above.</td></tr>
<tr><td>Payment</td><td>By debit order.</td></tr>
</tbody></table>
<p>By signing, the agency principal confirms that they are authorised to bind the agency to this agreement.</p>
<div class="corex-signature-section"><div class="corex-signature-section-title">THUS DONE AND SIGNED</div></div>

@include("docuperfect.web-templates.components.signature-block", ["parties" => ["Seller", "Agent"]])

</div>
</div>

</body>
</html>
BLADE;
    }
}
