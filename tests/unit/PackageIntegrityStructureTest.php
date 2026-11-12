<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PackageIntegrityStructureTest extends TestCase{
 public function testPackageScriptSelfVerifiesGeneratedChecksum():void{$r=dirname(__DIR__,2);$wrapper=file_get_contents($r.'/bin/package-plugin.sh');$s=file_get_contents($r.'/bin/package-gate2-pod.py');self::assertStringContainsString('package-gate2-pod.py',$wrapper);foreach(['git(\'cat-file\',\'blob\',oid)','hashlib.sha256(raw).hexdigest()!=x[\'sha256\']','z.testzip()','hashlib.sha256(target.read_bytes()).hexdigest()','digiforge.zip.sha256']as $check)self::assertStringContainsString($check,$s);}
 public function testDevelopmentSurfacesRemainExportIgnored():void{$m=json_decode(file_get_contents(dirname(__DIR__,2).'/docs/gate2/PAYLOAD.json'),true);self::assertCount(743,$m['files']);foreach($m['files']as $row){self::assertFalse((bool)preg_match('#^(bin|docs|tests|vendor|\.github)/#',$row['path']));self::assertNotContains($row['path'],['composer.json','composer.lock','phpunit.xml.dist','.env','wp-config.php']);}}
}
