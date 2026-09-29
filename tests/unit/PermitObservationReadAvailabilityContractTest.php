<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class PermitObservationReadAvailabilityContractTest extends TestCase { public function testReadFailuresAreNotProjectedAsEmptyOrZero():void { $c=file_get_contents(__DIR__.'/../../includes/POD/ProductionPermitPersistenceObservationRepository.php'); self::assertStringContainsString("'query_state'=>'UNAVAILABLE'",$c); self::assertStringContainsString("'count'=>null",$c); self::assertStringContainsString('if(!empty($wpdb->last_error))return;',$c); self::assertStringContainsString("'retry_permitted'=>false",$c); self::assertStringContainsString("'external_execution_authorized'=>false",$c); self::assertStringContainsString("'query_state'=>'AVAILABLE'",$c); } }
