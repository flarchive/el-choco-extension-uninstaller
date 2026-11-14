<?php
require $argv[1];
foreach(glob(__DIR__.'/../src/*.php') as $file)require_once $file;
use ElChoco\ExtensionUninstaller\Files;
use ElChoco\ExtensionUninstaller\JobStore;
use ElChoco\ExtensionUninstaller\JobRunner;
$base=sys_get_temp_dir().'/uninstaller-lock-'.bin2hex(random_bytes(5));
Files::directory($base.'/storage');Files::directory($base.'/vendor/composer');
Files::write($base.'/vendor/composer/installed.json','{"packages":[]}');
$store=new JobStore($base.'/storage');$runner=new JobRunner($store,$base,$base.'/storage',$base.'/assets');
$method=new ReflectionMethod($runner,'consoleLock');
$checks=0;
function check($value,$label){global $checks;$checks++;if(!$value)throw new RuntimeException($label);}
function rejects(callable $fn){try{$fn();return false;}catch(Throwable $e){return true;}}
check($method->invoke($runner)===null,'Works without Admin Console');
$console=$base.'/storage/admin-console';Files::directory($console.'/jobs');
$lock=fopen($console.'/worker.lock','c');flock($lock,LOCK_EX);
check(rejects(function()use($method,$runner){$method->invoke($runner); }),'Busy Admin Console worker excludes uninstaller');
flock($lock,LOCK_UN);fclose($lock);
$own=$method->invoke($runner);check(is_resource($own),'Can share the idle console lock');
$other=fopen($console.'/worker.lock','c');check(!flock($other,LOCK_EX|LOCK_NB),'Console cannot start concurrently');fclose($other);
flock($own,LOCK_UN);fclose($own);
foreach(['queued','running','interrupted'] as $status){
 Files::write($console.'/jobs/'.str_repeat('a',32).'.json',json_encode(['status'=>$status]));
 check(rejects(function()use($method,$runner){$method->invoke($runner); }),'Open console job blocks start');
}
Files::write($console.'/jobs/'.str_repeat('a',32).'.json',json_encode(['status'=>'succeeded']));
$own=$method->invoke($runner);check(is_resource($own),'Completed console job does not block');flock($own,LOCK_UN);fclose($own);
echo "$checks optional Admin Console lock coordination checks passed.\n";
