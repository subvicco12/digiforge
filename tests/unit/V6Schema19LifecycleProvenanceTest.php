<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class V6Schema19LifecycleProvenanceTest extends TestCase{
 public function testSchemaAddsDurableProvenanceAndExplicitClosureState():void{$s=file_get_contents(__DIR__.'/../../includes/Database/V6OperationalSchema.php');self::assertStringContainsString('VERSION=19',$s);self::assertStringContainsString('pod_authorization_bindings',$s);self::assertStringContainsString('external_execution_state varchar(32)',$s);$bootstrap=file_get_contents(__DIR__.'/../../digiforge.php');self::assertStringContainsString("DIGIFORGE_DB_VERSION = '19'",$bootstrap);self::assertStringContainsString("V6_LIFECYCLE_STATE_BACKFILL_FAILED",$s);self::assertStringContainsString("CONFIRMED_SUCCESS",$s);self::assertStringContainsString("CONFIRMED_FAILURE",$s);}
 public function testPermitConsumptionPersistsBindingAndClosureChecksIt():void{$c=file_get_contents(__DIR__.'/../../includes/POD/ProductionExecutionPermitConsumer.php');$r=file_get_contents(__DIR__.'/../../includes/POD/ProductionLifecycleClosureRepository.php');self::assertStringContainsString('pod_authorization_bindings',$c);self::assertStringContainsString('production_permit_package_binding_conflict',$c);self::assertStringContainsString('production_closure_provenance_conflict',$r);self::assertStringContainsString("'external_execution_state'=>\$executionState",$r);}
}
