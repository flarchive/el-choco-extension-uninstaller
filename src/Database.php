<?php
namespace ElChoco\ExtensionUninstaller;

final class Database
{
    private array $config;
    private \PDO $pdo;
    private string $prefix;

    public function __construct(string $base)
    {
        $config = require $base.'/config.php';
        $this->config = $config['database'];
        $db = $this->config;
        if (!in_array($db['driver'] ?? 'mysql', ['mysql', 'mariadb'], true)) throw new \RuntimeException('This preview supports MySQL/MariaDB installations only.');
        $this->prefix = $db['prefix'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9_]*$/D', $this->prefix)) throw new \RuntimeException('Unsupported database prefix.');
        $dsn = 'mysql:host='.$db['host'].';port='.($db['port'] ?? 3306).';dbname='.$db['database'].';charset=utf8mb4';
        if (!empty($db['unix_socket'])) $dsn = 'mysql:unix_socket='.$db['unix_socket'].';dbname='.$db['database'].';charset=utf8mb4';
        $this->pdo = new \PDO($dsn, $db['username'], $db['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    public function enabled(): array
    {
        $q = $this->pdo->prepare('SELECT value FROM `'.$this->prefix.'settings` WHERE `key` = ?');
        $q->execute(['extensions_enabled']);
        $value = json_decode($q->fetchColumn() ?: '[]', true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new \RuntimeException('Invalid extension state.');
        return $value;
    }

    public function backup(string $folder, ProcessExecutor $executor, callable $log): array
    {
        $db = $this->config;
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $db['database'])) throw new \RuntimeException('Unsupported database name for export.');
        $q = $this->pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB'");
        $q->execute([$db['database']]);
        if ($q->fetchColumn() !== false) throw new \RuntimeException('Purge requires an InnoDB database for a consistent transactional export.');
        $binary = $executor->findDump();
        if (!$binary) throw new \RuntimeException('mariadb-dump or mysqldump is required before data cleanup. No cleanup was started.');
        $quote = static function ($value) {
            if (preg_match('/[\x00-\x1f]/', (string) $value)) throw new \RuntimeException('Unsupported control character in dump configuration.');
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value).'"';
        };
        $ini = "[client]\nuser=".$quote($db['username'])."\npassword=".$quote($db['password'])."\n";
        if (!empty($db['unix_socket'])) $ini .= 'socket='.$quote($db['unix_socket'])."\n";
        else $ini .= 'host='.$quote($db['host'])."\nport=".(int) ($db['port'] ?? 3306)."\n";
        $credentials = $folder.'/dump-client.cnf';
        Files::write($credentials, $ini);
        $dump = $folder.'/database.sql';
        $handle = fopen($dump, 'xb');
        if (!$handle) { @unlink($credentials); throw new \RuntimeException('Cannot create database backup.'); }
        if (!chmod($dump, 0600)) { fclose($handle); @unlink($credentials); @unlink($dump); throw new \RuntimeException('Cannot protect database backup.'); }
        $bytes = 0;
        try {
            $exit = $executor->run([$binary, '--defaults-extra-file='.$credentials, '--single-transaction', '--quick', '--routines', '--events', '--triggers', '--hex-blob', '--skip-lock-tables', $db['database']],
                function ($type, $chunk) use ($handle, &$bytes, $log) {
                    if ($type !== 'out') { $log($chunk); return; }
                    $offset = 0;
                    while ($offset < strlen($chunk)) {
                        $written = fwrite($handle, substr($chunk, $offset));
                        if (!$written) throw new \RuntimeException('Database backup write failed.');
                        $offset += $written; $bytes += $written;
                    }
                });
            if ($exit !== 0 || $bytes < 100 || !fflush($handle)) throw new \RuntimeException('Database export failed; data cleanup was not started.');
        } finally { fclose($handle); @unlink($credentials); }
        return ['file' => 'database.sql', 'bytes' => $bytes, 'sha256' => hash_file('sha256', $dump), 'restoreTested' => false];
    }
}
