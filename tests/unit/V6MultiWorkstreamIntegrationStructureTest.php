<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class V6MultiWorkstreamIntegrationStructureTest extends TestCase {
 private function source(string $path):string{$s=file_get_contents(dirname(__DIR__,2).'/'.$path);self::assertIsString($s);return $s;}
 public function testPodRepositoryUsesTypedEtsyPersonalizationContract():void {
  $s=$this->source('includes/POD/Repository.php');self::assertStringContainsString('EtsyPersonalizationContract::normalize',$s);self::assertStringContainsString("['policy_constraints']['etsy_personalization']",$s);
 }
 public function testOrderReadinessUsesExplicitFulfillmentModeContract():void {
  $s=$this->source('includes/Orders/Repository.php');self::assertStringContainsString('FulfillmentMode::classify',$s);self::assertStringContainsString('OrderReadinessProjection::project',$s);
 }
 public function testPortalExposesMaster500WithoutProductionAuthority():void {
  $s=$this->source('includes/Portal/Portal.php');self::assertStringContainsString('PersonalizedCatalogReference::metadata',$s);self::assertStringContainsString('Production authority',$s);self::assertStringContainsString('>NO<',$s);
 }
}