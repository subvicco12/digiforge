<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
final class DigitalOperationalAdminUiTest extends TestCase {
 public function testOperationalSnapshotIsReadOnlyAndScoped():void{
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/DigitalFactory/Admin.php');
  self::assertStringContainsString("\$type==='digital_package'?'generation_status'",$s);
  self::assertStringContainsString("['digital_file','digital_preview','digital_template']",$s);
  self::assertStringContainsString('OperationalAdminSummary::summarize($items,$operationalField)',$s);
  self::assertStringContainsString("'digital_preview'=>'Digital Previews'",$s);
  self::assertStringContainsString('persisted states only',$s);
  self::assertStringContainsString('readiness inferred: NO',$s);
  self::assertStringContainsString('external actions performed: NO',$s);
 }
}
