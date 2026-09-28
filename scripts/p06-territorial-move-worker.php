<?php

declare(strict_types=1);

use App\Domain\Territorial\TerritorialAudit;
use App\Domain\Territorial\TerritorialAuthority;
use App\Domain\Territorial\TerritorialError;
use App\Domain\Territorial\TerritorialService;
use Illuminate\Database\Capsule\Manager;

$root=dirname(__DIR__);require $root.'/apps/api/vendor/autoload.php';
[$script,$unit,$parent,$user,$session,$version]=$argv;
$dsn=(string)getenv('WAVE5_DSN');$parts=[];foreach(explode(';',substr($dsn,6))as$part)if(str_contains($part,'=')){[$k,$v]=explode('=',$part,2);$parts[$k]=$v;}
$capsule=new Manager();$capsule->addConnection(['driver'=>'mysql','host'=>$parts['host'],'port'=>$parts['port']??3306,'database'=>$parts['dbname'],'username'=>getenv('WAVE5_USER')?:'root','password'=>getenv('WAVE5_PASSWORD')?:'','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','strict'=>true,'options'=>[PDO::ATTR_EMULATE_PREPARES=>false]]);$db=$capsule->getConnection();
fgets(STDIN);
try{$service=new TerritorialService($db,new TerritorialAuthority($db,['SYNTHETIC_READY'],['SYNTHETIC_READY']),new TerritorialAudit($db));$result=$service->move((int)$user,(int)$session,$unit,['new_parent_public_id'=>$parent,'reason'=>'concurrent move probe','lock_version'=>(int)$version]);echo json_encode(['status'=>'OK','parent'=>$result['parent_public_id']],JSON_THROW_ON_ERROR);}
catch(TerritorialError $e){echo json_encode(['status'=>$e->reason],JSON_THROW_ON_ERROR);}
