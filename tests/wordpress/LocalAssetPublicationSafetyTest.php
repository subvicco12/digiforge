<?php
declare(strict_types=1);
final class LocalAssetPublicationSafetyTest extends WP_UnitTestCase
{
    private array $processes=[];private array $directories=[];private string $control;
    protected function setUp():void{parent::setUp();DigiForge\Core\Settings::protectProduction();$this->control=sys_get_temp_dir().'/df-local-asset-'.wp_generate_uuid4();mkdir($this->control);$this->directories[]=$this->control;}
    protected function tearDown():void{foreach($this->processes as$p){proc_terminate($p,9);proc_close($p);}foreach($this->directories as$d){foreach(glob($d.'/*')as$f)if(is_file($f))unlink($f);if(is_dir($d))rmdir($d);}parent::tearDown();}
    private function start(int $version,string $text,string $id,bool $disable=false):string
    {
        $base=$this->control.'/'.$id;file_put_contents($base.'.json',json_encode(['version'=>$version,'text'=>$text,'uploads'=>defined('UPLOADS')?UPLOADS:null,'control'=>$base],JSON_THROW_ON_ERROR));$cmd=[PHP_BINARY];if($disable){$cmd[]='-d';$cmd[]='disable_functions=link';}$cmd[]=dirname(__DIR__).'/fixtures/local-asset-worker.php';$cmd[]=$base.'.json';$p=proc_open($cmd,[0=>['file','/dev/null','r'],1=>['file',$base.'.log','w'],2=>['file',$base.'.err','w']],$pipes);self::assertIsResource($p);$this->processes[$base]=$p;return $base;
    }
    private function ready(string $base):void{$until=microtime(true)+15;while(!is_file($base.'.ready')&&microtime(true)<$until){if(!proc_get_status($this->processes[$base])['running'])break;usleep(10000);}self::assertFileExists($base.'.ready',file_get_contents($base.'.err').file_get_contents($base.'.log'));}
    private function finish(string $base,bool $release=true):array{if($release)file_put_contents($base.'.go','1');self::assertSame(0,proc_close($this->processes[$base]),file_get_contents($base.'.err').file_get_contents($base.'.log'));unset($this->processes[$base]);return json_decode(file_get_contents($base.'.result'),true,512,JSON_THROW_ON_ERROR);}
    private function destination(int $v):string{$dir=wp_upload_dir()['basedir'].'/'.DigiForge\ProductFactory\AssetStorage::RELATIVE_ROOT.'/'.$v;$this->directories[]=$dir;return $dir.'/guide.txt';}
    public function testConflictingWritersCannotOverwriteAnAcknowledgedSource():void
    {
        $v=random_int(41000000,42000000);$path=$this->destination($v);$one=$this->start($v,'First fictional source','one');$two=$this->start($v,'Conflicting fictional source','two');$this->ready($one);$this->ready($two);$winner=$this->finish($one);$bytes=file_get_contents($path);$loser=$this->finish($two);self::assertSame('digiforge_u3_asset_replay_conflict',$loser['error']??null);self::assertSame(409,$loser['status']);self::assertSame($bytes,file_get_contents($path));self::assertSame($winner['hash'],hash_file('sha256',$path));
    }
    public function testIdenticalWritersReconcileWithoutReplacingTheWinnerInode():void
    {
        $v=random_int(42000001,43000000);$path=$this->destination($v);$one=$this->start($v,'Identical fictional source','one');$two=$this->start($v,'Identical fictional source','two');$this->ready($one);$this->ready($two);$winner=$this->finish($one);$inode=stat($path)['ino'];$other=$this->finish($two);clearstatcache(true,$path);self::assertSame($winner,$other);self::assertSame($inode,stat($path)['ino']);self::assertSame($winner['hash'],hash_file('sha256',$path));
    }
    public function testUnavailableAtomicPublicationFailsClosedButRetainedReplayWorks():void
    {
        $v=random_int(43000001,44000000);$path=$this->destination($v);$base=$this->start($v,'Fictional source','disabled',true);file_put_contents($base.'.go','1');$r=$this->finish($base,false);self::assertSame('digiforge_u3_asset_commit_capability',$r['error']??null);self::assertSame(503,$r['status']);self::assertFileDoesNotExist($path);
        $valid=(new DigiForge\ProductFactory\LocalAssetProducer())->write($v,'guide.txt','txt','Fictional source');self::assertNotWPError($valid);$replay=$this->finish($this->start($v,'Fictional source','replay',true),false);self::assertSame($valid['checksum_sha256'],$replay['hash']);
    }
}
