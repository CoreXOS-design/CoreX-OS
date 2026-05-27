<?php

namespace App\Services\P24;

use App\Models\P24Listing;
use App\Models\P24PriceChange;
use App\Models\P24ImportLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Webklex\PHPIMAP\ClientManager;

class P24ImapImportService
{
    private P24EmailParserService $parser;

    public function __construct(P24EmailParserService $parser)
    {
        $this->parser = $parser;
    }

    /**
     * Connect to IMAP, find unprocessed P24 emails, parse and store.
     */
    public function import(): array
    {
        $config = config('services.p24_imap');

        if (!$config['enabled']) {
            return ['status' => 'disabled', 'message' => 'P24 import is disabled. Set P24_IMPORT_ENABLED=true in .env'];
        }

        if (empty($config['host']) || empty($config['username']) || empty($config['password'])) {
            return ['status' => 'error', 'message' => 'P24 IMAP credentials not configured in .env'];
        }

        $agencyId = $this->resolveAgencyId();
        if (!$agencyId) {
            return ['status' => 'error', 'message' => 'No agency with p24_agency_id configured — cannot attribute imported listings.'];
        }

        try {
            $manager = new ClientManager([
                'default' => 'p24',
                'accounts' => [
                    'p24' => [
                        'host'          => $config['host'],
                        'port'          => (int) $config['port'],
                        'protocol'      => 'imap',
                        'encryption'    => $config['encryption'],
                        'username'      => $config['username'],
                        'password'      => $config['password'],
                        'validate_cert' => true,
                        'timeout'       => 30,
                    ],
                ],
            ]);

            $client = $manager->account();
            $client->connect();
        } catch (\Throwable $e) {
            Log::error("P24 IMAP connection failed: {$e->getMessage()}");
            return ['status' => 'error', 'message' => "IMAP connection failed: {$e->getMessage()}"];
        }

        $stats = ['processed' => 0, 'new' => 0, 'updated' => 0, 'errors' => 0, 'skipped' => 0];

        try {
            $folder = $client->getFolder($config['folder']);

            if (!$folder) {
                return ['status' => 'error', 'message' => "IMAP folder '{$config['folder']}' not found"];
            }

            // Use last successful import date instead of hardcoded 30 days
            $lastLog = P24ImportLog::where('status', 'success')
                ->orderByDesc('created_at')
                ->first();

            if ($lastLog) {
                // IMAP SINCE is date-only (no time), so subtract 1 day as buffer
                $since = Carbon::parse($lastLog->created_at)->subDay()->startOfDay();
            } else {
                // First run ever — fall back to 30 days
                $since = Carbon::now()->subDays(30);
            }

            try {
                $messages = $folder->search()
                    ->from('no-reply@property24.com')
                    ->since($since)
                    ->get();
            } catch (\Webklex\PHPIMAP\Exceptions\GetMessagesFailedException $e) {
                // "empty response" from the IMAP server means no matches — treat as zero results, not failure.
                Log::info("P24 IMAP search returned no results: {$e->getMessage()}");
                $client->disconnect();
                return ['status' => 'success', 'message' => 'No P24 emails found', 'stats' => $stats];
            }

            if ($messages->count() === 0) {
                $client->disconnect();
                return ['status' => 'success', 'message' => 'No P24 emails found', 'stats' => $stats];
            }

            foreach ($messages as $message) {
                $uid = (string) $message->getUid();

                // Skip if already processed
                if (P24ImportLog::where('email_uid', $uid)->exists()) {
                    $stats['skipped']++;
                    continue;
                }

                $subject = '';

                try {
                    $subject = (string) $message->getSubject();
                    $date = $message->getDate()->toDate();

                    $body = $message->hasHTMLBody()
                        ? $message->getHTMLBody()
                        : $message->getTextBody();

                    $parsedListings = $this->parser->parse($body, $subject);

                    $newCount = 0;
                    $updatedCount = 0;

                    foreach ($parsedListings as $data) {
                        if (empty($data['p24_listing_number']) || empty($data['asking_price'])) {
                            continue;
                        }

                        $existing = P24Listing::where('p24_listing_number', $data['p24_listing_number'])->first();

                        if ($existing) {
                            // Check for price change
                            if ((float) $existing->asking_price !== (float) $data['asking_price']) {
                                P24PriceChange::create([
                                    'listing_id' => $existing->id,
                                    'old_price' => $existing->asking_price,
                                    'new_price' => $data['asking_price'],
                                    'change_date' => now()->toDateString(),
                                ]);
                                $existing->asking_price = $data['asking_price'];
                            }

                            $existing->last_seen_date = now()->toDateString();
                            $existing->times_seen = $existing->times_seen + 1;

                            // Fill in any null fields with new data
                            foreach (['suburb', 'property_type', 'bedrooms', 'bathrooms', 'garages', 'p24_url'] as $field) {
                                if (empty($existing->$field) && !empty($data[$field])) {
                                    $existing->$field = $data[$field];
                                }
                            }

                            $existing->save();
                            $updatedCount++;
                        } else {
                            P24Listing::create([
                                'agency_id' => $agencyId,
                                'p24_listing_number' => $data['p24_listing_number'],
                                'asking_price' => $data['asking_price'],
                                'property_type' => $data['property_type'],
                                'suburb' => $data['suburb'],
                                'bedrooms' => $data['bedrooms'],
                                'bathrooms' => $data['bathrooms'],
                                'garages' => $data['garages'],
                                'is_mandated' => $data['is_mandated'] ?? false,
                                'p24_url' => $data['p24_url'],
                                'first_seen_date' => now()->toDateString(),
                                'last_seen_date' => now()->toDateString(),
                                'original_price' => $data['asking_price'],
                                'times_seen' => 1,
                            ]);
                            $newCount++;
                        }
                    }

                    P24ImportLog::create([
                        'agency_id' => $agencyId,
                        'email_uid' => $uid,
                        'email_subject' => Str::limit($subject, 250),
                        'email_date' => $date,
                        'listings_found' => count($parsedListings),
                        'listings_new' => $newCount,
                        'listings_updated' => $updatedCount,
                        'status' => 'success',
                    ]);

                    $stats['processed']++;
                    $stats['new'] += $newCount;
                    $stats['updated'] += $updatedCount;
                } catch (\Throwable $e) {
                    P24ImportLog::create([
                        'agency_id' => $agencyId,
                        'email_uid' => $uid,
                        'email_subject' => Str::limit($subject ?: 'Unknown', 250),
                        'email_date' => now(),
                        'status' => 'error',
                        'error_message' => Str::limit($e->getMessage(), 500),
                    ]);
                    $stats['errors']++;
                    Log::error("P24 email parse error: {$e->getMessage()}", ['uid' => $uid]);
                }
            }
        } finally {
            $client->disconnect();
        }

        // Always log a run-level summary so "Last Import" updates on every run
        P24ImportLog::create([
            'agency_id'        => $agencyId,
            'email_uid'        => 'run_' . now()->timestamp,
            'email_subject'    => sprintf('Import run: %d processed, %d new, %d updated, %d skipped, %d errors',
                $stats['processed'], $stats['new'], $stats['updated'], $stats['skipped'], $stats['errors']),
            'email_date'       => now(),
            'listings_found'   => $stats['processed'],
            'listings_new'     => $stats['new'],
            'listings_updated' => $stats['updated'],
            'status'           => 'success',
        ]);

        return ['status' => 'success', 'stats' => $stats];
    }

    private function resolveAgencyId(): ?int
    {
        $id = \Illuminate\Support\Facades\DB::table('agencies')
            ->whereNotNull('p24_agency_id')
            ->orderBy('id')
            ->value('id');

        return $id ? (int) $id : null;
    }
}
