#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['base:', 'id:', 'action:']);
$base = isset($options['base']) ? realpath($options['base']) : false;
$id = $options['id'] ?? '';
$action = $options['action'] ?? '';
if (!$base || !preg_match('/^[a-z0-9][a-z0-9_.-]*$/D', $id) || !in_array($action, ['disable', 'purge'], true)) { fwrite(STDERR, "Invalid action arguments.\n"); exit(1); }
try {
    require $base.'/vendor/autoload.php';
    require_once __DIR__.'/../src/RuntimeDiagnostics.php';
    if (!\ElChoco\ExtensionUninstaller\RuntimeDiagnostics::uid()) throw new \RuntimeException('Do not run this action as root.');
    $site = require $base.'/site.php';
    if (!$site instanceof \Flarum\Foundation\SiteInterface) throw new \RuntimeException('Invalid site.php.');
    $manager = $site->bootApp()->getContainer()->make(\Flarum\Extension\ExtensionManager::class);
    if (!$manager->getExtension($id)) throw new \RuntimeException('Extension not found.');
    $manager->disable($id);
    if ($action === 'purge') $manager->uninstall($id);
    fwrite(STDOUT, $action." completed through Flarum.\n");
} catch (\Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }
