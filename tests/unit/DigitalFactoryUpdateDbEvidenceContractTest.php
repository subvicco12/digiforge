<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class DigitalFactoryUpdateDbEvidenceContractTest extends TestCase{
 public function testUpdateClearsAndChecksDatabaseErrorEvidence():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/DigitalFactory/Repository.php');
  self::assertStringContainsString("\$wpdb->last_error = ''; \$updated = \$wpdb->update",$s);
  self::assertStringContainsString("\$updated === false || !empty(\$wpdb->last_error)",$s);
  self::assertStringContainsString("'update_failed', 'Unable to update digital entity.', 503",$s);
 }
}