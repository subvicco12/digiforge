<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class DigitalFactoryDbEvidenceContractTest extends TestCase {
 public function testAuthorityReadsAndTransitionFailClosedOnDbErrors():void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/DigitalFactory/Repository.php');
  self::assertStringContainsString("array|\\WP_Error|null",$s);
  self::assertStringContainsString("'evidence_unavailable'",$s);
  self::assertStringContainsString("'transition_failed'",$s);
  self::assertStringContainsString("\$wpdb->last_error=''",$s);
  self::assertStringContainsString("is_wp_error(\$existing)",$s);
  self::assertStringContainsString("is_wp_error(\$entity)",$s);
 }
 public function testRestShowPropagatesEvidenceFailure():void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/DigitalFactoryController.php');
  self::assertStringContainsString('if(is_wp_error($item)){return $item;}',$s);
 }
}