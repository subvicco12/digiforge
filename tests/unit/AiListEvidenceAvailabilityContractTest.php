<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class AiListEvidenceAvailabilityContractTest extends TestCase{
 public function testRowsCountAndCallersPreserveUnavailableEvidence():void{
  $repo=(string)file_get_contents(dirname(__DIR__,2).'/includes/AI/Repository.php');
  self::assertStringContainsString('public function list(string $entity, int $page = 1, int $perPage = self::DEFAULT_PAGE_SIZE): array|\\WP_Error',$repo);
  self::assertStringContainsString('AI list evidence could not be read.',$repo);
  self::assertStringContainsString('AI list count evidence could not be read.',$repo);
  self::assertStringContainsString('$total_raw === null',$repo);
  self::assertStringContainsString("array_map([\$this, 'normalize'], \$rows)",$repo);
  $rest=(string)file_get_contents(dirname(__DIR__,2).'/includes/REST/AiController.php');
  self::assertStringContainsString('public function list(\\WP_REST_Request $r):\\WP_REST_Response|\\WP_Error',$rest);
  self::assertStringContainsString('if(is_wp_error($result))return $result;',$rest);
  $admin=(string)file_get_contents(dirname(__DIR__,2).'/includes/AI/Admin.php');
  self::assertStringContainsString('AI governance evidence is unavailable. No empty or zero-count state is asserted.',$admin);
  self::assertStringContainsString('is_wp_error($runs)||is_wp_error($usage)||is_wp_error($reviews)',$admin);
 }
}
