<?php
declare(strict_types=1);

/** Exact local customer archive membership, not authentic Wedding Guide acceptance. */
final class Pilot48CustomerPackageIntegrityTest extends WP_UnitTestCase
{
    private array $paths=[];
    protected function tearDown():void{foreach($this->paths as$p)if(is_file($p))unlink($p);parent::tearDown();}
    private function assets():array
    {
        DigiForge\Core\Settings::protectProduction();$producer=new DigiForge\ProductFactory\LocalAssetProducer();$version=random_int(10000000,20000000);
        $a=$producer->write($version,'letter-guide.txt','txt','Fictional Letter guide');$b=$producer->write($version,'a4-guide.txt','txt','Fictional A4 guide');self::assertNotWPError($a);self::assertNotWPError($b);$this->paths[]=$a['absolute_path'];$this->paths[]=$b['absolute_path'];return [$producer,$version,$a,$b];
    }
    public function testDuplicateFlattenedNamesCannotSilentlyOmitCustomerFiles():void
    {
        [$producer,$version,$a,$b]=$this->assets();$a['filename']='Letter/guide.txt';$b['filename']='A4/guide.txt';$result=$producer->package($version,[$a,$b]);if(!is_wp_error($result))$this->paths[]=$result['absolute_path'];self::assertWPError($result,'Duplicate delivery member names must not overwrite customer content.');
    }
    public function testChangedSourceCannotBorrowEarlierChecksum():void
    {
        [$producer,$version,$a,$b]=$this->assets();file_put_contents($a['absolute_path'],'Unreviewed replacement');$result=$producer->package($version,[$a,$b]);if(!is_wp_error($result))$this->paths[]=$result['absolute_path'];self::assertWPError($result,'Archive must bind declared reviewed source hashes.');
    }
    public function testRetryWithUnchangedBytesDoesNotDependOnSourceMtime():void
    {
        [$producer,$version,$a,$b]=$this->assets();$first=$producer->package($version,[$a,$b]);self::assertNotWPError($first);$this->paths[]=$first['absolute_path'];touch($a['absolute_path'],946684800);touch($b['absolute_path'],978307200);$again=$producer->package($version,[$a,$b]);self::assertNotWPError($again,'Exact content replay must not fail because filesystem metadata changed.');self::assertSame($first['checksum_sha256'],$again['checksum_sha256']);$fresh=$producer->package($version,[$a,$b],'fresh-package.zip');self::assertNotWPError($fresh);$this->paths[]=$fresh['absolute_path'];self::assertSame($first['checksum_sha256'],$fresh['checksum_sha256'],'Fresh identical package must ignore source mtime too.');
        $zip=new ZipArchive();self::assertTrue($zip->open($first['absolute_path']));try{self::assertSame(2,$zip->numFiles);foreach([$a,$b]as$file)self::assertSame($file['checksum_sha256'],hash('sha256',$zip->getFromName($file['filename'])));}finally{$zip->close();}
    }    public function testHistoricalArchiveIsVerifiedWithoutRewritingItsBytes():void
    {
        [$producer,$version,$a,$b]=$this->assets();$path=dirname($a['absolute_path']).'/customer-package.zip';$zip=new ZipArchive();self::assertTrue($zip->open($path,ZipArchive::CREATE|ZipArchive::EXCL));self::assertTrue($zip->addFile($a['absolute_path'],$a['filename']));self::assertTrue($zip->addFile($b['absolute_path'],$b['filename']));self::assertTrue($zip->close());$this->paths[]=$path;$original=file_get_contents($path);$result=$producer->package($version,[$a,$b]);self::assertNotWPError($result);self::assertSame($original,file_get_contents($path));self::assertSame(hash('sha256',$original),$result['checksum_sha256']);self::assertCount(2,$result['package_members']);
    }
    public function testIncompleteIdentityAndExtraArchiveMembersFailClosed():void
    {
        [$producer,$version,$a,$b]=$this->assets();$bad=$a;unset($bad['checksum_sha256']);self::assertWPError($producer->package($version,[$bad,$b]));$result=$producer->package($version,[$a,$b]);self::assertNotWPError($result);$this->paths[]=$result['absolute_path'];$zip=new ZipArchive();self::assertTrue($zip->open($result['absolute_path']));self::assertTrue($zip->addFromString('UNREVIEWED.txt','extra fictional content'));self::assertTrue($zip->close());$before=file_get_contents($result['absolute_path']);self::assertWPError($producer->package($version,[$a,$b]));self::assertSame($before,file_get_contents($result['absolute_path']));
    }

}
