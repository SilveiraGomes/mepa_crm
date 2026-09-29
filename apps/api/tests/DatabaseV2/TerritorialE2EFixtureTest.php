<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseV2\Support\TerritorialHttpCase;

final class TerritorialE2EFixtureTest extends TerritorialHttpCase
{
    public function test_seed_territorial_browser_fixture_only():void
    {
        $g=$this->unit('GENERAL_DIRECTION',null,'DRAFT','Direcção Geral MEPA');$r=$this->unit('REGIONAL_DIRECTION',$g['id'],'DRAFT','Região Norte');$p=$this->unit('PROVINCIAL_DIRECTION',$r['id'],'DRAFT','Província de Referência');$m=$this->unit('MUNICIPAL_DIRECTION',$p['id'],'DRAFT','Município de Referência');$c=$this->unit('CENTER',$m['id'],'DRAFT','Centro Esperança');$c2=$this->unit('CENTER',$m['id'],'DRAFT','Centro Renovação');$co=$this->unit('CONGREGATION',$c['id'],'DRAFT','Congregação Vida');
        $actor=$this->staff($this->allPermissions(),$g['id'],true);$login='territorial.e2e.'.bin2hex(random_bytes(6));$password=bin2hex(random_bytes(32));$hash=(new BcryptHasher(['rounds'=>4]))->make($password);DB::table('users')->where('id',$actor['user'])->update(['login'=>$login,'password_hash'=>$hash,'status'=>'SYNTHETIC_READY','mfa_required'=>0]);
        $manifest=['login'=>$login,'password'=>$password,'root'=>$g['public_id'],'regional'=>$r['public_id'],'provincial'=>$p['public_id'],'municipal'=>$m['public_id'],'center'=>$c['public_id'],'target_center'=>$c2['public_id'],'congregation'=>$co['public_id']];$dir=dirname(__DIR__,4).'/.tmp';if(!is_dir($dir))mkdir($dir,0700,true);file_put_contents($dir.'/p06-e2e-fixtures.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");$this->assertFileExists($dir.'/p06-e2e-fixtures.json');
    }
}
