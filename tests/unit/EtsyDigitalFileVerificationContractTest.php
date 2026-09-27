<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class EtsyDigitalFileVerificationContractTest extends TestCase {
 public function testVerificationIsExactAndNonMutating():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyDigitalFileVerification.php');
  foreach(['listing_file_id','filename','rank','size_bytes','exact_match_count',"'mutation_performed'=>false","'publish_permitted'=>false"] as $n)self::assertStringContainsString($n,$s);
  foreach(['wp_remote_','curl_','CredentialVault','UPDATE ','DELETE ','INSERT '] as $n)self::assertStringNotContainsString($n,$s);
 }
 public function testPlanIsGetOnlyAndCannotRetryOrPublish():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyDigitalFileVerificationPlan.php');
  foreach(["'method'=>'GET'","'/files'","'mutation_permitted'=>false","'publish_permitted'=>false","'automatic_retry_permitted'=>false","'network_request_permitted'=>false"] as $n)self::assertStringContainsString($n,$s);
 }
}
