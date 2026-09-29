<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProvenanceIntegrityEvidenceAvailabilityContractTest extends TestCase { public function testUnavailableEvidenceDoesNotBecomeInsertOrEmptyHistory():void { $c=file_get_contents(__DIR__.'/../../includes/POD/ProductionProvenanceIntegrityEvidenceRepository.php'); self::assertStringContainsString('if(!empty($wpdb->last_error))return;',$c); self::assertStringContainsString("'query_state'=>'UNAVAILABLE'",$c); self::assertStringContainsString("'retry_permitted'=>false",$c); self::assertStringContainsString("'external_execution_authorized'=>false",$c); } }
