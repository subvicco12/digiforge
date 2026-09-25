<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
final class DigitalQaAdminUiTest extends TestCase {
 public function testQaSnapshotIsScopedToDownloadChecksAndReadOnly():void{
  $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/DigitalFactory/Admin.php');
  self::assertStringContainsString("\$type==='digital_download_check'",$source);
  self::assertStringContainsString('QaAdminSummary::summarize($items)',$source);
  self::assertStringContainsString('Current page QA snapshot:',$source);
  self::assertStringContainsString('readiness inferred: NO',$source);
  self::assertStringContainsString('external actions performed: NO',$source);
 }
}
