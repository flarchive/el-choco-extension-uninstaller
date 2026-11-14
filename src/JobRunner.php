<?php
namespace ElChoco\ExtensionUninstaller;

final class JobRunner
{
    private JobStore $store;
    private string $base;
    private string $storage;
    private string $assets;

    public function __construct(JobStore $store, string $base, string $storage, string $assets)
    {
        $this->store = $store; $this->base = $base; $this->storage = $storage; $this->assets = $assets;
    }

    public function drain(?string $acknowledge = null): void
    {
        $uid = RuntimeDiagnostics::uid();
        if (!$uid) throw new \RuntimeException('Run the worker as the PHP-FPM user, never root.');
        $this->store->heartbeat();
        foreach ($this->store->all() as $job) {
            if ($job['status'] === 'running') {
                $job['status'] = 'interrupted'; $job['finishedAt'] = gmdate('c');
                $job['output'] .= "\nWorker completion is unknown. Inspect child processes and backups before acknowledging. No automatic retry.\n";
                $this->store->save($job);
            }
        }
        if ($acknowledge !== null) {
            $job = $this->store->read($acknowledge);
            if (!$job || $job['status'] !== 'interrupted') throw new \RuntimeException('Only interrupted jobs can be acknowledged.');
            $job['status'] = 'acknowledged'; $this->store->save($job);
        }
        foreach ($this->store->all() as $job) {
            if ($job['status'] === 'interrupted') throw new \RuntimeException('Inspect interrupted job '.$job['id'].' before using --acknowledge.');
        }
        foreach (array_reverse($this->store->all()) as $job) if ($job['status'] === 'queued') $this->execute($job, $uid);
        $this->store->cleanup();
    }

    private function consoleLock()
    {
        $folder = $this->storage.'/admin-console';
        if (!is_dir($folder) && !isset(Inventory::packages($this->base)['el-choco/admin-console'])) return null;
        if (is_link($folder)) throw new \RuntimeException('Unsafe Admin Console storage.');
        Files::directory($folder);
        if (is_link($folder.'/worker.lock')) throw new \RuntimeException('Unsafe Admin Console lock.');
        $lock = fopen($folder.'/worker.lock', 'c');
        if (!$lock) throw new \RuntimeException('Cannot acquire the Admin Console lock.');
        chmod($folder.'/worker.lock', 0600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new \RuntimeException('Admin Console is executing a job. Wait and request this action again.'); }
        foreach (glob($folder.'/jobs/*.json') ?: [] as $file) {
            $job = Files::json($file);
            if (in_array($job['status'] ?? '', ['running', 'interrupted', 'queued'], true)) {
                flock($lock, LOCK_UN); fclose($lock);
                throw new \RuntimeException('Resolve open Admin Console jobs before using the uninstaller.');
            }
        }
        return $lock;
    }

    private function execute(array $job, int $uid): void
    {
        $start = microtime(true); $lastSave = 0; $mutation = false; $lock = null;
        $job['status'] = 'running'; $job['startedAt'] = gmdate('c'); $job['phase'] = 'preflight'; $this->store->save($job);
        $log = function ($chunk) use (&$job) {
            $remaining = max(0, 262144 - strlen($job['output']));
            $job['output'] .= substr($chunk, 0, $remaining);
            if (strlen($chunk) > $remaining) $job['truncated'] = true;
        };
        $pulse = function () use (&$job, &$lastSave, $start) {
            if (microtime(true) - $lastSave < 1) return;
            $job['seconds'] = round(microtime(true) - $start, 2); $this->store->save($job); $this->store->heartbeat(); $lastSave = microtime(true);
        };
        try {
            if ($job['uid'] !== $uid) throw new \RuntimeException('Worker and web PHP must use the same UID.');
            $lock = $this->consoleLock();
            $request = json_decode($job['command'], true, 512, JSON_THROW_ON_ERROR);
            $db = new Database($this->base);
            $executor = new ProcessExecutor($this->base, $this->store->folder(), $pulse);
            $plans = new PlanService($this->base, $this->store, $executor);
            if (($request['action'] ?? '') === 'preview') {
                $job['result'] = $plans->create($request['package'], $request['mode'], $job['actor'], $db->enabled(), $log);
                $job['phase'] = 'preview_ready';
            } elseif (($request['action'] ?? '') === 'execute') {
                $plan = $plans->validate($request['plan'], $job['actor'], $db->enabled());
                if ($plan['mode'] !== 'disable') RuntimeDiagnostics::assertPackageWritable($this->base, $plan['removed']);
                foreach (RuntimeDiagnostics::inspect($this->storage, $this->assets)['checks'] as $check) {
                    if (!$check['writable']) throw new \RuntimeException('Not writable: '.$check['name']);
                }
                $helper = is_executable('/usr/local/bin/extension') && is_file('/data/extensions/list');
                $job['phase'] = 'package_backup'; $this->store->save($job);
                $job['backup'] = PackageSnapshot::create($this->base, $this->storage, $helper ? '/data/extensions/list' : null);
                $backup = $this->store->folder().'/backups/'.$job['backup'];
                $plan['usedBy'] = $job['id']; $this->store->saveRecord($plan['id'], $plan);
                $manifest = ['package' => $plan['package'], 'mode' => $plan['mode'], 'created' => gmdate('c'), 'database' => null];
                if ($plan['mode'] === 'purge') {
                    $job['phase'] = 'database_backup'; $this->store->save($job);
                    $manifest['database'] = $db->backup($backup, $executor, $log);
                }
                Files::write($backup.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                $job['phase'] = $plan['mode'] === 'purge' ? 'purge' : 'disable'; $this->store->save($job);
                $mutation = true; $job['needsAutoloadRefresh'] = true;
                $action = $plan['mode'] === 'purge' ? 'purge' : 'disable';
                $exit = $executor->run([PHP_BINARY, dirname(__DIR__).'/bin/flarum-action.php', '--base='.$this->base, '--id='.$plan['extension']['id'], '--action='.$action], function ($type, $chunk) use ($log) { $log($chunk); });
                if ($exit !== 0) throw new \RuntimeException('Flarum could not complete the activation/data action. Package removal was not started.');
                if ($plan['mode'] !== 'disable') {
                    $job['phase'] = 'remove'; $this->store->save($job);
                    $draft = $this->store->folder().'/plans/'.$plan['id'];
                    foreach (['composer.json', 'composer.lock', 'vendor/composer/installed.json'] as $i => $file) {
                        if (hash_file('sha256', $this->base.'/'.$file) !== $plan['filesBefore'][$i]) throw new \RuntimeException('Package state changed during the Flarum action. No package files were replaced.');
                    }
                    foreach (['composer.json', 'composer.lock'] as $i => $file) {
                        if (is_link($draft.'/'.$file) || hash_file('sha256', $draft.'/'.$file) !== $plan['draftHashes'][$i]) throw new \RuntimeException('Approved proposal files changed.');
                    }
                    foreach (['composer.json', 'composer.lock'] as $file) Files::write($this->base.'/'.$file, (string) file_get_contents($draft.'/'.$file), fileperms($this->base.'/'.$file) & 0777);
                    $argv = array_merge($executor->composer(), ['install', '--no-dev', '--no-scripts', '--no-plugins', '--no-interaction', '--no-progress', '--no-ansi']);
                    if ($executor->run($argv, function ($type, $chunk) use ($log) { $log($chunk); }) !== 0) throw new \RuntimeException('Composer install failed after applying the approved lock file. Inspect the snapshot; no automatic rollback.');
                    if (isset(Inventory::packages($this->base)[$plan['package']])) throw new \RuntimeException('The extension package is still installed.');
                    Inventory::assertLocked($this->base);
                    if ($helper) {
                        $job['phase'] = 'docker_sync'; $this->store->save($job);
                        if ($executor->run(['/usr/local/bin/extension', 'sync'], function ($type, $chunk) use ($log) { $log($chunk); }) !== 0) throw new \RuntimeException('Removal succeeded but CrazyMax extension sync failed. Inspect the extension list before recreating the container.');
                    }
                }
                $job['phase'] = 'cache'; $this->store->save($job);
                if ($executor->run([PHP_BINARY, $this->base.'/flarum', 'cache:clear'], function ($type, $chunk) use ($log) { $log($chunk); }) !== 0) throw new \RuntimeException('Cache rebuild failed.');
                $job['phase'] = 'check'; $this->store->save($job);
                if ($executor->run([PHP_BINARY, $this->base.'/flarum', 'info'], function ($type, $chunk) use ($log) { $log($chunk); }) !== 0) throw new \RuntimeException('The final Flarum CLI boot check failed.');
                $job['result'] = ['package' => $plan['package'], 'mode' => $plan['mode'], 'check' => 'cli_boot'];
                $job['phase'] = 'done';
            } else throw new \RuntimeException('Unknown job action.');
            $job['status'] = 'succeeded'; $job['exitCode'] = 0;
        } catch (\Throwable $e) {
            $log("\n".$e->getMessage()."\n");
            $job['status'] = $mutation ? 'interrupted' : 'failed'; $job['exitCode'] = null;
        } finally { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
        $job['finishedAt'] = gmdate('c'); $job['seconds'] = round(microtime(true) - $start, 2);
        $job['warnings'] = RuntimeDiagnostics::warnings($job['output']); $job['hasWarnings'] = count($job['warnings']) > 0;
        $this->store->save($job); $this->store->heartbeat();
        if ($job['status'] === 'interrupted') throw new \RuntimeException('Manual inspection required after job '.$job['id']);
    }
}
