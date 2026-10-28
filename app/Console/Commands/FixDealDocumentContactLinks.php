<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\Audit\ContactAuditService;
use App\Services\DealV2\DealDocumentPartyResolver;
use App\Support\Audit\AuditContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Remove the contact links that the old deal-document filing fanned out to everyone linked to the
 * property (buyers, leads, other buyers) instead of the deal's own parties.
 *
 * A link row is removed only when ALL of these hold:
 *   - the document was filed from a DR1 deal (source_type deal / inbound_email / proforma_generated /
 *     work_authorisation, source_id = the deal) and is not deleted;
 *   - the link carries no party_role (the fan-out never set one; every deliberate link — e-sign
 *     signers, splitter, the new filing — does) and was written within 5 seconds of the document;
 *   - the contact is not one of the deal's parties on the side the document belongs to
 *     (reason not_deal_party, or wrong_side for a deal party who got the other side's document),
 *     and is not the proforma's own addressee.
 * A deal with no recorded parties never loses a link to a seller-side contact of the property.
 *
 *   --dry-run (default)  report only
 *   --apply              back the rows up to JSON, remove them, write one contact audit row each
 */
class FixDealDocumentContactLinks extends Command
{
    protected $signature = 'deal-docs:fix-contact-links {--dry-run : Report only (the default)} {--apply : Back up, remove the wrong links and audit them}';

    protected $description = 'Remove deal-document contact links that went to contacts who are not party to the deal';

    private const SOURCES = ['deal', 'inbound_email', 'proforma_generated', 'work_authorisation'];
    private const SELLER_SIDE = ['owner', 'seller', 'landlord', 'lessor'];

    public function handle(DealDocumentPartyResolver $resolver): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose --dry-run or --apply, not both.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');

        $remove  = [];   // each: link row + reason + context
        $stats   = [];   // source => [scanned, kept, not_deal_party, wrong_side, kept_no_parties]
        $parties = [];   // deal id => parties
        $sides   = [];

        $slugs = DB::table('document_types')->pluck('slug', 'id');

        $q = DB::table('document_contacts as dc')
            ->join('documents as d', 'd.id', '=', 'dc.document_id')
            ->join('deals as de', 'de.id', '=', 'd.source_id')
            ->whereNull('d.deleted_at')
            ->whereIn('d.source_type', self::SOURCES)
            ->whereNull('dc.party_role')
            ->whereRaw('ABS(TIMESTAMPDIFF(SECOND, d.created_at, dc.created_at)) <= 5')
            ->orderBy('dc.id')
            ->select('dc.id as link_id', 'dc.document_id', 'dc.contact_id', 'dc.created_at as linked_at',
                'd.source_type', 'd.source_id as deal_id', 'd.original_name', 'd.document_type_id');

        $q->chunk(500, function ($rows) use (&$remove, &$stats, &$parties, &$sides, $slugs, $resolver) {
            foreach ($rows as $r) {
                $s = &$stats[$r->source_type];
                $s ??= ['scanned' => 0, 'kept' => 0, 'not_deal_party' => 0, 'wrong_side' => 0, 'kept_no_parties' => 0];
                $s['scanned']++;

                $deal = (int) $r->deal_id;
                $parties[$deal] ??= $resolver->parties($deal);
                $side = $sides[$r->document_id] ??= $resolver->sideFor($slugs[$r->document_type_id] ?? null, $r->original_name);
                $allowed = $side === DealDocumentPartyResolver::BOTH
                    ? $parties[$deal]
                    : array_filter($parties[$deal], fn ($role) => $role === $side);

                if (isset($allowed[(int) $r->contact_id])) {
                    $s['kept']++;
                    continue;
                }
                if ($r->source_type === 'proforma_generated' && DB::table('proforma_invoices')
                        ->where('document_id', $r->document_id)->where('issued_to_contact_id', $r->contact_id)->exists()) {
                    $s['kept']++;
                    continue;
                }
                if ($parties[$deal] === [] && $this->isSellerSide((int) $r->document_id, (int) $r->contact_id)) {
                    $s['kept_no_parties']++;
                    continue;
                }

                $reason = isset($parties[$deal][(int) $r->contact_id]) ? 'wrong_side' : 'not_deal_party';
                $s[$reason]++;
                $remove[] = (array) $r + ['reason' => $reason, 'side' => $side];
            }
            unset($s);
        });

        $this->summary($stats, $remove);

        if (! $apply) {
            $this->info('Dry run — nothing written. Re-run with --apply to back up and remove.');

            return self::SUCCESS;
        }
        if ($remove === []) {
            $this->info('Nothing to remove.');

            return self::SUCCESS;
        }

        // Backup first — every row to be removed, complete enough to put back.
        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);
        $file = $dir . '/deal-docs-contact-links-' . now()->format('Ymd-His') . '.json';
        $ids  = array_column($remove, 'link_id');
        $full = DB::table('document_contacts')->whereIn('id', $ids)->get()->keyBy('id');
        File::put($file, json_encode([
            'command'   => 'deal-docs:fix-contact-links',
            'taken_at'  => now()->toIso8601String(),
            'restore'   => 'Re-insert each "row" into document_contacts to put a link back.',
            'rows'      => array_map(fn ($r) => [
                'row'           => $full[$r['link_id']] ?? null,
                'reason'        => $r['reason'],
                'source_type'   => $r['source_type'],
                'deal_id'       => $r['deal_id'],
                'document_name' => $r['original_name'],
            ], $remove),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line("Backup written: {$file}");

        AuditContext::setSource('deal-docs:fix-contact-links', 'system');
        $audit = app(ContactAuditService::class);
        $done  = 0;

        foreach (array_chunk($remove, 200) as $chunk) {
            DB::transaction(function () use ($chunk, $audit, &$done) {
                DB::table('document_contacts')->whereIn('id', array_column($chunk, 'link_id'))->delete();
                foreach ($chunk as $r) {
                    $contact = Contact::withoutGlobalScopes()->find($r['contact_id']);
                    if ($contact) {
                        $audit->log($contact, 'contact_document', 'document_unlinked', null,
                            oldValues: ['document_id' => $r['document_id']],
                            metadata: ['document_name' => $r['original_name'], 'deal_id' => $r['deal_id'], 'reason' => $r['reason'], 'source_type' => $r['source_type']],
                            humanSummary: 'Deal document "' . $r['original_name'] . '" removed from this contact — they are not a party to that deal (clean-up)');
                    }
                    $done++;
                }
            });
        }
        $this->info("Removed {$done} links.");

        return self::SUCCESS;
    }

    private function isSellerSide(int $documentId, int $contactId): bool
    {
        return DB::table('document_properties as dp')
            ->join('contact_property as cp', 'cp.property_id', '=', 'dp.property_id')
            ->where('dp.document_id', $documentId)->where('cp.contact_id', $contactId)
            ->whereNull('cp.deleted_at')->whereIn('cp.role', self::SELLER_SIDE)->exists();
    }

    private function summary(array $stats, array $remove): void
    {
        $rows = [];
        $tot  = ['scanned' => 0, 'kept' => 0, 'not_deal_party' => 0, 'wrong_side' => 0, 'kept_no_parties' => 0];
        foreach ($stats as $source => $s) {
            $rows[] = [$source, $s['scanned'], $s['kept'], $s['not_deal_party'], $s['wrong_side'], $s['kept_no_parties']];
            foreach ($tot as $k => $_) {
                $tot[$k] += $s[$k];
            }
        }
        $rows[] = ['TOTAL', $tot['scanned'], $tot['kept'], $tot['not_deal_party'], $tot['wrong_side'], $tot['kept_no_parties']];
        $this->table(['Source', 'Fan-out links', 'Kept (right party)', 'Remove: not a deal party', 'Remove: wrong side', 'Kept: deal has no parties'], $rows);
        $this->line(sprintf('To remove: %d links, %d contacts, %d documents.', count($remove),
            count(array_unique(array_column($remove, 'contact_id'))), count(array_unique(array_column($remove, 'document_id')))));
    }
}
