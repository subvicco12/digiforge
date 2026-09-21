<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductFactoryV1021OutputContractTest extends TestCase{
 public function test_production_outputs_preserve_geometry_archive_and_zip_evidence():void{
  $producer=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/LocalAssetProducer.php');
  $qa=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/AutomatedQa.php');
  $semantic=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/SemanticQa.php');
  $orchestrator=(string)file_get_contents(dirname(__DIR__,2).'/includes/ProductFactory/Orchestrator.php');
  self::assertStringContainsString("str_contains(\$name, 'mobile')",$producer);
  self::assertStringContainsString("'width'=>360,'height'=>640",$producer);
  self::assertStringContainsString("'width'=>595,'height'=>842",$producer);
  self::assertStringContainsString("'width'=>612,'height'=>792",$producer);
  self::assertStringContainsString("'pdf_media_box'",$qa);
  self::assertStringContainsString("'zip_member_inventory'",$qa);
  self::assertStringContainsString("'members' => array_values(\$names)",$qa);
  self::assertStringContainsString('[ZIP members: ',$semantic);
  self::assertStringContainsString("['package_filename']",$orchestrator);
  self::assertStringContainsString("['delivery_archive']",$orchestrator);
  self::assertStringContainsString("['archive_filename']",$orchestrator);
  self::assertStringContainsString("'generator_version'=>'1.0.22'",$orchestrator);
  self::assertStringContainsString("['sha256,filename']",$orchestrator);
  self::assertStringContainsString("'external_publish'=>false",$orchestrator);
 }
}
