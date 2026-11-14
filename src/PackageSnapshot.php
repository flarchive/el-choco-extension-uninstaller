<?php

namespace ElChoco\ExtensionUninstaller;

require_once __DIR__.'/ConsoleMessage.php';


final class PackageSnapshot
{
    public static function create(string $base, string $storage, ?string $extensionList): string
    {
        $id = gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
        $destination = $storage.'/extension-uninstaller/backups/'.$id;
        Files::directory($storage.'/extension-uninstaller/backups');
        if (is_link($destination)) throw new \RuntimeException('Linked snapshot directory rejected.');
        if (!@mkdir($destination, 0700, true) && !is_dir($destination)) {
            throw new ConsoleMessage('error_backup_create', 'The snapshot could not be created. Package changes were not started.');
        }
        $sources = ['composer.json' => $base.'/composer.json'];
        if (is_file($base.'/composer.lock')) { $sources['composer.lock'] = $base.'/composer.lock'; }
        if ($extensionList !== null) {
            if (!is_file($extensionList)) { throw new ConsoleMessage('error_extension_list_missing', 'The existing extension list is missing. Package changes were not started.'); }
            $sources['extensions-list'] = $extensionList;
        }
        foreach ($sources as $name => $source) {
            $bytes = @file_get_contents($source);
            if ($bytes === false) {
                throw new ConsoleMessage('error_backup_copy', 'Snapshot of {file} failed. Package changes were not started.', ['file' => $name]);
            }
            Files::write($destination.'/'.$name, $bytes);
        }
        return $id;
    }
}
