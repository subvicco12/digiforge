<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class IntegrationWriteConfirmationFinalContractTest extends TestCase{
 public function testCreateAndUpdateRequireAuthoritativeWriteConfirmation():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Integrations/Repository.php');
  self::assertStringContainsString("\$wpdb->last_error='';\$ok = \$wpdb->insert(Tables::integrations(), \$record);",$s);
  self::assertStringContainsString('integration_create_outcome_unknown',$s);
  self::assertStringContainsString("\$id<1",$s);
  self::assertStringContainsString('integration_update_readback_unavailable',$s);
  self::assertStringNotContainsString('return $this->find($id) ?? [];',$s);
 }
}
