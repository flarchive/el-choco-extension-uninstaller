<?php
namespace ElChoco\ExtensionUninstaller;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class ProcessExecutor
{
    private string $base;
    private string $folder;
    private $pulse;

    public function __construct(string $base, string $folder, ?callable $pulse = null)
    {
        $this->base = $base; $this->folder = $folder; $this->pulse = $pulse;
    }

    public function composer(): array
    {
        $path = (new ExecutableFinder())->find('composer', null, explode(PATH_SEPARATOR, RuntimeDiagnostics::commandPath()));
        if (!$path) throw new \RuntimeException('Composer was not found in the worker PATH.');
        return [PHP_BINARY, $path];
    }

    public function findDump(): ?string
    {
        $finder = new ExecutableFinder();
        $paths = explode(PATH_SEPARATOR, RuntimeDiagnostics::commandPath());
        return $finder->find('mariadb-dump', null, $paths) ?: $finder->find('mysqldump', null, $paths);
    }

    public function run(array $argv, callable $output, ?string $cwd = null): int
    {
        $env = ['PATH' => RuntimeDiagnostics::commandPath(), 'COMPOSER_NO_INTERACTION' => '1',
            'COMPOSER_CACHE_DIR' => $this->folder.'/composer-cache', 'TERM' => 'dumb',
            'COMPOSER' => false, 'COMPOSER_VENDOR_DIR' => false, 'COMPOSER_BIN_DIR' => false,
            'COMPOSER_IGNORE_PLATFORM_REQS' => false, 'COMPOSER_IGNORE_PLATFORM_REQ' => false];
        if (is_file('/data/extensions/composer-home/config.json')) $env['COMPOSER_HOME'] = '/data/extensions/composer-home';
        $process = new Process($argv, $cwd ?: $this->base, $env);
        $process->setTimeout(null);
        $process->disableOutput();
        $process->start($output);
        while ($process->isRunning()) {
            if ($this->pulse) ($this->pulse)();
            usleep(100000);
        }
        return $process->wait();
    }
}
