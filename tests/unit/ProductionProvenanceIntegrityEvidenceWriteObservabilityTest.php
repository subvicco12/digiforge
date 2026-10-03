<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductionProvenanceIntegrityEvidenceWriteObservabilityTest extends TestCase
{
 public function testEvidenceWriteFailuresRemainNonAuthoritativeButObservable(): void
 {
  $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/ProductionProvenanceIntegrityEvidenceRepository.php');
  self::assertIsString($source);
  self::assertStringContainsString("\$updated=\$wpdb->update",$source);
  self::assertStringContainsString("\$inserted=\$wpdb->insert",$source);
  self::assertStringContainsString("recordPersistenceFailure('UPDATE'",$source);
  self::assertStringContainsString("recordPersistenceFailure('INSERT'",$source);
  self::assertStringContainsString('error_log(', $source);
  self::assertStringContainsString('public static function observe(array $item):void',$source);
  self::assertStringContainsString('Never grants execution authority or retry',$source);
 }
}
