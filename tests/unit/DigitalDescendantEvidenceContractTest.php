<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class DigitalDescendantEvidenceContractTest extends TestCase {
 public function testDescendantAuthorityReadsResetAndPropagateEvidenceFailures():void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/DigitalFactory/Repository.php');
  $a=strpos($s,'private function validate_descendants');$b=strpos($s,'private function key',$a);self::assertNotFalse($a);self::assertNotFalse($b);$m=substr($s,$a,$b-$a);
  self::assertStringContainsString("\$wpdb->last_error='';\n            \$raw=\$wpdb->get_var(\$sql);",$m);
  self::assertStringContainsString("if(is_wp_error(\$owner)){return \$owner;}",$m);
 }
}
