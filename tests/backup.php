<?php
require $argv[1];
foreach (glob(__DIR__.'/../src/*.php') as $file) require_once $file;
use ElChoco\ExtensionUninstaller\Database;
use ElChoco\ExtensionUninstaller\Files;
use ElChoco\ExtensionUninstaller\ProcessExecutor;
class FakeStatement extends PDOStatement {
    public function execute(?array $params=null): bool { return true; }
    public function fetchColumn(int $column=0): mixed { return false; }
}
class FakePdo extends PDO {
    public function __construct() {}
    public function prepare(string $query,array $options=[]): PDOStatement|false { return new FakeStatement(); }
}
$checks=0;
function check($value,$label){global $checks;$checks++;if(!$value)throw new RuntimeException($label);}
$root=sys_get_temp_dir().'/uninstaller-backup-'.bin2hex(random_bytes(5));Files::directory($root);
$db=(new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Database::class,'config'))->setValue($db,['database'=>'forum','username'=>'forum','password'=>'secret"with\\slashes','host'=>'db','port'=>3306]);
(new ReflectionProperty(Database::class,'pdo'))->setValue($db,new FakePdo());
$executor=new class($root,$root) extends ProcessExecutor {
    public string $mode='success';
    public bool $privateCredentials=false;
    public function findDump(): ?string{return '/test/mariadb-dump';}
    public function run(array $argv,callable $output,?string $cwd=null): int{
        if(strpos($argv[1],'--defaults-extra-file=')!==0)throw new RuntimeException('Credentials must be the first dump option.');
        $file=substr($argv[1],strlen('--defaults-extra-file='));
        $bytes=file_get_contents($file);
        $this->privateCredentials=strpos($bytes,'password="secret\\"with\\\\slashes"')!==false;
        foreach($argv as $argument)if(strpos($argument,'secret')!==false)throw new RuntimeException('Password leaked into argv.');
        if($this->mode==='throw')throw new RuntimeException('Simulated process error');
        $output('out',str_repeat('-- SQL backup row\n',20));
        $output('err','A diagnostic, not SQL.');
        return $this->mode==='fail'?3:0;
    }
};
$log='';$logger=function($chunk)use(&$log){$log.=$chunk;};
$ok=$root.'/success';Files::directory($ok);$result=$db->backup($ok,$executor,$logger);
check($executor->privateCredentials,'Credentials correctly escaped in private option file');
check(!is_file($ok.'/dump-client.cnf'),'Temporary credentials removed after success');
check($result['sha256']===hash_file('sha256',$ok.'/database.sql'),'Backup content checksum');
check($result['bytes']===filesize($ok.'/database.sql'),'Export byte count verified');
check($result['restoreTested']===false,'Export is not misreported as a restore test');
check(strpos($log,'SQL backup row')===false,'SQL data is not included in UI logs');
foreach(['fail','throw'] as $mode){
    $executor->mode=$mode;$folder=$root.'/'.$mode;Files::directory($folder);$failed=false;
    try{$db->backup($folder,$executor,$logger);}catch(Throwable $e){$failed=true;}
    check($failed,'Nonzero/exception export cannot allow purge');
    check(!is_file($folder.'/dump-client.cnf'),'Credentials removed after failure');
}
echo "$checks database-export safety checks passed with simulated PDO and dump process.\n";
