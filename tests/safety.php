<?php
require $argv[1];
foreach (glob(__DIR__.'/../src/*.php') as $file) require_once $file;

use ElChoco\ExtensionUninstaller\Files;
use ElChoco\ExtensionUninstaller\Inventory;
use ElChoco\ExtensionUninstaller\JobStore;
use ElChoco\ExtensionUninstaller\PlanService;
use ElChoco\ExtensionUninstaller\ProcessExecutor;
use ElChoco\ExtensionUninstaller\PackageSnapshot;

$checks = 0;
function check($value, $message) { global $checks; $checks++; if (!$value) throw new RuntimeException($message); }
function rejects(callable $fn) { try { $fn(); return false; } catch (Throwable $e) { return true; } }
function jsonFile($file, $data) { Files::write($file, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)); }
$base = sys_get_temp_dir().'/uninstaller-safety-'.bin2hex(random_bytes(5));
foreach (['vendor/composer', 'vendor/acme/example/migrations', 'storage', 'public/assets'] as $folder) Files::directory($base.'/'.$folder);
Files::write($base.'/vendor/acme/example/extend.php', '<?php return [];');
Files::write($base.'/vendor/acme/example/migrations/001.php', '<?php return [];');
$target = ['name'=>'acme/example','version'=>'1.0.0','type'=>'flarum-extension'];
$helper = ['name'=>'acme/helper','version'=>'1.0.0','type'=>'library'];
$core = ['name'=>'flarum/core','version'=>'2.0.2','type'=>'library'];
$self = ['name'=>'el-choco/extension-uninstaller','version'=>'0.1.0-rc.1','type'=>'flarum-extension'];
$before = ['packages'=>[$target,$helper,$core,$self]];
$after = ['packages'=>[$core,$self]];
jsonFile($base.'/composer.json',['name'=>'acme/forum','require'=>['acme/example'=>'*','el-choco/extension-uninstaller'=>'*']]);
jsonFile($base.'/composer.lock',$before);
jsonFile($base.'/vendor/composer/installed.json',$before);
check(Inventory::id('acme/flarum-ext-example')==='acme-example','Flarum identifier mapping');
foreach (['../x','--help','a/b;id','a/b c','a/b/c','a/../../file'] as $bad) check(rejects(function () use ($bad) { Inventory::packageName($bad); }),'Reject unsafe package');
check(count(PlanService::changes($before,$after,'acme/example'))===2,'Preview includes orphan library');
$updated=$after; $updated['packages'][0]['version']='2.0.3';
check(rejects(function () use ($before,$updated) { PlanService::changes($before,$updated,'acme/example'); }),'Reject unrelated updates');
check(rejects(function () use ($before) { PlanService::changes($before,['packages'=>[]],'acme/example'); }),'Protect core and self');
$other=$before; $other['packages'][]=['name'=>'acme/other','version'=>'1','type'=>'flarum-extension'];
check(rejects(function () use ($other,$after) { PlanService::changes($other,$after,'acme/example'); }),'No other extension removals');
check(rejects(function () { PlanService::mode('drop-everything'); }),'Mode allowlist');
$store=new JobStore($base.'/storage');
$executor=new class($base,$store->folder()) extends ProcessExecutor {
    public function composer(): array { return ['test-composer']; }
    public function findDump(): ?string { return null; }
    public function run(array $argv, callable $output, ?string $cwd=null): int {
        $root=Files::json($cwd.'/composer.json'); unset($root['require']['acme/example']); jsonFile($cwd.'/composer.json',$root);
        $lock=Files::json($cwd.'/composer.lock'); $lock['packages']=array_values(array_filter($lock['packages'],function ($p) { return !in_array($p['name'],['acme/example','acme/helper'],true); }));
        jsonFile($cwd.'/composer.lock',$lock); $output('out','simulated solver'); return 0;
    }
};
$plans=new PlanService($base,$store,$executor);
$originalRoot=file_get_contents($base.'/composer.json');
$plan=$plans->create('acme/example','keep','1',['acme-example'],function ($s) {});
check(file_get_contents($base.'/composer.json')===$originalRoot,'Live root unchanged during preview');
check(count($plan['removed'])===2,'Public removal list');
check(!isset($plan['fingerprint']),'No private plan internals returned');
check($plans->validate($plan['id'],'1',['acme-example'])['package']==='acme/example','Plan validates');
check(rejects(function () use ($plans,$plan) { $plans->validate($plan['id'],'2',['acme-example']); }),'Actor-bound preview');
check(rejects(function () use ($plans,$plan) { $plans->validate($plan['id'],'1',[]); }),'Activation change invalidates plan');
Files::write($base.'/vendor/acme/example/migrations/001.php','<?php echo "changed";');
check(rejects(function () use ($plans,$plan) { $plans->validate($plan['id'],'1',['acme-example']); }),'Migration/code changes invalidate plan');
Files::write($base.'/vendor/acme/example/migrations/001.php','<?php return [];');
$saved=$store->record($plan['id']); $saved['expires']=time()-1; $store->saveRecord($plan['id'],$saved);
check(rejects(function () use ($plans,$plan) { $plans->validate($plan['id'],'1',['acme-example']); }),'Expired plan rejected');
$saved['expires']=time()+600; $saved['usedBy']='job'; $store->saveRecord($plan['id'],$saved);
check(rejects(function () use ($plans,$plan) { $plans->validate($plan['id'],'1',['acme-example']); }),'Used plan cannot execute twice');
check(rejects(function () use ($plans) { $plans->create('acme/example','purge','1',[],function ($s) {}); }),'Purge blocked without dump program');
$disable=$plans->create('acme/example','disable','1',[],function ($s) {});
check($disable['removed']===[],'Disable removes no packages');
$job=$store->enqueue('preview','1',1000,str_repeat('a',32));
check($store->enqueue('preview','1',1000,$job['id'])['id']===$job['id'],'Idempotent queue');
check(rejects(function () use ($store) { $store->enqueue('different','1',1000,str_repeat('a',32)); }),'Request ID cannot change payload');
check(rejects(function () use ($store) { $store->enqueue('preview','2',1000,str_repeat('a',32)); }),'Request ID actor binding');
check(rejects(function () use ($store) { $store->enqueue('preview','1',1000,str_repeat('b',32)); }),'Only one open job');
check(rejects(function () use ($store) { $store->record('../../config.php'); }),'Plan traversal rejected');
$lock=$store->lock('worker'); check($lock!==null && $store->lock('worker')===null,'Single worker lock'); flock($lock,LOCK_UN); fclose($lock);
$snapshot=PackageSnapshot::create($base,$base.'/storage',null);
check(file_get_contents($base.'/storage/extension-uninstaller/backups/'.$snapshot.'/composer.json')===$originalRoot,'Exact root snapshot');
check(!array_key_exists('output',$store->history()[0]),'History omits raw output');
check(rejects(function () { Inventory::target(__DIR__,'el-choco/extension-uninstaller',[]); }),'Unavailable/protected target rejected');
echo Composer\InstalledVersions::getPrettyVersion('flarum/core').": $checks safety checks passed.\n";
