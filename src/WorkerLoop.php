<?php
namespace ElChoco\ExtensionUninstaller;

use Throwable;

final class WorkerLoop
{
    private JobStore $store;
    private JobRunner $runner;
    public function __construct(JobStore $store, JobRunner $runner) { $this->store = $store; $this->runner = $runner; }

    public function run(): void
    {
        $started = microtime(true); $lastSweep = 0; $lastHeartbeat = 0; $signal = null; $lastError = '';
        while (!$this->store->stopRequestedAfter($started)) {
            $current = $this->store->pendingSignal();
            // Read one tiny signal per second, not every stored command output.
            // A periodic sweep also repairs a crash between job and signal writes.
            if ($signal !== $current || time() - $lastSweep >= 60) {
                $lock = $this->store->lock('worker');
                if ($lock) {
                    try {
                        $this->runner->drain(); $lastError = '';
                    } catch (Throwable $e) {
                        if ($e->getMessage() !== $lastError) { fwrite(STDERR, $e->getMessage()."\n"); }
                        $lastError = $e->getMessage();
                    } finally { flock($lock, LOCK_UN); fclose($lock); }
                    $lastSweep = time(); $signal = $current; $lastHeartbeat = time();
                }
            }
            if (time() - $lastHeartbeat >= 10) { $this->store->heartbeat(); $lastHeartbeat = time(); }
            usleep(1000000);
        }
    }
}
