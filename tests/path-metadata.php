<?php
require $argv[1];
foreach (glob(__DIR__.'/../src/*.php') as $file) require_once $file;
use ElChoco\ExtensionUninstaller\Files;
use ElChoco\ExtensionUninstaller\PlanService;
use ElChoco\ExtensionUninstaller\Inventory;
$base = sys_get_temp_dir().'/uninstaller-path-'.bin2hex(random_bytes(5));
$draft = $base.'/storage/extension-uninstaller/plans/draft';
foreach ([$draft, $base.'/workbench/picker', $base.'/workbench/other'] as $folder) Files::directory($folder);
$target = ['name'=>'flarum/gdpr','version'=>'v1.8.2','type'=>'flarum-extension'];
$package = ['name'=>'el-choco/flarum-fa-picker','version'=>'dev-main','type'=>'flarum-extension',
    'dist'=>['type'=>'path','url'=>'workbench/picker','reference'=>'unchanged-reference'],
    'transport-options'=>['symlink'=>false,'relative'=>true], 'require'=>['flarum/core'=>'^1.8 || ^2.0']];
$absolute = $package;
$absolute['dist']['url']=$base.'/workbench/picker';
$absolute['transport-options']['relative']=false;
$old=['packages'=>[$target,$package]];
$new=['packages'=>[$absolute]];
$checks=0;
function check($condition,$label){global $checks;$checks++;if(!$condition)throw new RuntimeException($label);}
function rejected($old,$new,$base,$draft){try{PlanService::changes($old,PlanService::normalizePathPackages($old,$new,$base,$draft),'flarum/gdpr');return false;}catch(RuntimeException $e){return true;}}
$normalized=PlanService::normalizePathPackages($old,$new,$base,$draft);
check($normalized['packages'][0]===$package,'Original metadata preserved for the same directory');
check(count(PlanService::changes($old,$normalized,'flarum/gdpr'))===1,'Only requested extension removed');
check($new['packages'][0]===$absolute,'Input data not mutated');
$relative=$absolute;$relative['dist']['url']='../../../../workbench/picker';$relative['transport-options']['relative']=true;
check(!rejected($old,['packages'=>[$relative]],$base,$draft),'After paths are anchored to preview directory');
$wrongAnchor=$absolute;$wrongAnchor['dist']['url']='../workbench/picker';
check(rejected($old,['packages'=>[$wrongAnchor]],$base,$draft),'Wrong relative anchor rejected');
foreach (['version','reference','require','source','autoload','scripts','symlink','new-transport','different-source','missing-source','non-path','remote-url','invalid-flag'] as $case) {
    $changed=$absolute;
    switch($case){
        case 'version':$changed['version']='dev-other';break;
        case 'reference':$changed['dist']['reference']='new-code-reference';break;
        case 'require':$changed['require']['flarum/core']='^3.0';break;
        case 'source':$changed['source']=['type'=>'git','reference'=>'new-source'];break;
        case 'autoload':$changed['autoload']=['files'=>['new.php']];break;
        case 'scripts':$changed['scripts']=['post-install-cmd'=>'new command'];break;
        case 'symlink':$changed['transport-options']['symlink']=true;break;
        case 'new-transport':$changed['transport-options']['new-option']=true;break;
        case 'different-source':$changed['dist']['url']=$base.'/workbench/other';break;
        case 'missing-source':$changed['dist']['url']=$base.'/workbench/missing';break;
        case 'non-path':$changed['dist']['type']='zip';break;
        case 'remote-url':$changed['dist']['url']='https://example.invalid/workbench/picker';break;
        case 'invalid-flag':$changed['transport-options']['relative']='false';break;
    }
    check(rejected($old,['packages'=>[$changed]],$base,$draft),'Reject unrelated change: '.$case);
}
$withoutFlag=$package;unset($withoutFlag['transport-options']['relative']);
check(!rejected(['packages'=>[$target,$withoutFlag]],$new,$base,$draft),'Missing old relative flag can be restored exactly');
$withoutOptions=$package;unset($withoutOptions['transport-options']);
$afterOnlyRelative=$absolute;$afterOnlyRelative['transport-options']=['relative'=>false];
check(!rejected(['packages'=>[$target,$withoutOptions]],['packages'=>[$afterOnlyRelative]],$base,$draft),'No transport object is added to the final lock');
$zip=$package;$zip['dist']['type']='zip';
check(rejected(['packages'=>[$target,$zip]],$new,$base,$draft),'Zip to path transition rejected');
Files::directory($base.'/vendor/composer');
Files::write($base.'/vendor/composer/installed.json',json_encode($old));
Files::write($base.'/composer.lock',json_encode(['packages'=>[$target,$absolute]]));
Inventory::assertLocked($base);check(true,'Installed/lock path representation differences are accepted');
$bad=$absolute;$bad['dist']['reference']='different-code';
Files::write($base.'/composer.lock',json_encode(['packages'=>[$target,$bad]]));
$blocked=false;try{Inventory::assertLocked($base);}catch(RuntimeException $e){$blocked=true;}
check($blocked,'Installed/lock reference changes remain blocked');
$bad=$absolute;$bad['dist']['url']=$base.'/workbench/other';
Files::write($base.'/composer.lock',json_encode(['packages'=>[$target,$bad]]));
$blocked=false;try{Inventory::assertLocked($base);}catch(RuntimeException $e){$blocked=true;}
check($blocked,'Installed/lock different directory remains blocked');
echo "$checks path-metadata safety checks passed.\n";
