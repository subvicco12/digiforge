<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
final class DigitalQaAdminVisibilityTest extends TestCase
{
 public function testExistingQaSnapshotSurfacesAttentionWithoutReadinessInference():void{
  $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/DigitalFactory/Admin.php');
  self::assertStringContainsString('attention %2$d; invalid/unknown %3$d',$source);
  self::assertStringContainsString("['attention_total']??0",$source);
  self::assertStringContainsString('readiness inferred: NO; external actions performed: NO',$source);
 }
 public function testQueryFailureStillSuppressesQaSummary():void{
  $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/DigitalFactory/Admin.php');
  self::assertStringContainsString("$qaSummary=$queryOk && $type==='digital_download_check'",$source);
  self::assertStringContainsString('Records unavailable because the snapshot query failed.',$source);
 }
}
