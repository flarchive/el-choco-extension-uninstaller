<?php
namespace ElChoco\ExtensionUninstaller;

final class Inventory
{
    public const PROTECTED = ['flarum/core', 'el-choco/extension-uninstaller', 'el-choco/admin-console', 'franc/admin-console'];

    public static function packageName(string $name): string
    {
        if (!preg_match('~^[a-z0-9](?:[a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*)$~D', $name)) throw new \InvalidArgumentException('Invalid package name.');
        return $name;
    }

    public static function id(string $name): string
    {
        [$vendor, $package] = explode('/', self::packageName($name), 2);
        return $vendor.'-'.str_replace(['flarum-ext-', 'flarum-'], '', $package);
    }

    public static function packages(string $base): array
    {
        $data = Files::json($base.'/vendor/composer/installed.json');
        $packages = [];
        foreach ($data['packages'] ?? $data as $package) {
            if (!is_array($package) || !isset($package['name'])) continue;
            $packages[$package['name']] = $package;
        }
        return $packages;
    }

    public static function assertLocked(string $base): void
    {
        $lock = Files::json($base.'/composer.lock'); $expected = [];
        foreach ($lock['packages'] ?? [] as $package) $expected[$package['name']] = $package;
        $installed = self::packages($base);
        $keys = array_keys($expected); $actualKeys = array_keys($installed); sort($keys); sort($actualKeys);
        if ($keys !== $actualKeys) throw new \RuntimeException('Installed packages do not match the production lock file. Repair the installation in the terminal first.');
        foreach ($expected as $name => $package) {
            $actual = $installed[$name];
            foreach (['version', 'source', 'dist'] as $field) {
                $a = $package[$field] ?? ''; $b = $actual[$field] ?? '';
                if ($field === 'version') { $a = ltrim($a, 'v'); $b = ltrim($b, 'v'); }
                if ($a != $b) throw new \RuntimeException('Installed package differs from lock file: '.$name);
            }
        }
    }

    public static function extensions(string $base, array $enabled): array
    {
        $root = Files::json($base.'/composer.json');
        $packages = self::packages($base); $rows = []; $ids = [];
        foreach ($packages as $name => $p) {
            if (($p['type'] ?? '') !== 'flarum-extension') continue;
            $id = self::id($name);
            if (isset($ids[$id])) throw new \RuntimeException('Ambiguous extension identifier: '.$id);
            $ids[$id] = true;
            $dependents = [];
            foreach ($packages as $other => $meta) {
                if ($other !== $name && isset($meta['require'][$name])) $dependents[] = $other;
            }
            $rows[] = ['name' => $name, 'id' => $id, 'title' => $p['extra']['flarum-extension']['title'] ?? $name,
                'description' => $p['description'] ?? '', 'version' => $p['version'] ?? '',
                'enabled' => in_array($id, $enabled, true), 'direct' => isset($root['require'][$name]),
                'protected' => in_array($name, self::PROTECTED, true), 'dependents' => $dependents];
        }
        usort($rows, function ($a, $b) { return strcasecmp($a['title'], $b['title']); });
        return $rows;
    }

    public static function target(string $base, string $name, array $enabled): array
    {
        $base = realpath($base) ?: $base;
        self::packageName($name);
        foreach (self::extensions($base, $enabled) as $row) {
            if ($row['name'] !== $name) continue;
            if ($row['protected']) throw new \RuntimeException('This package is protected. Use the terminal for its removal.');
            $path = $base.'/vendor/'.$name;
            if (is_link($path) || !is_dir($path) || strpos((string) realpath($path), $base.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR) !== 0) throw new \RuntimeException('External/path-linked extension packages are not supported.');
            return $row;
        }
        throw new \RuntimeException('Installed extension not found.');
    }

    public static function fingerprint(string $base, array $enabled): string
    {
        sort($enabled); $parts = [json_encode($enabled)];
        foreach (['composer.json', 'composer.lock', 'vendor/composer/installed.json'] as $file) {
            if (!is_file($base.'/'.$file)) throw new \RuntimeException('Required package file missing: '.$file);
            $parts[] = hash_file('sha256', $base.'/'.$file);
        }
        return hash('sha256', implode('|', $parts));
    }

    public static function codeFingerprint(string $base, string $package): string
    {
        $folder = $base.'/vendor/'.self::packageName($package); $hashes = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink()) throw new \RuntimeException('Linked extension content cannot be reviewed safely.');
            if (!$file->isFile()) continue;
            if (count($hashes) >= 10000) throw new \RuntimeException('Extension has too many files for this preview.');
            $hashes[substr($file->getPathname(), strlen($folder))] = hash_file('sha256', $file->getPathname());
        }
        ksort($hashes);
        return hash('sha256', json_encode($hashes));
    }
}
