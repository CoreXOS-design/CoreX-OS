<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\CommunicationMailbox;
use App\Models\User;
use App\Services\Communications\EmailArchiveIngestor;
use App\Services\Communications\ImapMailboxPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-40 BUG 1 — a non-responsive folder read must fail fast on the time budget,
 * never spin until the queue job timeout. Drives the poller with a fake client
 * whose folder read sleeps far longer than the (test-shrunk) budget and asserts
 * the poll returns a clean read_timeout error in roughly the budget window.
 */
final class ImapPollReadTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl not available — the hard watchdog needs it.');
        }
    }

    public function test_a_hung_folder_read_aborts_on_the_budget_instead_of_spinning(): void
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'T ' . Str::random(5), 'slug' => 'tt-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'D',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs(User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin',
        ]));

        $mailbox = CommunicationMailbox::create([
            'agency_id' => $agencyId, 'email_address' => 'office@agency.test',
            'imap_host' => 'imap.agency.test', 'imap_port' => 993, 'username' => 'office@agency.test',
            'encrypted_password' => 'secret', 'poll_inbox' => true, 'poll_sent' => false,
            'poll_interval_minutes' => 15, 'active' => true,
        ]);

        // Shrink the budget so the test is fast; the fake read sleeps longer.
        config(['communications.imap_poll_budget_seconds' => 1]);

        $poller = new class (app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            protected function connect(CommunicationMailbox $mailbox)
            {
                // A fake client whose INBOX read blocks for 5s — well past the 1s budget.
                $folder = new class {
                    public function query()
                    {
                        return $this;
                    }

                    public function since($d)
                    {
                        return $this;
                    }

                    public function get()
                    {
                        sleep(5); // simulate a non-responsive server fread()

                        return [];
                    }
                };

                return new class ($folder) {
                    public function __construct(private $folder)
                    {
                    }

                    public function getFolderByName($name)
                    {
                        return $this->folder;
                    }

                    public function disconnect(): void
                    {
                    }
                };
            }
        };

        $t0 = microtime(true);
        $result = $poller->poll($mailbox);
        $elapsed = microtime(true) - $t0;

        $this->assertSame('error', $result['status']);
        $this->assertSame('read_timeout', $result['reason']);
        $this->assertLessThan(
            4.0,
            $elapsed,
            "poll must abort on the ~1s budget, not block for the full 5s read (took {$elapsed}s)"
        );
    }
}
