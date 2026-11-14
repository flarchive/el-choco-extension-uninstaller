<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function fingerprint(string $bytes): string { return hash('sha256', str_replace("\r\n", "\n", $bytes)); }
function bytes(string $file): string {
    $data = @file_get_contents($file);
    if ($data === false) throw new RuntimeException('Cannot read: '.basename($file));
    return $data;
}
function writeFileAtomically(string $target, string $data, int $mode): void {
    if (is_link($target)) throw new RuntimeException('Linked target rejected.');
    $temp=$target.'.path-fix-'.bin2hex(random_bytes(6));
    if (file_put_contents($temp,$data,LOCK_EX)!==strlen($data)) throw new RuntimeException('Incomplete write.');
    if (!chmod($temp,$mode)) { @unlink($temp);throw new RuntimeException('Cannot set file permissions.'); }
    if (!rename($temp,$target)) { @unlink($temp);throw new RuntimeException('Cannot replace target.'); }
}
function privateDirectory(string $dir): void {
    if (is_link($dir)) throw new RuntimeException('Linked backup directory rejected.');
    if (!is_dir($dir) && !mkdir($dir,0700,true)) throw new RuntimeException('Cannot create backup directory.');
    if (!chmod($dir,0700)) throw new RuntimeException('Cannot protect backup directory.');
}
$lock=null;$workerLock=null;
try {
    $action=$argv[1]??'--check';$restore=null;
    if (preg_match('/^--restore=(path-metadata-[0-9]{8}-[0-9]{6}-[a-f0-9]{8})$/D',$action,$m))$restore=$m[1];
    if (count($argv)>2 || (!in_array($action,['--check','--apply'],true)&&$restore===null)) throw new RuntimeException('Usage: php apply.php --check | --apply | --restore=BACKUP_ID');
    $base=realpath(getcwd());
    if (!$base || !is_file($base.'/flarum')) throw new RuntimeException('Run from the actual Flarum directory.');
    $target=$base.'/vendor/el-choco/extension-uninstaller/src/PlanService.php';
    $resolved=realpath($target);
    if (!$resolved || is_link($target) || strpos($resolved,$base.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)!==0) throw new RuntimeException('Installed, non-linked uninstaller file not found.');
    $manifest=json_decode(bytes(__DIR__.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
    $current=bytes($target);$hash=fingerprint($current);$payload=bytes(__DIR__.'/PlanService.php');
    if (fingerprint($payload)!==$manifest['after']) throw new RuntimeException('Hotfix payload checksum mismatch.');
    if (!in_array($hash,array_merge($manifest['before'],[$manifest['after']]),true)) throw new RuntimeException('Unknown installed file. No changes made. SHA-256: '.$hash);
    if ($action==='--check') { echo "Check passed. ".($hash===$manifest['after']?'Fix already installed.':'Known RC.1 file; fix can be applied.')."\n";exit; }
    $uid=function_exists('posix_geteuid')?posix_geteuid():null;
    if ($uid===null&&preg_match('/^Uid:\s+\d+\s+(\d+)/m',(string)@file_get_contents('/proc/self/status'),$u))$uid=(int)$u[1];
    if ($uid===null||$uid===0)throw new RuntimeException('Use the same non-root user as PHP-FPM.');
    if (!is_writable($target)||!is_writable(dirname($target)))throw new RuntimeException('Installed file/directory is not writable.');
    $storage=realpath($base.'/storage');if(!$storage)throw new RuntimeException('Storage directory missing.');
    $parent=$storage.'/extension-uninstaller';privateDirectory($parent);privateDirectory($parent.'/backups');
    foreach (['.path-fix.lock','worker.lock'] as $name) if(is_link($parent.'/'.$name))throw new RuntimeException('Linked lock file rejected.');
    $lock=fopen($parent.'/.path-fix.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another hotfix process is active.');
    chmod($parent.'/.path-fix.lock',0600);
    $workerLock=fopen($parent.'/worker.lock','c');
    if(!$workerLock||!flock($workerLock,LOCK_EX|LOCK_NB))throw new RuntimeException('Worker is busy. Finish active jobs and stop its service first.');
    chmod($parent.'/worker.lock',0600);
    if(bytes($target)!==$current)throw new RuntimeException('Installed file changed during the check.');
    if ($restore!==null) {
        $folder=$parent.'/backups/'.$restore;
        if (is_link($folder)||is_link($folder.'/PlanService.php')||is_link($folder.'/manifest.json'))throw new RuntimeException('Linked backup rejected.');
        $meta=json_decode(bytes($folder.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
        $original=bytes($folder.'/PlanService.php');
        if (hash('sha256',$original)!==$meta['sha256'] || !in_array(fingerprint($original),$manifest['before'],true))throw new RuntimeException('Invalid original-file backup.');
        writeFileAtomically($target,$original,((int)$meta['mode'])&0777);
        echo "Original file restored. Restart the uninstaller worker.\n";exit;
    }
    if ($hash===$manifest['after']){echo "Fix already installed. No files changed.\n";exit;}
    $id='path-metadata-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));$folder=$parent.'/backups/'.$id;
    privateDirectory($folder);$mode=fileperms($target)&0777;
    writeFileAtomically($folder.'/PlanService.php',$current,0600);
    writeFileAtomically($folder.'/manifest.json',json_encode(['sha256'=>hash('sha256',$current),'mode'=>$mode],JSON_THROW_ON_ERROR),0600);
    echo "Backup: ".$folder."\nRestore: php ".__FILE__." --restore=".$id."\n";
    writeFileAtomically($target,$payload,$mode);
    echo "Fix installed. Restart the uninstaller worker and request a new preview.\n";
} catch(Throwable $e) { fwrite(STDERR,$e->getMessage()."\n");exit(1); }
finally {foreach([$workerLock,$lock] as $handle)if(is_resource($handle)){flock($handle,LOCK_UN);fclose($handle);}}
