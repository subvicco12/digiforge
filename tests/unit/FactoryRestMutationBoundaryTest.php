<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class FactoryRestMutationBoundaryTest extends TestCase{
 public function testFactoryMutationControllersBoundRequestBodies():void{
  foreach(['ProductFactoryController.php','DigitalFactoryController.php'] as $file){
   $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/'.$file);
   self::assertStringContainsString('MAX_BODY_BYTES',$s,$file);
   self::assertStringContainsString('get_body()',$s,$file);
   self::assertStringContainsString('payload_too_large',$s,$file);
   self::assertStringContainsString('413',$s,$file);
  }
 }
}
