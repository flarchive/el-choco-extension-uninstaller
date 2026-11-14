#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['base:', 'storage:', 'public:', 'acknowledge:', 'watch', 'stop']);
$base = isset($options['base']) ? realpath($options['base']) : false;
if (!$base || !is_file($base.'/flarum') || !is_file($base.'/vendor/autoload.php')) {
    fwrite(STDERR, "Usage: php worker.php --base=/absolute/flarum/path [--watch | --stop | --acknowledge=JOB_ID]\n"); exit(1);
}
require $base.'/vendor/autoload.php';
foreach (['ConsoleMessage', 'Files', 'RuntimeDiagnostics', 'PackageSnapshot', 'JobStore', 'ProcessExecutor', 'Inventory', 'Database', 'PlanService', 'JobRunner', 'WorkerLoop'] as $class) require_once __DIR__.'/../src/'.$class.'.php';
use ElChoco\ExtensionUninstaller\JobStore;
use ElChoco\ExtensionUninstaller\JobRunner;
use ElChoco\ExtensionUninstaller\RuntimeDiagnostics;
use ElChoco\ExtensionUninstaller\WorkerLoop;
try {
    if (!RuntimeDiagnostics::uid()) throw new \RuntimeException('Use the same identifiable non-root user as PHP-FPM.');
    chdir($base);
    $storage = realpath($options['storage'] ?? $base.'/storage');
    $public = realpath($options['public'] ?? (is_dir($base.'/public') ? $base.'/public' : $base));
    if (!$storage || !$public) throw new \RuntimeException('Storage/public paths missing. Specify --storage and --public for a custom site.php.');
    $store = new JobStore($storage);
    if (array_key_exists('stop', $options)) { $store->requestStop(); exit(0); }
    $runner = new JobRunner($store, $base, $storage, $public.'/assets');
    if (array_key_exists('watch', $options)) {
        if (isset($options['acknowledge'])) throw new \RuntimeException('Use --acknowledge separately without --watch.');
        (new WorkerLoop($store, $runner))->run(); exit(0);
    }
    $lock = $store->lock('worker');
    if (!$lock) { if (isset($options['acknowledge'])) throw new \RuntimeException('Worker is busy.'); exit(0); }
    try { $runner->drain($options['acknowledge'] ?? null); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
} catch (\Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }
