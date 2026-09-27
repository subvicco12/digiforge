<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyTaxonomyDiscoveryRestContractTest extends TestCase {
 public function testEndpointIsAuthenticatedGetOnlyAndBounded(): void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/Controller.php');
  foreach(["/etsy/taxonomy-discovery","'methods' => 'GET'","'permission_callback' => [\$this, 'can_manage']","EtsySellerTaxonomyClient","->discover(","external_actions_performed'=>false"] as $n) self::assertStringContainsString($n,$s);
 }
 public function testEndpointDoesNotExposeMutationMethod(): void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/Controller.php');
  $start=strpos($s,"/etsy/taxonomy-discovery");
  $end=strpos($s,"register_rest_route('digiforge/v1', '/activations'",$start);
  $route=substr($s,$start,$end-$start);
  self::assertStringNotContainsString("'methods' => 'POST'",$route);
 }
}