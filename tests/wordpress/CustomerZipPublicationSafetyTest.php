<?php
declare(strict_types=1);

/** Independent native PHP/WordPress publishers; synthetic bytes, never customer acceptance. */
final class CustomerZipPublicationSafetyTest extends WP_UnitTestCase
{
    private array $processes=[];
    private array $directories=[];
    private string $control;

    protected function setUp():void
    {
        parent::setUp();
        $this->control=sys_get_temp_dir().'/df-zip-publication-'.wp_generate_uuid4();
        mkdir($this->control);$this->directories[]=$this->control;
        DigiForge\Core\Settings::protectProduction();
    }
    protected function tearDown():void
    {
        foreach($this->processes as$p){proc_terminate($p,9);proc_close($p);}
        foreach(array_reverse($this->directories)as$d)$this->removeOwnedDirectory($d);
        parent::tearDown();
    }
    private function removeOwnedDirectory(string $path):void
    {
        if(!is_dir($path))return;
        foreach(new DirectoryIterator($path)as$f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())$this->removeOwnedDirectory($f->getPathname());else unlink($f->getPathname());}rmdir($path);
    }
    private function selection(string $text='Fictional Letter edition'):array
    {
        $p=new DigiForge\ProductFactory\LocalAssetProducer();$v=random_int(30000000,40000000);
        $a=$p->write($v,'letter.txt','txt',$text);$b=$p->write($v,'a4.txt','txt','Fictional A4 edition');
        self::assertNotWPError($a);self::assertNotWPError($b);$this->directories[]=dirname($a['absolute_path']);return [$p,$v,[$a,$b]];
    }
    private function start(int $version,array $assets,string $id,string $mode='pause',bool $disableLink=false):string
    {
        $base=$this->control.'/'.$id;$request=$base.'.json';
        file_put_contents($request,json_encode(['version'=>$version,'assets'=>$assets,'control'=>$base,'mode'=>$mode,'uploads'=>UPLOADS],JSON_THROW_ON_ERROR));
        $command=[PHP_BINARY];if($disableLink){$command[]='-d';$command[]='disable_functions=link';}
        $command[]=dirname(__DIR__).'/fixtures/customer-zip-worker.php';$command[]=$request;
        $p=proc_open($command,[0=>['file','/dev/null','r'],1=>['file',$base.'.log','w'],2=>['file',$base.'.err','w']],$pipes);
        self::assertIsResource($p);$this->processes[$base]=$p;return $base;
    }
    private function ready(string $base):void
    {
        $until=microtime(true)+20;while(!is_file($base.'.ready')&&microtime(true)<$until){if(!proc_get_status($this->processes[$base])['running'])break;usleep(10000);}
        self::assertFileExists($base.'.ready',file_get_contents($base.'.err').file_get_contents($base.'.log'));
    }
    private function finish(string $base,bool $release=true):array
    {
        if($release)file_put_contents($base.'.go','1');
        self::assertSame(0,proc_close($this->processes[$base]),file_get_contents($base.'.err').file_get_contents($base.'.log'));unset($this->processes[$base]);
        self::assertFileExists($base.'.result');return json_decode(file_get_contents($base.'.result'),true,512,JSON_THROW_ON_ERROR);
    }
    private function verify(array $assets,string $path):string
    {
        $z=new ZipArchive();self::assertTrue($z->open($path,ZipArchive::CHECKCONS));
        try{self::assertSame(count($assets),$z->numFiles);foreach($assets as$i=>$a){self::assertSame($a['filename'],$z->getNameIndex($i));self::assertSame($a['checksum_sha256'],hash('sha256',$z->getFromIndex($i)));}}finally{$z->close();}
        return hash_file('sha256',$path);
    }
    public function testIdenticalIndependentPublishersReconcileWithoutOverwrite():void
    {
        [$p,$v,$a]=$this->selection();$one=$this->start($v,$a,'one');$two=$this->start($v,$a,'two');$this->ready($one);$this->ready($two);
        $x=$this->finish($one);$path=dirname($a[0]['absolute_path']).'/customer-package.zip';$stat=stat($path);$y=$this->finish($two);
        self::assertArrayNotHasKey('error',$x);self::assertSame($x,$y);self::assertSame($stat['ino'],stat($path)['ino']);self::assertSame($x['checksum_sha256'],$this->verify($a,$path));
        self::assertSame($x['checksum_sha256'],$p->package($v,$a)['checksum_sha256']);self::assertSame([],glob($path.'.tmp-*'));
    }
    public function testConflictingIndependentPublisherCannotReplaceWinner():void
    {
        [$p,$v,$a]=$this->selection();[,,$other]=$this->selection('Different fictional edition');
        $one=$this->start($v,$a,'winner');$two=$this->start($v,$other,'conflict');$this->ready($one);$this->ready($two);$x=$this->finish($one);
        $path=dirname($a[0]['absolute_path']).'/customer-package.zip';$before=file_get_contents($path);$y=$this->finish($two);
        self::assertSame('digiforge_u3_asset_replay_conflict',$y['error']);self::assertSame(409,$y['status']);self::assertSame($before,file_get_contents($path));self::assertSame($x['checksum_sha256'],$this->verify($a,$path));
        self::assertWPError($p->package($v,$other));
    }
    public function testKilledPublisherCannotPromotePartialTemporaryArchive():void
    {
        [$p,$v,$a]=$this->selection();$base=$this->start($v,$a,'interrupted','truncate_pause');$this->ready($base);$path=dirname($a[0]['absolute_path']).'/customer-package.zip';
        self::assertFileDoesNotExist($path);self::assertCount(1,glob($path.'.tmp-*'));proc_terminate($this->processes[$base],9);proc_close($this->processes[$base]);unset($this->processes[$base]);
        self::assertFileDoesNotExist($base.'.result');self::assertFileDoesNotExist($path);$result=$p->package($v,$a);self::assertNotWPError($result);self::assertSame($result['checksum_sha256'],$this->verify($a,$path));
        // Owned interrupted temp remains unaccepted; no production cleanup policy is introduced.
        self::assertCount(1,glob($path.'.tmp-*'));
    }
    public function testPartialDestinationIsRejectedWithoutReplacement():void
    {
        [$p,$v,$a]=$this->selection();$path=dirname($a[0]['absolute_path']).'/customer-package.zip';file_put_contents($path,"PK\x03\x04partial");$before=file_get_contents($path);
        $r=$p->package($v,$a);self::assertWPError($r);self::assertSame('digiforge_u3_asset_replay_conflict',$r->get_error_code());self::assertSame($before,file_get_contents($path));
    }
    public function testKilledAfterCommitReconcilesExactRetainedArchiveWithoutReceipt():void
    {
        [$p,$v,$a]=$this->selection();$base=$this->start($v,$a,'uncertain','after_link_pause');$this->ready($base);$path=dirname($a[0]['absolute_path']).'/customer-package.zip';
        $hash=$this->verify($a,$path);$inode=stat($path)['ino'];proc_terminate($this->processes[$base],9);proc_close($this->processes[$base]);unset($this->processes[$base]);
        self::assertFileDoesNotExist($base.'.result');$r=$p->package($v,$a);self::assertNotWPError($r);self::assertSame($hash,$r['checksum_sha256']);self::assertSame($inode,stat($path)['ino']);self::assertSame($hash,$this->verify($a,$path));
    }
    public function testMissingAndFailingAtomicPrerequisiteNeverPublishes():void
    {
        [$p,$v,$a]=$this->selection();$path=dirname($a[0]['absolute_path']).'/customer-package.zip';
        $absent=$this->finish($this->start($v,$a,'disabled','pause',true),false);self::assertSame('digiforge_u3_asset_commit_capability',$absent['error']);self::assertSame(503,$absent['status']);self::assertFileDoesNotExist($path);
        $failed=$this->finish($this->start($v,$a,'failed','fail_link'),false);self::assertSame('digiforge_u3_asset_commit',$failed['error']);self::assertSame(503,$failed['status']);self::assertFileDoesNotExist($path);self::assertSame([],glob($path.'.tmp-*'));
        $ok=$p->package($v,$a);self::assertNotWPError($ok);$retained=$this->finish($this->start($v,$a,'retained','pause',true),false);self::assertSame($ok['checksum_sha256'],$retained['checksum_sha256']);self::assertSame($ok['checksum_sha256'],$this->verify($a,$path));
    }
}
