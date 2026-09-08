<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\CommunicationMailbox;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationPending;
use App\Services\Communications\EmailArchiveIngestor;
use App\Services\Communications\ImapMailboxPoller;
use App\Services\Communications\ImapPollTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\Connection\Protocols\Response;

/**
 * 2026-09-08 (Johan) — deterministic proof for the UID-based incremental
 * poller, replacing the same-day timestamp+overlap design he rejected as
 * "naming a gap instead of fixing it." UIDs make every one of these
 * scenarios exactly reproducible — no live timing, no waiting for a real
 * message to land in a real inbox at the right microsecond.
 *
 * The fake connection implements the full ProtocolInterface (required by
 * PHP's type system — Client::getConnection(): ProtocolInterface) but only
 * fetch()/flags()/folderStatus()/search() do anything real; everything else
 * is unreachable by the poller and stubbed to satisfy the interface only.
 */
final class ImapUidIncrementalPollTest extends TestCase
{
    use RefreshDatabase;

    private CommunicationMailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        $agency = \App\Models\Agency::create(['name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8)]);
        $this->mailbox = CommunicationMailbox::create([
            'agency_id' => $agency->id, 'email_address' => 'office@agency.test',
            'imap_host' => 'imap.agency.test', 'imap_port' => 993, 'username' => 'office@agency.test',
            'encrypted_password' => 'secret', 'poll_inbox' => true, 'poll_sent' => false,
            'poll_interval_minutes' => 15, 'active' => true,
        ]);
    }

    /** Builds a real (never-connected) Client subclass whose getConnection() returns our fake wire. */
    private function fakeClient(FakeImapConnection $conn): Client
    {
        return new class($conn) extends Client {
            public function __construct(private FakeImapConnection $conn)
            {
                // Same account-array shape ImapMailboxPoller::connect() builds via
                // ClientManager — Client::setConfig() needs 'default' + a matching
                // 'accounts.<name>' entry, an empty array is not enough.
                parent::__construct(new Config([
                    'default' => 'fake',
                    'accounts' => ['fake' => [
                        'host' => 'fake', 'port' => 993, 'protocol' => 'imap',
                        'encryption' => 'ssl', 'username' => 'fake', 'password' => 'fake',
                        'validate_cert' => true, 'timeout' => 5,
                    ]],
                    'masks' => [
                        'message' => \Webklex\PHPIMAP\Support\Masks\MessageMask::class,
                        'attachment' => \Webklex\PHPIMAP\Support\Masks\AttachmentMask::class,
                    ],
                    // Message::make() -> parseHeader() does $this->options =
                    // $this->config->get('options') unconditionally -- a missing key
                    // here throws the exact same class of typed-property TypeError
                    // one step later, deep inside header parsing rather than at
                    // construction. Same shape as webklex's default config.
                    'options' => [
                        'delimiter' => '/',
                        'fetch' => \Webklex\PHPIMAP\IMAP::FT_PEEK,
                        'sequence' => \Webklex\PHPIMAP\IMAP::ST_UID,
                        'fetch_body' => true,
                        'fetch_flags' => true,
                        'soft_fail' => false,
                        'rfc822' => true,
                        'debug' => false,
                        'unescaped_search_dates' => false,
                        'uid_cache' => true,
                        'boundary' => '/boundary=(.*?(?=;)|(.*))/i',
                        'message_key' => 'list',
                        'fetch_order' => 'asc',
                        'dispositions' => ['attachment', 'inline'],
                        'common_folders' => [
                            'root' => 'INBOX', 'junk' => 'INBOX/Junk', 'draft' => 'INBOX/Drafts',
                            'sent' => 'INBOX/Sent', 'trash' => 'INBOX/Trash',
                        ],
                        'open' => [],
                    ],
                    'decoding' => [
                        'options' => ['header' => 'utf-8', 'message' => 'utf-8', 'attachment' => 'utf-8'],
                        'decoder' => [
                            'header' => \Webklex\PHPIMAP\Decoder\HeaderDecoder::class,
                            'message' => \Webklex\PHPIMAP\Decoder\MessageDecoder::class,
                            'attachment' => \Webklex\PHPIMAP\Decoder\AttachmentDecoder::class,
                        ],
                    ],
                    // Client::setEventsFromConfig() requires this key present (typed array
                    // property, no default) -- same shape as webklex's own published default
                    // config (vendor/webklex/php-imap/src/config/imap.php).
                    'events' => [
                        'message' => [
                            'new' => \Webklex\PHPIMAP\Events\MessageNewEvent::class,
                            'moved' => \Webklex\PHPIMAP\Events\MessageMovedEvent::class,
                            'copied' => \Webklex\PHPIMAP\Events\MessageCopiedEvent::class,
                            'deleted' => \Webklex\PHPIMAP\Events\MessageDeletedEvent::class,
                            'restored' => \Webklex\PHPIMAP\Events\MessageRestoredEvent::class,
                        ],
                        'folder' => [
                            'new' => \Webklex\PHPIMAP\Events\FolderNewEvent::class,
                            'moved' => \Webklex\PHPIMAP\Events\FolderMovedEvent::class,
                            'deleted' => \Webklex\PHPIMAP\Events\FolderDeletedEvent::class,
                        ],
                        'flag' => [
                            'new' => \Webklex\PHPIMAP\Events\FlagNewEvent::class,
                            'deleted' => \Webklex\PHPIMAP\Events\FlagDeletedEvent::class,
                        ],
                    ],
                ]));
            }

            public function getConnection(): ProtocolInterface
            {
                return $this->conn;
            }

            public function getFolderPath(): ?string
            {
                return 'INBOX';
            }

            // Message::make() unconditionally calls peek() at the end, which --
            // when the raw flags don't already show \Seen -- calls unsetFlag(),
            // which calls the REAL Client::openFolder(), which calls
            // checkConnection(), which (since our fake never populates the real
            // $connection property) tries to open a genuine socket to host
            // "fake" and throws ConnectionFailedException("connection failed").
            // Route it at our fake wire instead, exactly like getConnection().
            public function openFolder(string $folder_path, bool $force_select = false): array
            {
                $this->conn->selectFolder($folder_path);
                return [];
            }
        };
    }

    /** Minimal fake "lite message" — all poll() needs from a search result before the header peek. */
    private function liteMessage(int $uid): object
    {
        return new class($uid) {
            public function __construct(private int $uid) {}
            public function getUid(): int { return $this->uid; }
        };
    }

    private function rfc822Header(string $messageId, string $from, string $subject): string
    {
        return "From: {$from}\r\nTo: office@agency.test\r\nSubject: {$subject}\r\nMessage-ID: <{$messageId}>\r\nDate: " . now()->toRfc2822String() . "\r\n\r\n";
    }

    /** Builds a poller whose connect() returns a fake client wired to the given fake folder/UIDVALIDITY. */
    private function pollerWithFakeFolder(FakeImapFolder $folder): ImapMailboxPoller
    {
        return new class($folder, app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            public function __construct(private FakeImapFolder $fakeFolder, EmailArchiveIngestor $ingestor)
            {
                parent::__construct($ingestor);
            }

            public function connect(CommunicationMailbox $mailbox)
            {
                return app('test.fakeImapClient');
            }

            protected function resolveFolder($client, array $paths): ?object
            {
                return $this->fakeFolder;
            }
        };
    }

    /** Single scenario: two polls, second UID arrives between them, collected exactly once. */
    public function test_a_message_arriving_between_polls_is_collected_on_the_next_poll_exactly_once(): void
    {
        $conn = new FakeImapConnection(uidValidity: 111, uidNext: 200, headers: [
            100 => $this->rfc822Header('msg-100@test', 'newclient@example.test', 'First enquiry'),
            101 => $this->rfc822Header('msg-101@test', 'newclient@example.test', 'Second enquiry, arrived after poll 1'),
        ]);
        app()->instance('test.fakeImapClient', $this->fakeClient($conn));
        $folder = new FakeImapFolder($conn, uidsAvailableNow: [100]); // only 100 exists "at the time" of poll 1

        $poller = $this->pollerWithFakeFolder($folder);
        $poller->poll($this->mailbox);

        $this->mailbox->refresh();
        $this->assertSame(100, $this->mailbox->last_uid_seen, 'poll 1 only saw UID 100 — cursor must reflect exactly that, not "now"');
        $this->assertSame('SINCE', $conn->lastSearchTerms[0] ?? null, 'first poll (no stored UID) should have used the date backfill, not a UID range');

        // "Arrives during/after poll 1" -- from the fake's perspective this is the same
        // mechanism either way: whatever wasn't in poll 1's result set is collected on
        // whichever poll's search DOES return it. That is the point of using UIDs.
        $folder->uidsAvailableNow = [100, 101];

        $poller2 = $this->pollerWithFakeFolder($folder);
        $poller2->poll($this->mailbox);

        $this->mailbox->refresh();
        $this->assertSame(101, $this->mailbox->last_uid_seen, 'poll 2 must have advanced to 101');

        $pending = CommunicationPending::where('agency_id', $this->mailbox->agency_id)->get();
        $this->assertSame(2, $pending->count(), 'both messages held exactly once each -- no loss, no duplicate');
        $subjects = $pending->pluck('subject')->sort()->values()->all();
        $this->assertSame(['First enquiry', 'Second enquiry, arrived after poll 1'], $subjects);
    }

    public function test_rerunning_a_poll_immediately_collects_nothing_new_and_creates_no_duplicates(): void
    {
        $conn = new FakeImapConnection(uidValidity: 222, uidNext: 105, headers: [
            100 => $this->rfc822Header('msg-a@test', 'client@example.test', 'Only message'),
        ]);
        app()->instance('test.fakeImapClient', $this->fakeClient($conn));
        $folder = new FakeImapFolder($conn, uidsAvailableNow: [100]);

        $this->pollerWithFakeFolder($folder)->poll($this->mailbox);
        $this->mailbox->refresh();
        $this->assertSame(100, $this->mailbox->last_uid_seen);
        $this->assertSame(1, CommunicationPending::count());

        // Re-run immediately, same server state. Cursor is now 100, so the search is
        // UID 101:* -- the fake correctly has nothing to return above 100.
        $this->pollerWithFakeFolder($folder)->poll($this->mailbox);
        $this->mailbox->refresh();
        $this->assertSame(100, $this->mailbox->last_uid_seen, 'unchanged -- nothing new existed');
        $this->assertSame(['UID', '101:*'], $conn->lastSearchTerms);
        $this->assertSame(1, CommunicationPending::count(), 'still exactly one -- no duplicate created');
    }

    public function test_uidvalidity_mismatch_forces_a_bounded_resync_not_a_silent_continue(): void
    {
        // Simulate: this mailbox already has a stored cursor from a PREVIOUS
        // uidvalidity (200 under validity 111) -- then the folder gets rebuilt and
        // now reports a DIFFERENT uidvalidity (999). Every UID under 111 is now
        // meaningless against the new numbering.
        $this->mailbox->forceFill(['last_uid_seen' => 200, 'inbox_uid_validity' => 111])->save();

        $conn = new FakeImapConnection(uidValidity: 999, uidNext: 50, headers: [
            10 => $this->rfc822Header('msg-post-rebuild@test', 'client@example.test', 'Message under new numbering'),
        ]);
        app()->instance('test.fakeImapClient', $this->fakeClient($conn));
        // A UID-range search would ask for "UID 201:*" (wrong, stale numbering) if the
        // mismatch weren't caught -- the fake only has message 10, so a broken
        // implementation would silently see nothing and record false success.
        $folder = new FakeImapFolder($conn, uidsAvailableNow: [10]);

        \Illuminate\Support\Facades\Log::spy();

        $this->pollerWithFakeFolder($folder)->poll($this->mailbox);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
            ->withArgs(fn ($msg) => str_contains($msg, 'UIDVALIDITY changed') && str_contains($msg, 'stored=111') && str_contains($msg, 'current=999'))
            ->once();

        $this->assertSame(['SINCE'], array_slice($conn->lastSearchTerms, 0, 1), 'a validity mismatch must fall back to the date backfill, never trust the stale UID range');

        $this->mailbox->refresh();
        $this->assertSame(10, $this->mailbox->last_uid_seen, 'resync correctly picked up the message under the NEW numbering');
        $this->assertSame(999, $this->mailbox->inbox_uid_validity, 'the new validity is now the one of record');
        $this->assertSame(1, CommunicationPending::count(), 'the message under the new numbering was not silently skipped');
    }

    public function test_an_interrupted_poll_does_not_advance_the_stored_uid(): void
    {
        $this->mailbox->forceFill(['last_uid_seen' => 50, 'inbox_uid_validity' => 111])->save();

        config(['communications.imap_poll_budget_seconds' => 1]);
        $conn = new FakeImapConnection(uidValidity: 111, uidNext: 200, headers: []);
        app()->instance('test.fakeImapClient', $this->fakeClient($conn));
        $folder = new FakeImapFolder($conn, uidsAvailableNow: [51], hangOnGet: true); // simulates an unresponsive server mid-read

        $poller = $this->pollerWithFakeFolder($folder);
        $result = $poller->poll($this->mailbox);

        $this->assertSame('error', $result['status']);
        $this->assertSame('read_timeout', $result['reason']);

        $this->mailbox->refresh();
        $this->assertSame(50, $this->mailbox->last_uid_seen, 'CRITICAL: an interrupted poll must leave the stored UID exactly as it was');
        $this->assertSame(111, $this->mailbox->inbox_uid_validity);
        $this->assertSame(0, CommunicationPending::count(), 'nothing was processed, nothing should exist');

        // Next run: same server state, same starting cursor -- must ask for the
        // exact same range again (51:*), proving nothing was skipped.
        config(['communications.imap_poll_budget_seconds' => 50]);
        $folder->hangOnGet = false;
        $this->pollerWithFakeFolder($folder)->poll($this->mailbox);
        $this->assertSame(['UID', '51:*'], $conn->lastSearchTerms, 'the retry asked for the exact same messages the interrupted run never got to');
    }
}

/**
 * Fake IMAP wire. Implements the full ProtocolInterface because
 * Client::getConnection() is type-hinted to it — only fetch()/flags()/
 * folderStatus()/search() are ever actually called by the poller; every
 * other method exists purely to satisfy the interface.
 */
final class FakeImapConnection implements ProtocolInterface
{
    public ?array $lastSearchTerms = null;

    public function __construct(
        public int $uidValidity,
        public int $uidNext,
        public array $headers, // uid => raw RFC822 header string
    ) {
    }

    private function ok(array $data = []): Response
    {
        // canBeEmpty(true): several stubbed calls along the real Message/Client
        // code paths (e.g. the STORE this fake's store() answers, hit via
        // Message::peek() -> unsetFlag()) return an empty result on success --
        // Response::successful() otherwise treats an empty array as failed and
        // validatedData() throws ResponseException("Empty response").
        $r = new Response(0, false);
        $r->setResult($data);
        $r->setCanBeEmpty(true);
        return $r;
    }

    public function folderStatus(string $folder = 'INBOX', $arguments = ['MESSAGES', 'UNSEEN', 'RECENT', 'UIDNEXT', 'UIDVALIDITY']): Response
    {
        return $this->ok(['messages' => 0, 'unseen' => 0, 'recent' => 0, 'uidnext' => $this->uidNext, 'uidvalidity' => $this->uidValidity]);
    }

    public function search(array $params, int|string $uid = 1): Response
    {
        // $params[0] is the raw generated query string, e.g. "UID 101:*" or "SINCE ...".
        $this->lastSearchTerms = explode(' ', trim((string) ($params[0] ?? '')), 2);
        return $this->ok([]); // the poller in these tests reads results via the fake Folder, not this
    }

    public function fetch(array $items, int|array $uids, $x = null, $y = null): Response
    {
        $uid = is_array($uids) ? $uids[0] : $uids;
        $row = ['BODY[HEADER]' => $this->headers[$uid] ?? ''];
        if (in_array('FLAGS', $items, true)) {
            $row['FLAGS'] = [];
        }
        if (in_array('BODY.PEEK[TEXT]', $items, true)) {
            $row['BODY[TEXT]'] = 'Test body text.';
        }
        return $this->ok([$uid => $row]);
    }

    public function flags(int|array $uids, int|string $uid = 1): Response
    {
        $u = is_array($uids) ? $uids[0] : $uids;
        return $this->ok([$u => []]);
    }

    // Everything below is unreachable in these tests -- stubbed only to satisfy the interface.
    public function __destruct() {}
    public function connect(string $host, ?int $port = null) { return true; }
    public function login(string $user, string $password): Response { return $this->ok(); }
    public function authenticate(string $user, string $token): Response { return $this->ok(); }
    public function logout(): Response { return $this->ok(); }
    public function connected(): bool { return true; }
    public function getCapabilities(): Response { return $this->ok(); }
    public function selectFolder(string $folder = 'INBOX'): Response { return $this->ok(); }
    public function examineFolder(string $folder = 'INBOX'): Response { return $this->ok(); }
    public function content(int|array $uids, string $rfc = "RFC822", int|string $uid = 1): Response { return $this->ok(); }
    public function headers(int|array $uids, string $rfc = "RFC822", int|string $uid = 1): Response { return $this->ok(); }
    public function sizes(int|array $uids, int|string $uid = 1): Response { return $this->ok(); }
    public function getUid(?int $id = null): Response { return $this->ok(); }
    public function getMessageNumber(string $id): Response { return $this->ok(); }
    public function folders(string $reference = '', string $folder = '*'): Response { return $this->ok(); }
    public function store(array|string $flags, int $from, ?int $to = null, ?string $mode = null, bool $silent = true, int|string $uid = 1, ?string $item = null): Response { return $this->ok(); }
    public function appendMessage(string $folder, string $message, ?array $flags = null, ?string $date = null): Response { return $this->ok(); }
    public function copyMessage(string $folder, $from, ?int $to = null, int|string $uid = 1): Response { return $this->ok(); }
    public function copyManyMessages(array $messages, string $folder, int|string $uid = 1): Response { return $this->ok(); }
    public function moveMessage(string $folder, $from, ?int $to = null, int|string $uid = 1): Response { return $this->ok(); }
    public function moveManyMessages(array $messages, string $folder, int|string $uid = 1): Response { return $this->ok(); }
    public function ID($ids = null): Response { return $this->ok(); }
    public function createFolder(string $folder): Response { return $this->ok(); }
    public function renameFolder(string $old, string $new): Response { return $this->ok(); }
    public function deleteFolder(string $folder): Response { return $this->ok(); }
    public function subscribeFolder(string $folder): Response { return $this->ok(); }
    public function unsubscribeFolder(string $folder): Response { return $this->ok(); }
    public function idle() {}
    public function done() {}
    public function expunge(): Response { return $this->ok(); }
    public function getQuota($username): Response { return $this->ok(); }
    public function getQuotaRoot(string $quota_root = 'INBOX'): Response { return $this->ok(); }
    public function noop(): Response { return $this->ok(); }
    public function overview(string $sequence, int|string $uid = 1): Response { return $this->ok(); }
    public function enableDebug() {}
    public function disableDebug() {}
    public function enableUidCache() {}
    public function disableUidCache() {}
    public function setUidCache(?array $uids) {}
}

/**
 * Fake Folder — duck-typed (ImapMailboxPoller never type-hints the folder it
 * gets back from resolveFolder(), matching the existing test-double pattern
 * in MailboxHealthTest.php/ImapPollReadTimeoutTest.php).
 */
final class FakeImapFolder
{
    public array $uidsAvailableNow = [];
    public bool $hangOnGet = false;

    public function __construct(private FakeImapConnection $conn, array $uidsAvailableNow = [], bool $hangOnGet = false)
    {
        $this->uidsAvailableNow = $uidsAvailableNow;
        $this->hangOnGet = $hangOnGet;
    }

    public function status(): array
    {
        return ['uidvalidity' => $this->conn->uidValidity, 'uidnext' => $this->conn->uidNext];
    }

    public function query(): FakeImapQuery
    {
        return new FakeImapQuery($this->conn, $this->uidsAvailableNow, $this->hangOnGet);
    }
}

final class FakeImapQuery
{
    private ?int $minUid = null;

    public function __construct(private FakeImapConnection $conn, private array $uidsAvailableNow, private bool $hangOnGet)
    {
    }

    public function where(string $criteria): static
    {
        // Mirrors ImapMailboxPoller's "CUSTOM UID {n}:*" raw criterion.
        if (preg_match('/UID (\d+):\*/', $criteria, $m)) {
            $this->minUid = (int) $m[1];
        }
        $this->conn->lastSearchTerms = $this->minUid !== null ? ['UID', "{$this->minUid}:*"] : null;
        return $this;
    }

    public function since($date): static
    {
        $this->minUid = null; // date-mode: the fake returns everything "available now"
        $this->conn->lastSearchTerms = ['SINCE', (string) $date];
        return $this;
    }

    public function setFetchBody(bool $b): static
    {
        return $this;
    }

    public function get(): \Illuminate\Support\Collection
    {
        if ($this->hangOnGet) {
            // Simulate the pcntl watchdog firing mid-read, exactly like the real
            // budget-timeout tests in MailboxHealthTest.php.
            sleep(3);
        }

        $uids = $this->minUid !== null
            ? array_values(array_filter($this->uidsAvailableNow, fn ($u) => $u >= $this->minUid))
            : $this->uidsAvailableNow;

        return collect($uids)->map(fn ($uid) => new class($uid) {
            public function __construct(private int $uid) {}
            public function getUid(): int { return $this->uid; }
        });
    }
}
