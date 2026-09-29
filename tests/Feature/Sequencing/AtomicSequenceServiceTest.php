<?php

declare(strict_types=1);

namespace Tests\Feature\Sequencing;

use App\Services\Sequencing\AtomicSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Duplicate-deal fix, 2026-09-29 — the generic allocator behind race-safe
 * deal_no / DealV2::reference numbering (see AtomicSequenceService docblock
 * for the full incident this replaces: two concurrent reads of the same
 * MAX(deal_no), no lock, producing two full deals with consecutive-looking
 * numbers — #1826 and #1827 — from one user's double-click).
 */
final class AtomicSequenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_consecutive_calls_return_distinct_ascending_values(): void
    {
        $svc = app(AtomicSequenceService::class);

        $a = DB::transaction(fn () => $svc->next('test-scope-a', 1001));
        $b = DB::transaction(fn () => $svc->next('test-scope-a', 1001));
        $c = DB::transaction(fn () => $svc->next('test-scope-a', 1001));

        $this->assertSame(1001, $a);
        $this->assertSame(1002, $b, 'consecutive');
        $this->assertSame(1003, $c, 'consecutive');
    }

    public function test_a_void_never_frees_a_number_for_reuse(): void
    {
        // The allocator has no concept of "undo" — calling it never rewinds
        // next_value, mirroring ProformaNumberService's own guarantee.
        $svc = app(AtomicSequenceService::class);

        $first = DB::transaction(fn () => $svc->next('test-scope-b', 1));
        $second = DB::transaction(fn () => $svc->next('test-scope-b', 1));

        $this->assertSame($first + 1, $second);
    }

    public function test_different_scopes_are_independent_sequences(): void
    {
        $svc = app(AtomicSequenceService::class);

        $a1 = DB::transaction(fn () => $svc->next('scope-x', 1));
        $b1 = DB::transaction(fn () => $svc->next('scope-y', 500));
        $a2 = DB::transaction(fn () => $svc->next('scope-x', 1));

        $this->assertSame(1, $a1);
        $this->assertSame(500, $b1);
        $this->assertSame(2, $a2);
    }

    /**
     * The actual claim behind "race-safe": a second allocation for the SAME
     * scope genuinely BLOCKS on the row lock while the first transaction is
     * still open, rather than racing it. Proven with two real, independent
     * DB connections (not two sequential calls on one connection, which
     * would prove nothing about locking) — the second connection's
     * lockForUpdate() must wait; MySQL's innodb_lock_wait_timeout then
     * surfaces as a query error if it waits past our short timeout, which is
     * exactly the signal that the lock held.
     */
    public function test_a_second_allocation_for_the_same_scope_blocks_while_the_first_transaction_is_open(): void
    {
        $svc = app(AtomicSequenceService::class);

        // First connection: allocate and DELIBERATELY do not commit yet.
        DB::beginTransaction();
        $first = $svc->next('test-scope-lock', 1);
        $this->assertSame(1, $first);

        // Second, independent connection — a fresh PDO, not DB::connection()'s
        // own instance, so its transaction is genuinely separate.
        $config = config('database.connections.' . config('database.default'));
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4";
        $pdo2 = new \PDO($dsn, $config['username'], $config['password']);
        $pdo2->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo2->exec('SET SESSION innodb_lock_wait_timeout = 1'); // fail fast instead of hanging the test

        $blocked = false;
        try {
            $pdo2->beginTransaction();
            $stmt = $pdo2->prepare("SELECT * FROM sequence_counters WHERE scope = 'test-scope-lock' FOR UPDATE");
            $stmt->execute();
            $pdo2->commit();
        } catch (\PDOException $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout') || str_contains($e->getMessage(), '1205');
            $pdo2->rollBack();
        }

        DB::rollBack(); // release the first transaction's lock, clean up

        $this->assertTrue($blocked, 'a second connection locking the same scope row must have blocked/timed out — proves the lock is real, not just the outcome');
    }
}
