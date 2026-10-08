<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class LaunchCandidateReadbackContractTest extends TestCase {
 public function testSuccessfulCandidateSelectUsesCachedResultAndRejectsQueryFailure():void {
  $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Launch/ExecutionEngine.php');
  $start=strpos($s,'private function candidate(int $id)');
  self::assertNotFalse($start);
  $end=strpos($s,'private function researchPrompt(', $start);
  self::assertNotFalse($end);
  $method=substr($s,$start,$end-$start);
  self::assertStringContainsString('$queryResult === false',$method);
  self::assertStringContainsString('isset($wpdb->last_result[0])',$method);
  self::assertStringNotContainsString('get_row(null',$method);
  self::assertStringContainsString('return is_array($row) ? $row : null;',$method);
 }
}
