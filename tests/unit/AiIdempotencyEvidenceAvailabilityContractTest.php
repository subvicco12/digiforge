<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class AiIdempotencyEvidenceAvailabilityContractTest extends TestCase{
 public function testCreateFailsClosedWhenReplayEvidenceCannotBeRead():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/AI/Repository.php');
  self::assertStringContainsString("\$wpdb->last_error = ''",$s);
  self::assertStringContainsString('AI idempotency evidence could not be read.',$s);
  self::assertStringContainsString("return \$this->error('evidence_unavailable'",$s);
  self::assertStringContainsString("['idempotent_replay' => true]",$s);
  self::assertLessThan(strpos($s,"\$data['idempotency_key'] = \$key;"),strpos($s,'AI idempotency evidence could not be read.'));
 }
}
