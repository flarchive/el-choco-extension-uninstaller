<?php
namespace ElChoco\ExtensionUninstaller;

final class Files
{
    public static function json(string $file): array
    {
        if (is_link($file) || !is_file($file)) throw new \RuntimeException('Missing or unsafe file: '.basename($file));
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Invalid JSON: '.basename($file));
        return $data;
    }

    public static function write(string $file, string $bytes, int $mode = 0600): void
    {
        if (is_link($file)) throw new \RuntimeException('Symbolic-link target rejected.');
        $tmp = $file.'.'.bin2hex(random_bytes(6)).'.tmp';
        $handle = @fopen($tmp, 'xb');
        if (!$handle) throw new \RuntimeException('Cannot create file: '.basename($file));
        try {
            if (!chmod($tmp, $mode)) throw new \RuntimeException('Cannot protect private file.');
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if (!$written) throw new \RuntimeException('Incomplete write.');
                $offset += $written;
            }
            if (!fflush($handle)) throw new \RuntimeException('Cannot flush file.');
        } catch (\Throwable $e) { fclose($handle); @unlink($tmp); throw $e; }
        fclose($handle);
        if (!rename($tmp, $file)) { @unlink($tmp); throw new \RuntimeException('Cannot replace file atomically.'); }
    }

    public static function directory(string $folder): void
    {
        if (is_link($folder)) throw new \RuntimeException('Symbolic-link directory rejected.');
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) throw new \RuntimeException('Cannot create private directory.');
        if (!chmod($folder, 0700)) throw new \RuntimeException('Cannot protect private directory.');
    }
}
