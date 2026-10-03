<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class ProductionReadEvidenceContractTest extends TestCase {
 public function testAuthorityReadsPropagateDbFailure():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Production/Repository.php');
  self::assertStringContainsString('array|WP_Error|null',$s);
  self::assertStringContainsString("'evidence_unavailable'",$s);
  self::assertStringContainsString("\$wpdb->last_error=''",$s);
  self::assertGreaterThanOrEqual(6,substr_count($s,'is_wp_error('));
  self::assertStringContainsString('if(is_wp_error($created))return $created;',$s);
  self::assertStringContainsString('if(is_wp_error($confirmed))return $confirmed;',$s);
 }
}