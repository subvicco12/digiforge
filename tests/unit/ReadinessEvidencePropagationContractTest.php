<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ReadinessEvidencePropagationContractTest extends TestCase{
 public function testUnavailableReadinessEvidenceIsNotReclassifiedAsOrdinaryNotReady():void{
  $root=dirname(__DIR__,2).'/includes/Listings/';
  $files=['Repository.php','EtsyDraftPreparation.php','EtsyPublishAuthorizationGate.php','ReleaseEvidence.php','EtsyOperationPreparationService.php'];
  $combined='';
  foreach($files as $file)$combined.=(string)file_get_contents($root.$file)."\n";
  self::assertStringContainsString("if(is_wp_error(\$readiness))return \$readiness;if(empty(\$readiness['ready']))",$combined);
  self::assertStringContainsString("if(is_wp_error(\$current))return \$current;if(empty(\$current['ready']))",$combined);
  self::assertStringContainsString('if($current instanceof WP_Error)return $current;if(empty($current[\'ready\']))',$combined);
  self::assertStringContainsString('if ($currentReadiness instanceof WP_Error) return $currentReadiness;',$combined);
  self::assertStringContainsString("'pod_not_ready','POD readiness must pass before binding.'",$combined);
  self::assertStringContainsString('Listing readiness must pass before Etsy draft preparation.',$combined);
  self::assertStringContainsString('Listing is no longer release-ready.',$combined);
  self::assertStringContainsString('Current listing readiness does not pass.',$combined);
  self::assertStringContainsString('Referenced listing is no longer release-ready.',$combined);
  self::assertStringNotContainsString("is_wp_error(\$readiness)||empty(\$readiness['ready'])",$combined);
  self::assertStringNotContainsString("\$current instanceof WP_Error||empty(\$current['ready'])",$combined);
  self::assertStringNotContainsString("\$currentReadiness instanceof WP_Error || empty(\$currentReadiness['ready'])",$combined);
 }
}
