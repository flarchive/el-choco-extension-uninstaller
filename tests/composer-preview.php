<?php
require $argv[1];
foreach (glob(__DIR__.'/../src/*.php') as $file) require_once $file;
use ElChoco\ExtensionUninstaller\Files;
use ElChoco\ExtensionUninstaller\JobStore;
use ElChoco\ExtensionUninstaller\PlanService;
use ElChoco\ExtensionUninstaller\ProcessExecutor;
use ElChoco\ExtensionUninstaller\Inventory;
$phar=realpath($argv[2]);
if (!$phar) throw new RuntimeException('Pass the Composer PHAR as the second argument.');
$base=sys_get_temp_dir().'/uninstaller-composer-'.bin2hex(random_bytes(5));
foreach (['vendor/composer','vendor/acme/example','vendor/acme/helper','vendor/flarum/core','vendor/el-choco/extension-uninstaller','storage'] as $dir) Files::directory($base.'/'.$dir);
Files::write($base.'/vendor/acme/example/extend.php','<?php return [];');
$packages=[
 ['name'=>'acme/example','version'=>'1.0.0','type'=>'flarum-extension','require'=>['acme/helper'=>'1.0.0','flarum/core'=>'^2.0']],
 ['name'=>'acme/helper','version'=>'1.0.0','type'=>'library'],
 ['name'=>'flarum/core','version'=>'2.0.2','type'=>'library'],
 ['name'=>'el-choco/extension-uninstaller','version'=>'1.0.0','type'=>'flarum-extension','require'=>['flarum/core'=>'^2.0']]
];
foreach($packages as &$package) $package['dist']=['type'=>'zip','url'=>'https://example.invalid/unused-fixture.zip','reference'=>'fixture']; unset($package);
$root=['name'=>'acme/test-forum','require'=>['acme/example'=>'1.0.0','el-choco/extension-uninstaller'=>'1.0.0'],
 'repositories'=>[['type'=>'package','package'=>$packages],['packagist.org'=>false]],'config'=>['allow-plugins'=>false]];
Files::write($base.'/composer.json',json_encode($root,JSON_PRETTY_PRINT));
$store=new JobStore($base.'/storage');
$executor=new class($base,$store->folder(),null,$phar) extends ProcessExecutor {
    private string $phar;
    public function __construct($base,$folder,$pulse,$phar){parent::__construct($base,$folder,$pulse);$this->phar=$phar;}
    public function composer(): array {
        $args=[PHP_BINARY];
        if(getenv('UNINSTALLER_TEST_PHP_INI')) $args=array_merge($args,['-c',getenv('UNINSTALLER_TEST_PHP_INI')]);
        return array_merge($args,[$this->phar]);
    }
};
$output='';
$logger=function($type,$chunk)use(&$output){$output.=$chunk;};
$exit=$executor->run(array_merge($executor->composer(),['update','--no-install','--no-dev','--no-scripts','--no-plugins','--no-interaction','--no-progress']),$logger);
if($exit!==0) throw new RuntimeException($output);
$lock=Files::json($base.'/composer.lock');
foreach($lock['packages'] as &$p) { $p['install-path']='../'.$p['name']; $p['installation-source']='dist'; } unset($p);
Files::write($base.'/vendor/composer/installed.json',json_encode(['packages'=>$lock['packages'],'dev'=>false,'dev-package-names'=>[]]));
$original=file_get_contents($base.'/composer.json');
$service=new PlanService($base,$store,$executor);
try { $plan=$service->create('acme/example','keep','1',[],function($chunk)use(&$output){$output.=$chunk;}); }
catch(Throwable $e) { throw new RuntimeException($e->getMessage()."\n".$output); }
if(file_get_contents($base.'/composer.json')!==$original) throw new RuntimeException('Preview changed live root.');
$removed=array_column($plan['removed'],'name');sort($removed);
if($removed!==['acme/example','acme/helper']) throw new RuntimeException('Unexpected plan: '.json_encode($removed));
$service->validate($plan['id'],'1',[]);
$draft=$store->folder().'/plans/'.$plan['id'];
foreach(['composer.json','composer.lock'] as $file) Files::write($base.'/'.$file,(string)file_get_contents($draft.'/'.$file));
$output='';
$exit=$executor->run(array_merge($executor->composer(),['install','--no-dev','--no-scripts','--no-plugins','--no-interaction','--no-progress']),$logger);
if($exit!==0) throw new RuntimeException($output);
$installed=Inventory::packages($base);
if(isset($installed['acme/example'])||isset($installed['acme/helper'])||!isset($installed['flarum/core'])||!isset($installed['el-choco/extension-uninstaller'])) throw new RuntimeException('Unexpected installed state.');
echo "Real offline Composer: isolated preview, exact two-package removal and protected packages retained.\n";
