<?php
require $argv[1];
foreach (glob(__DIR__.'/../src/*.php') as $file) require_once $file;
use ElChoco\ExtensionUninstaller\Files;
use ElChoco\ExtensionUninstaller\JobStore;
use ElChoco\ExtensionUninstaller\UninstallerController;
use Flarum\Foundation\Paths;
use Flarum\Group\Group;
use Flarum\Http\RequestUtil;
use Flarum\Http\Middleware\CheckCsrfToken;
use Flarum\Http\Exception\TokenMismatchException;
use Flarum\User\Access\Gate;
use Flarum\User\Guest;
use Flarum\User\User;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Laminas\Diactoros\ServerRequest;
$checks=0;
function check($value,$label) { global $checks; $checks++; if (!$value) throw new RuntimeException($label); }
function denied(callable $fn,string $type) { try { $fn(); } catch(Throwable $e) { if ($e instanceof $type) return true; throw $e; } return false; }
$base=sys_get_temp_dir().'/uninstaller-auth-'.bin2hex(random_bytes(5));
Files::directory($base.'/vendor/composer'); Files::directory($base.'/storage'); Files::directory($base.'/public');
Files::write($base.'/composer.json','{"require":{}}'); Files::write($base.'/vendor/composer/installed.json','{"packages":[]}');
User::setGate(new Gate(new Container(),[]));
$member=new User(); $member->id=2; $member->setRelation('groups',new Collection());
$group=new Group(); $group->id=Group::ADMINISTRATOR_ID;
$admin=new User(); $admin->id=1; $admin->setRelation('groups',new Collection([$group]));
$guest=new Guest(); $guest->setAttribute('groups',new Collection());
$settingsClass=Flarum\Settings\MemoryCacheSettingsRepository::class;
$settings=(new ReflectionClass($settingsClass))->newInstanceWithoutConstructor();
(new ReflectionProperty($settingsClass,'cache'))->setValue($settings,['extensions_enabled'=>'[]']);
(new ReflectionProperty($settingsClass,'isCached'))->setValue($settings,true);
$controller=new UninstallerController(new Paths(['base'=>$base,'public'=>$base.'/public','storage'=>$base.'/storage']),$settings,function(){return 1000;});
$csrf=new CheckCsrfToken([]);
$session=new class { public function token(){return 'csrf-token';} };
function req($actor,$method,$body=[],$token='csrf-token') {
    global $session;
    $r=(new ServerRequest())->withMethod($method)->withAttribute('session',$session)->withAttribute('routeName','elchoco.extension-uninstaller.run')->withParsedBody($body);
    $r=RequestUtil::withActor($r,$actor);
    return $token===null?$r:$r->withHeader('X-CSRF-Token',$token);
}
foreach ([$guest,$member] as $actor) foreach (['GET','POST'] as $method) {
    $r=req($actor,$method);
    check(denied(function()use($csrf,$controller,$r){$csrf->process($r,$controller);},PermissionDeniedException::class),'Non-admin denied');
}
check(!is_dir($base.'/storage/extension-uninstaller'),'No state before authorization');
foreach ([null,'wrong'] as $token) {
    $r=req($admin,'POST',[],$token);
    check(denied(function()use($csrf,$controller,$r){$csrf->process($r,$controller);},TokenMismatchException::class),'CSRF required');
}
check(!is_dir($base.'/storage/extension-uninstaller'),'No state on CSRF failure');
$response=$csrf->process(req($admin,'GET'),$controller);
check($response->getStatusCode()===200,'Admin catalog available');
check(strpos($response->getHeaderLine('Cache-Control'),'no-store')!==false,'Sensitive data is not cached');
$request=['action'=>'preview','package'=>'acme/example','mode'=>'keep','requestId'=>str_repeat('a',32)];
$response=$csrf->process(req($admin,'POST',$request),$controller);
check($response->getStatusCode()===202,'Admin can queue preview');
check($csrf->process(req($admin,'POST',$request),$controller)->getStatusCode()===200,'Retry returns existing job');
$request['package']='acme/other';
check($csrf->process(req($admin,'POST',$request),$controller)->getStatusCode()===422,'Cannot change repeated payload');
$store=new JobStore($base.'/storage');
$job=$store->read(str_repeat('a',32)); $job['status']='succeeded'; $store->save($job);
$planId=str_repeat('b',32);
$store->saveRecord($planId,['id'=>$planId,'actor'=>'1','expires'=>time()+600,'usedBy'=>null,'package'=>'acme/example','mode'=>'purge']);
$execute=['action'=>'execute','plan'=>$planId,'requestId'=>str_repeat('c',32),'confirmation'=>'wrong','purgeConfirmed'=>true];
check($csrf->process(req($admin,'POST',$execute),$controller)->getStatusCode()===422,'Exact confirmation word required');
$execute['confirmation']='acme/example';
check($csrf->process(req($admin,'POST',$execute),$controller)->getStatusCode()===422,'Package name is not a confirmation word');
$execute['confirmation']='DELETE'; $execute['purgeConfirmed']=false;
check($csrf->process(req($admin,'POST',$execute),$controller)->getStatusCode()===422,'Purge requires separate confirmation');
$execute['purgeConfirmed']=true;
check($csrf->process(req($admin,'POST',$execute),$controller)->getStatusCode()===202,'Confirmed action queued');
$p=$store->record($planId); $p['usedBy']=str_repeat('c',32); $store->saveRecord($planId,$p);
check($csrf->process(req($admin,'POST',$execute),$controller)->getStatusCode()===200,'Lost response retry remains idempotent after consumption');
$execute['requestId']=str_repeat('d',32);
check($csrf->process(req($admin,'POST',$execute),$controller)->getStatusCode()===422,'A used plan cannot create a new execution');
$completed=$store->read(str_repeat('c',32));$completed['status']='succeeded';$store->save($completed);
$disableId=str_repeat('e',32);
$store->saveRecord($disableId,['id'=>$disableId,'actor'=>'1','expires'=>time()+600,'usedBy'=>null,'package'=>'acme/example','mode'=>'disable']);
$disable=['action'=>'execute','plan'=>$disableId,'requestId'=>str_repeat('f',32),'confirmation'=>'DELETE'];
check($csrf->process(req($admin,'POST',$disable),$controller)->getStatusCode()===422,'DELETE cannot confirm disable-only action');
$disable['confirmation']=' DISABLE ';
check($csrf->process(req($admin,'POST',$disable),$controller)->getStatusCode()===202,'DISABLE confirms disable-only action');
foreach ([$guest,$member] as $actor) {
    $r=req($actor,'GET')->withQueryParams(['job'=>str_repeat('c',32)]);
    check(denied(function()use($csrf,$controller,$r){$csrf->process($r,$controller);},PermissionDeniedException::class),'Job output is private');
}
$rootController=new UninstallerController(new Paths(['base'=>$base,'public'=>$base.'/public','storage'=>$base.'/storage']),$settings,function(){return 0;});
check($csrf->process(req($admin,'POST',$execute),$rootController)->getStatusCode()===503,'Root web identity blocked');
$extenders=require __DIR__.'/../extend.php'; check(count($extenders)===3,'Real Flarum extenders load');
$less=new Less_Parser(); $less->parse(file_get_contents(__DIR__.'/../less/admin.less'));
check(strpos($less->getCss(),'.eu-layout')!==false,'Real Flarum Less compiler accepts UI styles');
echo Composer\InstalledVersions::getPrettyVersion('flarum/core').": $checks authorization / CSRF / styles checks passed.\n";
