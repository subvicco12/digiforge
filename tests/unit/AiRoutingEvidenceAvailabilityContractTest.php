<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class AiRoutingEvidenceAvailabilityContractTest extends TestCase{
 public function testRunCreationSeparatesUnavailableRoutingEvidenceFromEmptyModelSet():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/AI/Repository.php');
  self::assertStringContainsString('private function modelsForEnvironment(string $environment): array|\\WP_Error',$s);
  self::assertStringContainsString("\$wpdb->last_error = ''",$s);
  self::assertStringContainsString('AI routing model evidence could not be read.',$s);
  self::assertStringContainsString('if (is_wp_error($models))',$s);
  self::assertStringContainsString("return array_map([\$this, 'normalize'], \$rows);",$s);
 }
}
