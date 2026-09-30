<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Real concurrency: every worker is a separate PHP process with its own
 * PostgreSQL connection. Test data must therefore be committed, so tests
 * using this trait use DatabaseTruncation instead of RefreshDatabase.
 */
trait RunsConcurrentWorkers
{
    /**
     * Start all workers while this test holds the locks taken by $holdLocks,
     * wait until every worker is blocked on those locks, then release them so
     * all workers race at the same instant.
     *
     * @param  callable(): void  $holdLocks  runs inside an open transaction, e.g. SELECT ... FOR UPDATE
     * @param  list<list<string>>  $workerArgs  arguments for tests/Support/concurrency-worker.php
     * @return list<string> one outcome per worker, in the same order
     */
    protected function raceWorkers(callable $holdLocks, array $workerArgs, int $timeoutSeconds = 30): array
    {
        $db = config('database.connections.'.config('database.default'));
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => config('database.default'),
            'DB_HOST' => $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => $db['password'],
            'CACHE_STORE' => 'array',
        ];

        DB::beginTransaction();
        $holdLocks();

        $processes = array_map(
            fn (array $args) => Process::env($env)
                ->timeout($timeoutSeconds)
                ->start([PHP_BINARY, base_path('tests/Support/concurrency-worker.php'), ...$args]),
            $workerArgs,
        );

        try {
            $this->waitUntilBlockedOnLocks(count($workerArgs), $processes, $timeoutSeconds);
        } finally {
            DB::commit();
        }

        return array_map(fn ($process) => trim($process->wait()->output()), $processes);
    }

    private function waitUntilBlockedOnLocks(int $expected, array $processes, int $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            // Statistics views are snapshotted once per transaction, and we are
            // inside the lock-holding transaction: drop the snapshot every poll.
            DB::select('SELECT pg_stat_clear_snapshot()');

            $waiting = DB::scalar(
                "SELECT count(*) FROM pg_stat_activity
                 WHERE datname = current_database() AND wait_event_type = 'Lock' AND pid <> pg_backend_pid()"
            );

            if ($waiting >= $expected) {
                return;
            }

            // A worker that exits while we still hold the locks never reached them.
            foreach ($processes as $process) {
                if (! $process->running()) {
                    throw new RuntimeException('Worker exited before blocking on the locks: '.$this->describe($process));
                }
            }

            usleep(20_000);
        }

        throw new RuntimeException("Timed out waiting for {$expected} workers to block on locks (saw {$waiting}).");
    }

    private function describe($process): string
    {
        return trim($process->output().' '.$process->errorOutput()) ?: '(no output)';
    }
}
