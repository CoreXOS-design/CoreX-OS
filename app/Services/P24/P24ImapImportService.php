<?php

namespace App\Services\P24;

use App\Models\AgencyP24ImapSetting;
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
     * P24 IMAP per-agency (#3) — poll EVERY agency's own active, configured
     * P24 mailbox in turn. A single agency's broken credentials (bad host,
     * expired password, connection timeout) is recorded on THAT agency's
     * settings row and never aborts the run for the others.
     */
    public function importAllAgencies(): array
    {
        $settings = AgencyP24ImapSetting::withoutGlobalScopes()->active()->configured()->get();

        if ($settings->isEmpty()) {
            return ['status' => 'disabled', 'message' => 'No agency has an active, configured P24 IMAP mailbox.'];
        }

        $perAgency = [];
        foreach ($settings as $setting) {
            $perAgency[] = [
                'agency_id' => $setting->agency_id,
                'result'    => $this->importForAgency($setting),
            ];
        }

        return ['status' => 'success', 'agencies' => $perAgency];
    }

    /**
     * Connect to ONE agency's own IMAP mailbox, find unprocessed P24 emails,
     * parse and store — all rows attributed to that agency's own agency_id.
     */
    public function importForAgency(AgencyP24ImapSetting $setting): array
    {
        $agencyId = (int) $setting->agency_id;

        if (! $setting->isConfigured()) {
            return ['status' => 'error', 'message' => 'P24 IMAP mailbox is missing host, username or password.'];
        }

        try {
            $manager = new ClientManager([
                'default' => 'p24',
                'accounts' => [
                    'p24' => [
                        'host'          => $setting->imap_host,
                        'port'          => (int) $setting->imap_port,
                        'protocol'      => 'imap',
                        'encryption'    => $setting->imap_encryption,
                        'username'      => $setting->username,
                        'password'      => $setting->encrypted_password,
                        'validate_cert' => true,
                        'timeout'       => 30,
                    ],
                ],
            ]);

            $client = $manager->account();
            $client->connect();
        } catch (\Throwable $e) {
            Log::error("P24 IMAP connection failed (agency {$agencyId}): {$e->getMessage()}");
            $this->recordFailure($setting, 'connect_failed');
            return ['status' => 'error', 'message' => "IMAP connection failed: {$e->getMessage()}"];
        }

        $stats = ['processed' => 0, 'new' => 0, 'updated' => 0, 'errors' => 0, 'skipped' => 0];

        try {
            $folder = $client->getFolder($setting->imap_folder ?: 'INBOX');

            if (!$folder) {
                $this->recordFailure($setting, 'connect_failed');
                return ['status' => 'error', 'message' => "IMAP folder '{$setting->imap_folder}' not found"];
            }

            // Use this agency's own last successful import date instead of a hardcoded window.
            $lastLog = P24ImportLog::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->where('status', 'success')
                ->orderByDesc('created_at')
                ->first();

            if ($lastLog) {
                // IMAP SINCE is date-only (no time), so subtract 1 day as buffer
                $since = Carbon::parse($lastLog->created_at)->subDay()->startOfDay();
            } else {
                // First run ever for this agency — fall back to 30 days
                $since = Carbon::now()->subDays(30);
            }

            try {
                $messages = $folder->search()
                    ->from('no-reply@property24.com')
                    ->since($since)
                    ->setFetchBody(false) // AT-257: UIDs only; bodies pulled via BODY.PEEK below (never sets \Seen)
                    ->get();
            } catch (\Webklex\PHPIMAP\Exceptions\GetMessagesFailedException $e) {
                // "empty response" from the IMAP server means no matches — treat as zero results, not failure.
                Log::info("P24 IMAP search returned no results (agency {$agencyId}): {$e->getMessage()}");
                $client->disconnect();
                $this->recordSuccess($setting);
                return ['status' => 'success', 'message' => 'No P24 emails found', 'stats' => $stats];
            }

            if ($messages->count() === 0) {
                $client->disconnect();
                $this->recordSuccess($setting);
                return ['status' => 'success', 'message' => 'No P24 emails found', 'stats' => $stats];
            }

            foreach ($messages as $liteMessage) {
                $uid = (string) $liteMessage->getUid();

                // Skip if already processed for THIS agency (before the body fetch —
                // never re-reads a done alert). Scoped by agency_id so two agencies
                // polling different mailboxes never collide on the same UID space.
                if (P24ImportLog::withoutGlobalScopes()->where('agency_id', $agencyId)->where('email_uid', $uid)->exists()) {
                    $stats['skipped']++;
                    continue;
                }

                $subject = '';

                try {
                    // AT-257 — true non-destructive read: BODY.PEEK[], never marks the alert \Seen.
                    $message = \App\Services\Communications\PeekingMessageFetcher::peek($client, (int) $uid);
                    if ($message === null) {
                        $stats['errors']++;
                        continue;
                    }

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

                        $existing = P24Listing::withoutGlobalScopes()
                            ->where('agency_id', $agencyId)
                            ->where('p24_listing_number', $data['p24_listing_number'])
                            ->first();

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
                    Log::error("P24 email parse error (agency {$agencyId}): {$e->getMessage()}", ['uid' => $uid]);
                }
            }
        } catch (\Throwable $e) {
            $client->disconnect();
            $this->recordFailure($setting, 'read_timeout');
            Log::error("P24 IMAP read failed (agency {$agencyId}): {$e->getMessage()}");
            return ['status' => 'error', 'message' => "IMAP read failed: {$e->getMessage()}"];
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

        $this->recordSuccess($setting);

        return ['status' => 'success', 'stats' => $stats];
    }

    private function recordSuccess(AgencyP24ImapSetting $setting): void
    {
        $setting->forceFill([
            'last_polled_at' => now(),
            'last_error' => null,
            'last_error_at' => null,
            'consecutive_failures' => 0,
        ])->save();
    }

    private function recordFailure(AgencyP24ImapSetting $setting, string $reason): void
    {
        $setting->forceFill([
            'last_polled_at' => now(),
            'last_error' => $reason,
            'last_error_at' => now(),
            'consecutive_failures' => $setting->consecutive_failures + 1,
        ])->save();
    }
}
