<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PortalRecoveryEvidenceHandoffGuidanceTest extends TestCase {
 public function testPortalStatesExactNonAuthorizingRecoveryEvidenceHandoff():void {
  $p=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
  foreach(['Host backup evidence handoff:','identifier, captured_at, location, verification_method, verified_at, verified_by and retrievable=true','Recovery drill evidence handoff:','drill_id, performed_at, environment, database_backup_identifier, plugin_package_identifier, performed_by','restore_verified=true, schema_verified=true and application_health_verified=true','does not create, retrieve or verify the host backup'] as $needle) self::assertStringContainsString($needle,$p);
 }
}