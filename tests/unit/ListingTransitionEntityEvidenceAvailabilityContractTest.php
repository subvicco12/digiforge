<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ListingTransitionEntityEvidenceAvailabilityContractTest extends TestCase{
 public function testTransitionSeparatesUnavailableEntityEvidenceAndPostWriteReadback():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/Repository.php');
  self::assertStringContainsString('private function findEvidence(string $table,int $id,string $code,string $message): array|WP_Error|null',$s);
  self::assertStringContainsString("\$wpdb->last_error='';",$s);
  self::assertStringContainsString("'transition_entity_evidence_unavailable'",$s);
  self::assertStringContainsString("'transition_readback_evidence_unavailable'",$s);
  self::assertStringContainsString('if($row instanceof WP_Error)return $row;',$s);
  self::assertStringContainsString('if($updated instanceof WP_Error)return $updated;',$s);
  self::assertStringNotContainsString("return \$this->find(\$table,\$id)?:[];",$s);
  self::assertLessThan(strpos($s,"'not_found','Listing record not found.'"),strpos($s,"'transition_entity_evidence_unavailable'"));
  self::assertLessThan(strpos($s,'return $updated;'),strpos($s,"'transition_readback_evidence_unavailable'"));
 }
}
