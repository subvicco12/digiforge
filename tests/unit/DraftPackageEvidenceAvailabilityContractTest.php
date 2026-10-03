<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class DraftPackageEvidenceAvailabilityContractTest extends TestCase{
 public function testDraftPackagePropagatesUnavailableReadinessAndSeoEvidence():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/Repository.php');
  self::assertStringContainsString('if(is_wp_error($readiness))return $readiness;if(empty($readiness[\'ready\']))',$s);
  self::assertStringContainsString('private function firstByEvidence(string $table,string $field,int $id,string $code,string $message): array|WP_Error|null',$s);
  self::assertStringContainsString("'draft_package_evidence_unavailable'",$s);
  self::assertStringContainsString('if($seo instanceof WP_Error)return $seo;',$s);
  self::assertStringContainsString("'not_ready','Listing is not release-ready.'",$s);
  self::assertStringNotContainsString("if(is_wp_error(\$readiness)||empty(\$readiness['ready']))return \$this->error('not_ready'",$s);
 }
}
