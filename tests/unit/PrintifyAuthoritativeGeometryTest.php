<?php
declare(strict_types=1);
require_once __DIR__.'/../fixtures/PrintifyGeometryFixture.php';
final class PrintifyAuthoritativeGeometryTest extends PHPUnit\Framework\TestCase {
 public function testExactProviderDimensionsAndBindingsArePreserved():void {$row=PrintifyGeometryFixture::catalog(321,77,88);$t=PrintifyGeometryFixture::candidate($row,'geometry-fixture');self::assertSame(1200,$t['print_areas'][0]['width_px']);self::assertSame($row['attributes']['_digiforge_source_evidence']['variant_sha256'],$t['print_areas'][0]['_catalog_bindings'][0]['variant_sha256']);}
 public function testCallerCannotInventDimensions():void {$this->expectException(InvalidArgumentException::class);PrintifyGeometryFixture::candidate(PrintifyGeometryFixture::catalog(321,77,88),'fabricated',1201,1600);}
 public function testChangedVariantBytesCannotReuseProvenance():void {$row=PrintifyGeometryFixture::catalog(321,77,88);$row['attributes']['placeholders'][0]['width']=1400;$this->expectException(InvalidArgumentException::class);PrintifyGeometryFixture::candidate($row,'tampered',1400,1600);}
 public function testMissingProvenanceCannotBeValidated():void {$row=PrintifyGeometryFixture::catalog(321,77,88);unset($row['attributes']['_digiforge_source_evidence']);$this->expectException(InvalidArgumentException::class);PrintifyGeometryFixture::candidate($row,'missing');}
}
