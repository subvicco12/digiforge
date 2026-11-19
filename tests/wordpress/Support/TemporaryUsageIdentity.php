<?php
declare(strict_types=1);
/** Native suites are serial; temporary usage IDs must not reuse retained immutable evidence IDs. */
final class TemporaryUsageIdentity {
 public static function next(WP_UnitTestCase $case):int {
  global $wpdb;$wpdb->last_error='';$high=$wpdb->get_var('SELECT GREATEST((SELECT COALESCE(MAX(id),0) FROM '.DigiForge\Database\Tables::shop_ai_usage().'),(SELECT COALESCE(MAX(CAST(SUBSTRING(option_name,CHAR_LENGTH(\'digiforge_ai_evidence_completion_\')+1) AS UNSIGNED)),0) FROM '.$wpdb->options." WHERE option_name REGEXP '^digiforge_ai_evidence_completion_[1-9][0-9]*$'),(SELECT COALESCE(MAX(CAST(SUBSTRING(option_name,CHAR_LENGTH('digiforge_ai_money_usage_')+1) AS UNSIGNED)),0) FROM ".$wpdb->options." WHERE option_name REGEXP '^digiforge_ai_money_usage_[1-9][0-9]*$'))");
  $case->assertSame('',$wpdb->last_error);$case->assertNotNull($high);$case->assertTrue(ctype_digit((string)$high));$case->assertLessThan(PHP_INT_MAX-1000,(int)$high);return (int)$high+1000;
 }
}
