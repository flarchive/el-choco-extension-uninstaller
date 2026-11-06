<?php

namespace ElChoco\ExtensionUninstaller;

require_once __DIR__.'/ConsoleMessage.php';


final class JobStore
{
    private string $folder;

    public function __construct(string $storage)
    {
        $this->folder = $storage.'/extension-uninstaller';
        foreach ([$this->folder, $this->folder.'/jobs', $this->folder.'/plans'] as $dir) {
            if (is_link($dir)) { throw new ConsoleMessage('error_folder_symlink', 'The uninstaller directory must not be a symbolic link.'); }
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new ConsoleMessage('error_folder_unwritable', 'The uninstaller directory is not writable.');
            }
            if (!chmod($dir, 0700)) throw new \RuntimeException('Cannot protect job storage.');
        }
    }

    public function folder(): string { return $this->folder; }

    public function lock(string $name, bool $blocking = false)
    {
        if (!in_array($name, ['queue', 'worker'], true)) { throw new ConsoleMessage('error_lock_invalid', 'Invalid lock.'); }
        $file = $this->folder.'/'.$name.'.lock';
        if (is_link($file)) { throw new ConsoleMessage('error_lock_file_invalid', 'Invalid lock file.'); }
        $handle = @fopen($file, 'c');
        if (!$handle) { throw new ConsoleMessage('error_lock_open', 'The lock file cannot be opened.'); }
        @chmod($file, 0600);
        if (!flock($handle, LOCK_EX | ($blocking ? 0 : LOCK_NB))) { fclose($handle); return null; }
        return $handle;
    }

    public function enqueue(string $command, string $actor, int $uid, string $requestId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $requestId)) { throw new ConsoleMessage('error_job_id_invalid', 'Invalid job ID.'); }
        $lock = $this->lock('queue', true);
        try {
            // Retrying a POST after a lost response must never run Composer twice.
            $existing = $this->read($requestId);
            if ($existing) {
                if ($existing['command'] !== $command || $existing['actor'] !== $actor) {
                    throw new ConsoleMessage('error_job_id_used', 'This job ID is already in use.');
                }
                if ($existing['status'] === 'queued') { $this->atomic($this->folder.'/pending.json', ['id' => $existing['id']]); }
                return $existing;
            }
            foreach ($this->all() as $job) {
                if (in_array($job['status'], ['queued', 'running', 'interrupted'], true)) {
                    throw new ConsoleMessage('error_queue_busy', 'A job is already open. Check its status; resolve interrupted jobs in the terminal first.');
                }
            }
            $job = ['id' => $requestId, 'command' => $command, 'actor' => $actor, 'uid' => $uid,
                'status' => 'queued', 'time' => gmdate('c'), 'createdOrder' => microtime(true), 'startedAt' => null, 'finishedAt' => null,
                'exitCode' => null, 'seconds' => 0, 'output' => '', 'truncated' => false,
                'backup' => null, 'warnings' => [], 'hasWarnings' => false, 'needsAutoloadRefresh' => false];
            $this->save($job);
            $this->atomic($this->folder.'/pending.json', ['id' => $job['id']]);
            return $job;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function read(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new ConsoleMessage('error_job_id_invalid', 'Invalid job ID.'); }
        $file = $this->folder.'/jobs/'.$id.'.json';
        if (!is_file($file)) { return null; }
        if (is_link($file)) { throw new ConsoleMessage('error_job_file_invalid', 'Invalid job file.'); }
        $contents = @file_get_contents($file);
        if ($contents === false && !is_file($file)) { return null; }
        $data = json_decode((string) $contents, true);
        if (!is_array($data) || ($data['id'] ?? null) !== $id) { throw new ConsoleMessage('error_job_corrupt', 'The job file is damaged. No automatic retry.'); }
        return $data;
    }

    public function save(array $job): void
    {
        $id = $job['id'];
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new ConsoleMessage('error_job_id_invalid', 'Invalid job ID.'); }
        $this->atomic($this->folder.'/jobs/'.$id.'.json', $job);
    }

    public function heartbeat(): void { $this->atomic($this->folder.'/worker.json', ['time' => time()]); }
    public function pendingSignal(): string
    {
        $file = $this->folder.'/pending.json';
        if (!is_file($file)) { return ''; }
        if (is_link($file)) { throw new ConsoleMessage('error_signal_invalid', 'Invalid job signal.'); }
        return (string) file_get_contents($file);
    }
    public function requestStop(): void { $this->atomic($this->folder.'/stop.json', ['time' => microtime(true)]); }
    public function stopRequestedAfter(float $start): bool
    {
        $file = $this->folder.'/stop.json';
        if (!is_file($file) || is_link($file)) { return false; }
        $data = json_decode((string) file_get_contents($file), true);
        return (float) ($data['time'] ?? 0) >= $start;
    }
    public function workerSeen(): ?int
    {
        $file = $this->folder.'/worker.json';
        if (!is_file($file) || is_link($file)) { return null; }
        $data = json_decode((string) file_get_contents($file), true);
        return isset($data['time']) ? (int) $data['time'] : null;
    }

    public function all(): array
    {
        $jobs = [];
        foreach (glob($this->folder.'/jobs/*.json') ?: [] as $file) {
            $job = $this->read(basename($file, '.json'));
            if ($job !== null) { $jobs[] = $job; }
        }
        usort($jobs, function ($a, $b) { return ($b['createdOrder'] ?? strtotime($b['time'])) <=> ($a['createdOrder'] ?? strtotime($a['time'])); });
        return $jobs;
    }

    public function history(): array
    {
        return array_map(function ($job) {
            unset($job['output'], $job['warnings'], $job['uid']);
            return $job;
        }, array_slice($this->all(), 0, 30));
    }

    public function cleanup(): void
    {
        foreach ($this->all() as $i => $job) {
            if (in_array($job['status'], ['succeeded', 'failed', 'acknowledged'], true) &&
                ($i >= 100 || strtotime($job['finishedAt'] ?? $job['time']) < time() - 7 * 86400)) {
                if (!@unlink($this->folder.'/jobs/'.$job['id'].'.json')) { throw new ConsoleMessage('error_cleanup_failed', 'Expired job output could not be deleted.'); }
            }
        }
    }

    public function record(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new \RuntimeException('Invalid plan ID.');
        $file = $this->folder.'/plans/'.$id.'.json';
        if (is_link($file)) throw new \RuntimeException('Invalid plan file.');
        if (!is_file($file)) return null;
        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    public function saveRecord(string $id, array $data): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new \RuntimeException('Invalid plan ID.');
        $this->atomic($this->folder.'/plans/'.$id.'.json', $data);
    }

    private function atomic(string $file, array $data): void
    {
        if (is_link($file)) { throw new ConsoleMessage('error_target_symlink', 'A symbolic link cannot be used as the target file.'); }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $tmp = $file.'.'.bin2hex(random_bytes(6)).'.tmp';
        $handle = @fopen($tmp, 'x');
        if (!$handle) { throw new ConsoleMessage('error_state_create', 'The status file cannot be created.'); }
        @chmod($tmp, 0600);
        try {
            $offset = 0;
            while ($offset < strlen($json)) {
                $written = fwrite($handle, substr($json, $offset));
                if (!$written) { throw new ConsoleMessage('error_state_write', 'The status file could not be written completely.'); }
                $offset += $written;
            }
            fflush($handle);
        } finally { fclose($handle); }
        if (!@rename($tmp, $file)) { @unlink($tmp); throw new ConsoleMessage('error_state_replace', 'The status file cannot be replaced atomically.'); }
    }
}
