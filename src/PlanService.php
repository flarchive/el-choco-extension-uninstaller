<?php
namespace ElChoco\ExtensionUninstaller;

final class PlanService
{
    private string $base;
    private JobStore $store;
    private ProcessExecutor $executor;

    public function __construct(string $base, JobStore $store, ProcessExecutor $executor)
    {
        $this->base = $base; $this->store = $store; $this->executor = $executor;
    }

    public static function mode(string $mode): string
    {
        if (!in_array($mode, ['disable', 'keep', 'purge'], true)) throw new \InvalidArgumentException('Unknown action.');
        return $mode;
    }

    public static function confirmationWord(string $mode): string
    {
        return self::mode($mode) === 'disable' ? 'DISABLE' : 'DELETE';
    }

    public static function changes(array $before, array $after, string $target): array
    {
        $index = static function ($lock) {
            $result = [];
            foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $p) $result[$p['name']] = $p;
            return $result;
        };
        $old = $index($before); $new = $index($after); $removed = [];
        foreach ($new as $name => $p) {
            if (!isset($old[$name]) || $old[$name] != $p) throw new \RuntimeException('The proposal installs or changes another package. No removal was approved.');
        }
        foreach ($old as $name => $p) {
            if (isset($new[$name])) continue;
            if (in_array($name, Inventory::PROTECTED, true)) throw new \RuntimeException('The proposal removes a protected package.');
            if (($p['type'] ?? '') === 'flarum-extension' && $name !== $target) throw new \RuntimeException('The proposal removes another extension. Remove it separately.');
            $removed[] = ['name' => $name, 'version' => $p['version'] ?? '', 'extension' => ($p['type'] ?? '') === 'flarum-extension'];
        }
        if (isset($new[$target]) || !isset($old[$target])) throw new \RuntimeException('The requested package is not removed by the proposal.');
        return $removed;
    }

    public function create(string $package, string $mode, string $actor, array $enabled, callable $output): array
    {
        self::mode($mode);
        $row = Inventory::target($this->base, $package, $enabled);
        if ($mode !== 'disable' && (!$row['direct'] || $row['dependents'])) throw new \RuntimeException('Removal is blocked by dependent packages or an indirect root requirement.');
        if ($mode === 'disable') {
            $catalog = Inventory::extensions($this->base, $enabled);
            foreach ($catalog as $other) {
                if ($other['enabled'] && in_array($other['name'], $row['dependents'], true)) throw new \RuntimeException('Disable dependent extensions first: '.$other['name']);
            }
        }
        if ($mode === 'purge' && !$this->executor->findDump()) throw new \RuntimeException('Install mariadb-dump or mysqldump in the worker environment before requesting database cleanup.');
        $id = bin2hex(random_bytes(16));
        $draft = $this->store->folder().'/plans/'.$id;
        Files::directory($draft);
        $fingerprint = Inventory::fingerprint($this->base, $enabled);
        $codeFingerprint = Inventory::codeFingerprint($this->base, $package);
        $removed = [];
        if ($mode !== 'disable') {
            $installed = Files::json($this->base.'/vendor/composer/installed.json');
            if (!empty($installed['dev-package-names'])) throw new \RuntimeException('Remove installed development dependencies in the terminal first; this tool runs Composer with --no-dev.');
            Inventory::assertLocked($this->base);
            $root = Files::json($this->base.'/composer.json');
            if (($root['config']['vendor-dir'] ?? 'vendor') !== 'vendor') throw new \RuntimeException('Custom Composer vendor directories are not supported.');
            foreach ($root['repositories'] ?? [] as $repo) {
                if (is_array($repo) && ($repo['type'] ?? '') === 'path' && !preg_match('~^(?:/|[A-Za-z]:[\\\\/])~', $repo['url'] ?? '')) throw new \RuntimeException('Relative Composer path repositories cannot be previewed safely. Use absolute paths or remove obsolete local repositories.');
            }
            Files::write($draft.'/composer.json', (string) file_get_contents($this->base.'/composer.json'));
            Files::write($draft.'/composer.lock', (string) file_get_contents($this->base.'/composer.lock'));
            $argv = array_merge($this->executor->composer(), ['remove', $package, '--no-install', '--update-no-dev', '--minimal-changes', '--no-scripts', '--no-plugins', '--no-interaction', '--no-progress', '--no-ansi']);
            if ($this->executor->run($argv, function ($type, $chunk) use ($output) { $output($chunk); }, $draft) !== 0) throw new \RuntimeException('Composer could not prepare the removal. The forum package files were not changed.');
            $expected = $root; unset($expected['require'][$package]); unset($expected['require-dev'][$package]);
            $actual = Files::json($draft.'/composer.json');
            if (empty($expected['require'])) unset($expected['require']);
            if (empty($actual['require'])) unset($actual['require']);
            if (empty($expected['require-dev'])) unset($expected['require-dev']);
            if (empty($actual['require-dev'])) unset($actual['require-dev']);
            if ($expected != $actual) throw new \RuntimeException('Composer changed unrelated root configuration. Proposal rejected.');
            $removed = self::changes(Files::json($this->base.'/composer.lock'), Files::json($draft.'/composer.lock'), $package);
        }
        if ($fingerprint !== Inventory::fingerprint($this->base, $enabled)) throw new \RuntimeException('The installation changed while preparing the proposal. Request a fresh preview.');
        $migrationFiles = [];
        foreach (glob($this->base.'/vendor/'.$package.'/migrations/*.php') ?: [] as $file) $migrationFiles[] = basename($file);
        $plan = ['id' => $id, 'actor' => $actor, 'created' => time(), 'expires' => time() + 600, 'usedBy' => null,
            'package' => $package, 'mode' => $mode, 'extension' => $row, 'removed' => $removed,
            'migrationFiles' => $migrationFiles, 'fingerprint' => $fingerprint,
            'codeFingerprint' => $codeFingerprint,
            'filesBefore' => array_map(function ($file) { return hash_file('sha256', $this->base.'/'.$file); }, ['composer.json', 'composer.lock', 'vendor/composer/installed.json']),
            'draftHashes' => $mode === 'disable' ? [] : [hash_file('sha256', $draft.'/composer.json'), hash_file('sha256', $draft.'/composer.lock')]];
        $this->store->saveRecord($id, $plan);
        return self::publicPlan($plan);
    }

    public static function publicPlan(array $plan): array
    {
        return array_merge(array_intersect_key($plan, array_flip(['id', 'expires', 'package', 'mode', 'extension', 'removed', 'migrationFiles'])), ['confirmationWord' => self::confirmationWord($plan['mode'])]);
    }

    public function validate(string $id, string $actor, array $enabled): array
    {
        $plan = $this->store->record($id);
        if (!$plan || $plan['actor'] !== $actor || $plan['expires'] < time() || $plan['usedBy'] !== null) throw new \RuntimeException('This preview expired, was used, or belongs to another administrator. Request a new preview.');
        if ($plan['fingerprint'] !== Inventory::fingerprint($this->base, $enabled)) throw new \RuntimeException('Packages or activation state changed. Request a new preview.');
        Inventory::target($this->base, $plan['package'], $enabled);
        if ($plan['codeFingerprint'] !== Inventory::codeFingerprint($this->base, $plan['package'])) throw new \RuntimeException('Extension files changed after the preview.');
        if ($plan['mode'] !== 'disable') {
            $folder = $this->store->folder().'/plans/'.$id;
            foreach (['composer.json', 'composer.lock'] as $i => $file) {
                if (is_link($folder.'/'.$file) || hash_file('sha256', $folder.'/'.$file) !== $plan['draftHashes'][$i]) throw new \RuntimeException('The proposal files changed.');
            }
        }
        return $plan;
    }
}
