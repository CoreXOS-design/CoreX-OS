<?php

namespace App\Services\PrivateProperty;

use App\Models\PpAlertListing;
use App\Models\PpAlertPriceChange;
use App\Models\Scopes\AgencyScope;
use App\Services\Prospecting\AlertPropertyLinker;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webklex\PHPIMAP\ClientManager;

/**
 * Private Property alert e-mails -> pp_alert_listings (property-identity.md section 15, C1). The twin of P24ImapImportService: the same
 * mailbox setup (services.pp_imap falls back to the Property24 mailbox values, so one inbox can serve both), the same non-destructive
 * read, and every row is tied to its property file through the door (AlertPropertyLinker) as it is saved.
 *
 * `storeRows()` is the part tests drive without a mailbox.
 */
class PpImapImportService
{
    public function __construct(private readonly PpEmailParserService $parser)
    {
    }

    public function import(): array
    {
        $config = config('services.pp_imap');
        if (! $config['enabled']) {
            return ['status' => 'disabled', 'message' => 'Private Property import is disabled. Set PP_IMPORT_ENABLED=true in .env'];
        }
        if (empty($config['host']) || empty($config['username']) || empty($config['password'])) {
            return ['status' => 'error', 'message' => 'Private Property IMAP credentials are not configured (PP_IMAP_* or the P24_IMAP_* mailbox).'];
        }
        $agencyId = $this->resolveAgencyId();
        if (! $agencyId) {
            return ['status' => 'error', 'message' => 'No agency to attribute imported Private Property alerts to.'];
        }

        try {
            $manager = new ClientManager(['default' => 'pp', 'accounts' => ['pp' => [
                'host' => $config['host'], 'port' => (int) $config['port'], 'protocol' => 'imap', 'encryption' => $config['encryption'],
                'username' => $config['username'], 'password' => $config['password'], 'validate_cert' => true, 'timeout' => 30,
            ]]]);
            $client = $manager->account();
            $client->connect();
        } catch (\Throwable $e) {
            Log::error("Private Property IMAP connection failed: {$e->getMessage()}");

            return ['status' => 'error', 'message' => "IMAP connection failed: {$e->getMessage()}"];
        }

        $stats = ['processed' => 0, 'new' => 0, 'updated' => 0, 'errors' => 0, 'skipped' => 0, 'unreadable' => 0];

        try {
            $folder = $client->getFolder($config['folder']);
            if (! $folder) {
                return ['status' => 'error', 'message' => "IMAP folder '{$config['folder']}' not found"];
            }

            $lastLog = DB::table('pp_import_log')->where('agency_id', $agencyId)->where('status', 'success')->where('email_uid', 'not like', 'run_%')->orderByDesc('created_at')->first();
            $since = $lastLog ? Carbon::parse($lastLog->created_at)->subDay()->startOfDay() : Carbon::now()->subDays(30);

            try {
                $messages = $folder->search()->from((string) $config['from'])->since($since)->setFetchBody(false)->get();
            } catch (\Webklex\PHPIMAP\Exceptions\GetMessagesFailedException $e) {
                Log::info("Private Property IMAP search returned no results: {$e->getMessage()}");
                $client->disconnect();

                return ['status' => 'success', 'message' => 'No Private Property emails found', 'stats' => $stats];
            }

            foreach ($messages as $liteMessage) {
                $uid = (string) $liteMessage->getUid();
                if (DB::table('pp_import_log')->where('agency_id', $agencyId)->where('email_uid', $uid)->exists()) {
                    $stats['skipped']++;
                    continue;
                }
                $subject = '';
                try {
                    $message = \App\Services\Communications\PeekingMessageFetcher::peek($client, (int) $uid);
                    if ($message === null) {
                        $stats['errors']++;
                        continue;
                    }
                    $subject = (string) $message->getSubject();
                    $date = $message->getDate()->toDate();
                    $body = $message->hasHTMLBody() ? $message->getHTMLBody() : $message->getTextBody();

                    $rows = $this->parser->parse($body, $subject);
                    if ($rows === []) {
                        // Not marked as processed: once the reader is corrected the mail is read again.
                        $stats['unreadable']++;
                        Log::warning('Private Property alert e-mail yielded no adverts (reader may not match this format)', ['uid' => $uid, 'subject' => Str::limit($subject, 120)]);
                        continue;
                    }

                    [$new, $updated] = $this->storeRows($agencyId, $rows);
                    DB::table('pp_import_log')->insert([
                        'agency_id' => $agencyId, 'email_uid' => $uid, 'email_subject' => Str::limit($subject, 250), 'email_date' => $date,
                        'listings_found' => count($rows), 'listings_new' => $new, 'listings_updated' => $updated, 'status' => 'success',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $stats['processed']++;
                    $stats['new'] += $new;
                    $stats['updated'] += $updated;
                } catch (\Throwable $e) {
                    DB::table('pp_import_log')->insert([
                        'agency_id' => $agencyId, 'email_uid' => $uid, 'email_subject' => Str::limit($subject ?: 'Unknown', 250), 'email_date' => now(),
                        'status' => 'error', 'error_message' => Str::limit($e->getMessage(), 500), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $stats['errors']++;
                    Log::error("Private Property email parse error: {$e->getMessage()}", ['uid' => $uid]);
                }
            }
        } finally {
            $client->disconnect();
        }

        DB::table('pp_import_log')->insert([
            'agency_id' => $agencyId, 'email_uid' => 'run_' . now()->timestamp,
            'email_subject' => sprintf('Import run: %d processed, %d new, %d updated, %d skipped, %d unreadable, %d errors', $stats['processed'], $stats['new'], $stats['updated'], $stats['skipped'], $stats['unreadable'], $stats['errors']),
            'email_date' => now(), 'listings_found' => $stats['processed'], 'listings_new' => $stats['new'], 'listings_updated' => $stats['updated'],
            'status' => 'success', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['status' => 'success', 'stats' => $stats];
    }

    /**
     * Save parsed advert rows for one agency: a new advert is created, a known one gets its price change / last-seen date, and every row is
     * tied to its property file through the door. Adverts without a price are skipped (as the Property24 import does).
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array{0:int,1:int} [new, updated]
     */
    public function storeRows(int $agencyId, array $rows): array
    {
        $new = $updated = 0;
        foreach ($rows as $data) {
            if (empty($data['pp_listing_number']) || empty($data['asking_price'])) {
                continue;
            }
            $existing = PpAlertListing::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('pp_listing_number', $data['pp_listing_number'])->first();

            if ($existing) {
                if ((float) $existing->asking_price !== (float) $data['asking_price']) {
                    PpAlertPriceChange::create(['listing_id' => $existing->id, 'old_price' => $existing->asking_price, 'new_price' => $data['asking_price'], 'change_date' => now()->toDateString()]);
                    $existing->asking_price = $data['asking_price'];
                }
                $existing->last_seen_date = now()->toDateString();
                $existing->times_seen = $existing->times_seen + 1;
                foreach (['suburb', 'property_type', 'bedrooms', 'bathrooms', 'garages', 'pp_url'] as $field) {
                    if (empty($existing->$field) && ! empty($data[$field])) {
                        $existing->$field = $data[$field];
                    }
                }
                $existing->save();
                $alert = $existing;
                $updated++;
            } else {
                $alert = PpAlertListing::create([
                    'agency_id' => $agencyId, 'pp_listing_number' => $data['pp_listing_number'], 'asking_price' => $data['asking_price'],
                    'original_price' => $data['asking_price'], 'property_type' => $data['property_type'] ?? null, 'suburb' => $data['suburb'] ?? null,
                    'bedrooms' => $data['bedrooms'] ?? null, 'bathrooms' => $data['bathrooms'] ?? null, 'garages' => $data['garages'] ?? null,
                    'is_mandated' => $data['is_mandated'] ?? false, 'pp_url' => $data['pp_url'] ?? null,
                    'first_seen_date' => now()->toDateString(), 'last_seen_date' => now()->toDateString(), 'times_seen' => 1,
                ]);
                $new++;
            }
            try {
                app(AlertPropertyLinker::class)->linkModel($alert);
            } catch (\Throwable $e) {
                Log::warning('Private Property alert could not be tied to a property file', ['alert_id' => $alert->id, 'error' => $e->getMessage()]);
            }
        }

        return [$new, $updated];
    }

    private function resolveAgencyId(): ?int
    {
        $configured = (int) config('services.pp_imap.agency_id');
        if ($configured > 0 && DB::table('agencies')->where('id', $configured)->exists()) {
            return $configured;
        }
        // the same agency the Property24 mailbox import is attributed to
        $id = DB::table('agencies')->whereNotNull('p24_agency_id')->orderBy('id')->value('id');

        return $id ? (int) $id : null;
    }
}
