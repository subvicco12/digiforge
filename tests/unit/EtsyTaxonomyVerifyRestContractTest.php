<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyTaxonomyVerifyRestContractTest extends TestCase {
 public function testExactVerifierEndpointIsAuthenticatedAndGetOnly(): void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/Controller.php');
  foreach(["/etsy/taxonomy-verify","'methods' => 'GET'","'permission_callback' => [\$this, 'can_manage']","fetchVerified(\$integrationId,\$taxonomyId)","external_actions_performed'=>false"] as $n) self::assertStringContainsString($n,$s);
 }
 public function testVerifierRouteHasNoPostMutationSurface(): void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/Controller.php');
  $start=strpos($s,"/etsy/taxonomy-verify"); $end=strpos($s,"register_rest_route('digiforge/v1', '/etsy/taxonomy-discovery'",$start);
  $route=substr($s,$start,$end-$start);
  self::assertStringNotContainsString("'methods' => 'POST'",$route);
 }
}