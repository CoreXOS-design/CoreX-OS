<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Services\Communications\EmailArchiveIngestor;
use App\Services\Communications\ImapMailboxPoller;
use Tests\TestCase;

/**
 * AT-43 Fix 1 — the Sent folder must resolve to the REAL sent-mail folder even
 * when the server has several folders whose leaf name is "Sent". webklex
 * getFolderByName() matched leaf names and grabbed an empty homonym, losing all
 * outbound capture. Resolution now prefers the RFC 6154 \Sent special-use flag,
 * then a non-empty path-candidate.
 */
final class SentFolderResolutionTest extends TestCase
{
    /** Build a fake webklex client from [path => ['flags'=>[], 'count'=>int]]. */
    private function fakeClient(array $spec): object
    {
        $listed = [];
        $folders = [];
        foreach ($spec as $path => $meta) {
            $listed[$path] = ['delimiter' => '.', 'flags' => $meta['flags'] ?? []];
            $folders[$path] = new class($path, (int) ($meta['count'] ?? 0)) {
                public string $path;

                public function __construct(string $p, private int $count)
                {
                    $this->path = $p;
                }

                public function query(): object
                {
                    return new class($this->count) {
                        public function __construct(private int $count)
                        {
                        }

                        public function all(): \Illuminate\Support\Collection
                        {
                            return collect($this->count > 0 ? array_fill(0, $this->count, true) : []);
                        }
                    };
                }
            };
        }

        return new class($listed, $folders) {
            public function __construct(private array $listed, private array $folders)
            {
            }

            public function getConnection(): object
            {
                return new class($this->listed) {
                    public function __construct(private array $listed)
                    {
                    }

                    public function folders($ref = '', $pat = '*'): object
                    {
                        return new class($this->listed) {
                            public function __construct(private array $listed)
                            {
                            }

                            public function validatedData(): array
                            {
                                return $this->listed;
                            }
                        };
                    }
                };
            }

            public function getFolderByPath($path)
            {
                return $this->folders[$path] ?? null;
            }
        };
    }

    private function resolver(): object
    {
        return new class(app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            public function publicResolveSent($client)
            {
                return $this->resolveSentFolder($client);
            }
        };
    }

    public function test_special_use_sent_wins_over_empty_homonyms(): void
    {
        // The live Afrihost shape: real INBOX.Sent advertises \Sent; two empty
        // homonyms also named "Sent".
        $client = $this->fakeClient([
            'INBOX'                                    => ['flags' => [], 'count' => 330],
            'INBOX.Sent'                               => ['flags' => ['\\HasNoChildren', '\\Sent'], 'count' => 551],
            'INBOX.INBOX.Sent'                         => ['flags' => ['\\HasNoChildren'], 'count' => 0],
            'INBOX.sorted.local (This computer).Sent'  => ['flags' => ['\\HasNoChildren'], 'count' => 1],
        ]);

        $folder = $this->resolver()->publicResolveSent($client);

        $this->assertNotNull($folder);
        $this->assertSame('INBOX.Sent', $folder->path);
    }

    public function test_gmail_special_use_path_resolves(): void
    {
        $client = $this->fakeClient([
            'INBOX'              => ['flags' => [], 'count' => 100],
            '[Gmail]/Sent Mail'  => ['flags' => ['\\HasNoChildren', '\\Sent'], 'count' => 42],
            '[Gmail]/All Mail'   => ['flags' => ['\\HasNoChildren', '\\All'], 'count' => 9999],
        ]);

        $folder = $this->resolver()->publicResolveSent($client);

        $this->assertSame('[Gmail]/Sent Mail', $folder->path);
    }

    public function test_no_special_use_falls_back_to_non_empty_candidate_not_empty_homonym(): void
    {
        // No server advertises \Sent → path fallback. INBOX.Sent (non-empty) must
        // beat the empty INBOX.INBOX.Sent homonym.
        $client = $this->fakeClient([
            'INBOX'            => ['flags' => [], 'count' => 50],
            'INBOX.Sent'       => ['flags' => ['\\HasNoChildren'], 'count' => 551],
            'INBOX.INBOX.Sent' => ['flags' => ['\\HasNoChildren'], 'count' => 0],
        ]);

        $folder = $this->resolver()->publicResolveSent($client);

        $this->assertSame('INBOX.Sent', $folder->path);
    }

    public function test_noselect_special_use_is_skipped(): void
    {
        // A \Sent folder marked \Noselect can't be opened — skip it, fall back.
        $client = $this->fakeClient([
            'INBOX'        => ['flags' => [], 'count' => 50],
            'Sent-Virtual' => ['flags' => ['\\Sent', '\\Noselect'], 'count' => 0],
            'INBOX.Sent'   => ['flags' => ['\\HasNoChildren'], 'count' => 200],
        ]);

        $folder = $this->resolver()->publicResolveSent($client);

        $this->assertSame('INBOX.Sent', $folder->path);
    }

    public function test_returns_null_when_no_sent_folder_exists(): void
    {
        $client = $this->fakeClient([
            'INBOX'         => ['flags' => [], 'count' => 50],
            'INBOX.Drafts'  => ['flags' => ['\\Drafts'], 'count' => 3],
        ]);

        $this->assertNull($this->resolver()->publicResolveSent($client));
    }
}
