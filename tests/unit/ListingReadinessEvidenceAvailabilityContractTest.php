<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ListingReadinessEvidenceAvailabilityContractTest extends TestCase {
 public function testReadinessCannotCastFailedEvidenceReadsToEmptyOrZero():void {
  $c=file_get_contents(__DIR__.'/../../includes/Listings/Repository.php');
  $start=strpos($c,'function readiness(int $listingId)');
  $end=strpos($c,'public function createDraftPackage',$start);
  $method=substr($c,$start,$end-$start);
  self::assertStringNotContainsString('(int)$wpdb->get_var',$method);
  self::assertStringNotContainsString('ARRAY_A)?:[]',$method);
  self::assertStringContainsString('readiness_evidence_unavailable',$method);
  self::assertStringContainsString('Listing readiness evidence is unavailable; release readiness is blocked.',$method);
  self::assertStringContainsString('!empty($wpdb->last_error)||!is_numeric($raw)',$method);
 }
 public function testDraftPackageStillRequiresSuccessfulReadyProjection():void {
  $c=file_get_contents(__DIR__.'/../../includes/Listings/Repository.php');
  self::assertStringContainsString('$readiness=$this->readiness($listingId)',$c);
  self::assertStringContainsString('if(is_wp_error($readiness))return $readiness;if(empty($readiness[\'ready\']))',$c);
 }
 public function testReadinessReadsClearStaleDatabaseErrorsBeforeIndependentQueries():void{$s=(string)file_get_contents(__DIR__.'/../../includes/Listings/Repository.php');self::assertStringContainsString("\$count=function(string \$sql)use(\$wpdb):int|WP_Error{\$wpdb->last_error='';\$raw=\$wpdb->get_var(\$sql);",$s);self::assertStringContainsString("\$wpdb->last_error='';\$mediaRows=\$wpdb->get_results",$s);self::assertStringContainsString("\$wpdb->last_error='';\$podBindings=\$wpdb->get_results",$s);}
}
