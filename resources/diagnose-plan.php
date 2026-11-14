<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function fields(array $a, array $b, string $prefix = ''): array {
    $result = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (!array_key_exists($key,$a) || !array_key_exists($key,$b)) $result[]=$path;
        elseif (is_array($a[$key]) && is_array($b[$key])) $result=array_merge($result,fields($a[$key],$b[$key],$path));
        elseif ($a[$key] != $b[$key]) $result[]=$path;
        if (count($result)>=24) return array_slice($result,0,24);
    }
    return $result;
}
function packages(array $lock): array {
    $result=[];
    foreach (array_merge($lock['packages']??[],$lock['packages-dev']??[]) as $package) $result[$package['name']]=$package;
    return $result;
}
$before=packages(json_decode(file_get_contents('composer.lock'),true,512,JSON_THROW_ON_ERROR));
$target=$argv[1]??null;
if($target!==null && !preg_match('~^[a-z0-9_.-]+/[a-z0-9_.-]+$~',$target)) exit("Bitte einen Composer-Paketnamen angeben.\n");
$files=glob('storage/extension-uninstaller/plans/*/composer.lock')?:[];
usort($files,function($a,$b){return filemtime($b)<=>filemtime($a);});
foreach($files as $file){
    $after=packages(json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR));
    if ($target!==null && (!isset($before[$target]) || isset($after[$target]))) continue;
    echo "Vorschau: ".$file.PHP_EOL;
    $count=0;
    foreach($after as $name=>$package){
        if(!isset($before[$name])) { echo "NEU: ".$name.PHP_EOL; $count++; }
        elseif($before[$name]!=$package){
            echo $name." | ".($before[$name]['version']??'?')." -> ".($package['version']??'?').PHP_EOL;
            echo "Felder: ".implode(', ',fields($before[$name],$package)).PHP_EOL;
            $count++;
        }
    }
    if(!$count)echo "Keine Unterschiede an verbleibenden Paketen gefunden.\n";
    exit;
}
echo "Keine passende gespeicherte Vorschau gefunden.\n";
